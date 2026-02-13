<?php

namespace BigCommerce\Webhooks\Product;

use BigCommerce\Api\v3\Api\CatalogApi;
use BigCommerce\Api\v3\Api\ChannelsApi;
use BigCommerce\Logging\Error_Log;
use BigCommerce\Post_Types\Product\Product;
use BigCommerce\Taxonomies\Channel\Channel;

class Channels_Manager {

	/**
	 * @var \BigCommerce\Api\v3\Api\CatalogApi
	 */
	protected $catalog_api;
	/**
	 * @var \BigCommerce\Api\v3\Api\ChannelsApi
	 */
	protected $channels_api;

	private const PRODUCT_IMPORT_LOCK_TTL = 75;
	private const PRODUCT_IMPORT_LOCK_GROUP = 'bigcommerce_product_import_lock';
	private const PRODUCT_IMPORT_LOCK_OPTION_PREFIX = 'bigcommerce_product_import_lock_';

	public function __construct( CatalogApi $catalog_api, ChannelsApi $channels_api ) {
		$this->catalog_api  = $catalog_api;
		$this->channels_api = $channels_api;
	}

	protected function get_channel( $channel_id ) {
		$channels = get_terms( [
			'taxonomy'   => Channel::NAME,
			'meta_key'   => Channel::CHANNEL_ID,
			'meta_value' => $channel_id,
			'meta_query' => [
				[
					'key'     => Channel::STATUS,
					'value'   => [ Channel::STATUS_PRIMARY, Channel::STATUS_CONNECTED ],
					'compare' => 'IN',
				],
			],
		] );

		if ( empty( $channels ) || is_wp_error( $channels ) ) {
			do_action( 'bigcommerce/log', Error_Log::INFO, __( 'Could not find the channel', 'bigcommerce' ), [
				'channel_id' => $channel_id,
			], 'webhooks' );

			return false;
		}

		return reset( $channels );
	}

	protected function maybe_get_existing_product( $product_id ) {
		global $wpdb;
		$query   = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d", Product::BIGCOMMERCE_ID, $product_id );
		$post_id = $wpdb->get_var( $query );

		if ( empty( $post_id ) || is_wp_error( $post_id ) ) {
			return false;
		}

		return new Product( $post_id );
	}

	/**
	 * Attempt to acquire a per-product import lock.
	 *
	 * @param int    $product_id      BigCommerce product ID.
	 * @param int    $channel_id      BigCommerce channel ID.
	 * @param int    $channel_term_id WordPress channel term ID.
	 * @param string $scope           Webhook scope.
	 * @param string $action          Webhook action.
	 *
	 * @return bool True when the lock was acquired.
	 */
	protected function acquire_product_import_lock( int $product_id, int $channel_id, int $channel_term_id, string $scope, string $action ): bool {
		$lock_token = $this->create_product_import_lock_token();
		$acquired   = $this->acquire_product_import_lock_option( $product_id, $lock_token );

		if ( $acquired ) {
			wp_cache_set( $this->get_product_import_lock_key( $product_id ), $lock_token, self::PRODUCT_IMPORT_LOCK_GROUP, self::PRODUCT_IMPORT_LOCK_TTL );
		}

		$message = $acquired
			? __( 'Acquired product import lock', 'bigcommerce' )
			: __( 'Skipped product import because lock is active', 'bigcommerce' );
		$this->log_product_import_lock_event( $message, $product_id, $channel_id, $channel_term_id, $scope, $action );

		return $acquired;
	}

	/**
	 * Release a per-product import lock.
	 *
	 * @param int    $product_id      BigCommerce product ID.
	 * @param int    $channel_id      BigCommerce channel ID.
	 * @param int    $channel_term_id WordPress channel term ID.
	 * @param string $scope           Webhook scope.
	 * @param string $action          Webhook action.
	 *
	 * @return void
	 */
	protected function release_product_import_lock( int $product_id, int $channel_id, int $channel_term_id, string $scope, string $action ): void {
		$option_name = $this->get_product_import_lock_option_name( $product_id );
		$lock_key    = $this->get_product_import_lock_key( $product_id );
		$cached      = wp_cache_get( $lock_key, self::PRODUCT_IMPORT_LOCK_GROUP );
		$stored      = get_option( $option_name, '' );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return;
		}

		if ( is_string( $cached ) && hash_equals( $stored['token'], $cached ) ) {
			delete_option( $option_name );
			wp_cache_delete( $lock_key, self::PRODUCT_IMPORT_LOCK_GROUP );
			$this->log_product_import_lock_event( __( 'Released product import lock', 'bigcommerce' ), $product_id, $channel_id, $channel_term_id, $scope, $action );
		}
	}

	/**
	 * Acquire a lock using the options table as a fallback.
	 *
	 * @param int    $product_id BigCommerce product ID.
	 * @param string $token      Unique lock token.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_product_import_lock_option( int $product_id, string $token ): bool {
		global $wpdb;

		$option_name = $this->get_product_import_lock_option_name( $product_id );
		$now         = time();
		$payload     = [
			'token'   => $token,
			'created' => $now,
			'expires' => $now + self::PRODUCT_IMPORT_LOCK_TTL,
		];

		$serialized_payload = maybe_serialize( $payload );
		$inserted           = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				$serialized_payload
			)
		);

		if ( ! empty( $inserted ) ) {
			return true;
		}

		$existing_raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);
		$existing = maybe_unserialize( $existing_raw );

		if ( is_array( $existing ) && isset( $existing['expires'] ) && (int) $existing['expires'] < $now ) {
			delete_option( $option_name );

			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$option_name,
					$serialized_payload
				)
			);

			return ! empty( $inserted );
		}

		return false;
	}

	/**
	 * Build a unique lock token.
	 *
	 * @return string
	 */
	private function create_product_import_lock_token(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Build the cache key for the import lock.
	 *
	 * @param int $product_id BigCommerce product ID.
	 *
	 * @return string
	 */
	private function get_product_import_lock_key( int $product_id ): string {
		return 'product_' . $product_id;
	}

	/**
	 * Build the option name for the import lock fallback.
	 *
	 * @param int $product_id BigCommerce product ID.
	 *
	 * @return string
	 */
	private function get_product_import_lock_option_name( int $product_id ): string {
		return self::PRODUCT_IMPORT_LOCK_OPTION_PREFIX . $product_id;
	}

	/**
	 * Log a lock event with minimal context for debugging.
	 *
	 * @param string $message         Log message.
	 * @param int    $product_id      BigCommerce product ID.
	 * @param int    $channel_id      BigCommerce channel ID.
	 * @param int    $channel_term_id WordPress channel term ID.
	 * @param string $scope           Webhook scope.
	 * @param string $action          Webhook action.
	 *
	 * @return void
	 */
	private function log_product_import_lock_event( string $message, int $product_id, int $channel_id, int $channel_term_id, string $scope, string $action ): void {
		do_action( 'bigcommerce/log', Error_Log::INFO, $message, [
			'product_id'      => $product_id,
			'channel_id'      => $channel_id,
			'channel_term_id' => $channel_term_id,
			'scope'           => $scope,
			'action'          => $action,
		], 'webhooks' );
	}
}
