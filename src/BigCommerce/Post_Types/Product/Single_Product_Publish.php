<?php


namespace BigCommerce\Post_Types\Product;

use BigCommerce\Api\v3\Api\CatalogApi;
use BigCommerce\Api\v3\Api\ChannelsApi;
use BigCommerce\Api\v3\ApiException;
use BigCommerce\Api\v3\Model\UpdateListingRequest;
use BigCommerce\Exceptions\Product_Not_Found_Exception;
use BigCommerce\Taxonomies\Channel\Channel;
use BigCommerce\Taxonomies\Channel\Connections;
use BigCommerce\Webhooks\Webhook_Cron_Tasks;

/**
 * Class Single_Product_Publish
 *
 * Adds a link to the post actions row to publish a single product
 */
class Single_Product_Publish {

	const ACTION = 'publish-product';

    /** @var ChannelsApi */
    private $channels;

    public function __construct( ChannelsApi $channels ) {
        $this->channels = $channels;
    }

	/**
	 * @param array    $actions
	 * @param \WP_Post $post
	 *
	 * @return array
	 * @filter post_row_actions
	 */
	public function add_row_action( $actions, $post ) {
		if ( get_post_type( $post ) !== Product::NAME ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = sprintf( '<a href="%s" class="%s" title="%s">%s</a>', esc_url( $this->get_publish_url( $post ) ), sanitize_html_class( self::ACTION ), esc_attr( __( 'Force publish the product in the BigCommerce API channel listing', 'bigcommerce' ) ), __( 'Force Publish', 'bigcommerce' ) );

		return $actions;
	}

	private function get_publish_url(\WP_Post $post ) {
		$url = add_query_arg( [
			'action'      => self::ACTION,
			'post_id'     => $post->ID,
			'redirect_to' => urlencode( add_query_arg( [ 'post_type' => Product::NAME ], admin_url( 'edit.php' ) ) ),
		], admin_url( 'admin-post.php' ) );

		$url = wp_nonce_url( $url, self::ACTION . $post->ID );

		return $url;
	}

	/**
	 * @return void
	 * @action admin_post_ . self::ACTION
	 */
	public function handle_request() {
		$post_id = filter_input( INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT );
		$nonce   = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_STRING );
		if ( empty( $post_id ) || ! wp_verify_nonce( $nonce, self::ACTION . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html( __( 'Invalid request', 'bigcommerce' ) ), esc_html( __( 'Invalid request', 'bigcommerce' ) ), 401 );
			exit;
		}

		$error = $this->publish_product( $post_id );
		if ( $error->has_errors() ) {
			add_settings_error( self::ACTION, $error->get_error_code(), $error->get_error_message() );
		} else {
			add_settings_error( self::ACTION, 'success', sprintf( __( 'Published product: %s', 'bigcommerce' ), esc_html( get_the_title( $post_id ) ) ), 'updated' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		$redirect = filter_input( INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL ) ?: admin_url( 'edit.php?post_type=' . Product::NAME );
		$redirect = add_query_arg( [ 'settings-updated' => 1 ], $redirect );
		wp_safe_redirect( esc_url_raw( $redirect ), 303 );
		exit();
	}

	/**
	 * @param int $post_id
	 *
	 * @return \WP_Error
	 */
	private function publish_product($post_id ) {
		$error = new \WP_Error();

		/**
		 * Error triggered when updating a product fails
		 *
		 * @param string $message
		 */
		$error_handler = function ( $message ) use ( $error ) {
			$error->add( 'import_error', sprintf(
				__( 'Error updating product. Message: %s', 'bigcommerce' ),
				$message
			) );
		};

		add_action( 'bigcommerce/import/error', $error_handler, 0, 1 );

		$product = new Product( $post_id );
		update_post_meta( $post_id, Product::REQUIRES_REFRESH_META_KEY, 1 );

		/*
		 * If the caching client is in use, clear the
		 * generation key to get a fresh response.
		 */
		wp_cache_delete( 'generation_key', 'bigcommerce_api' );

        $connections = new Connections();
        $channels    = $connections->active();

        if ( empty( $channels ) ) {
            do_action( 'bigcommerce/import/error', __( 'No channels connected. Product publish canceled.', 'bigcommerce' ) );

            return $error;
        }

		try {

            foreach ($channels as $channel) {

                $channel_id = get_term_meta($channel->term_id, Channel::CHANNEL_ID, true);
                if (empty($channel_id)) {
                    return $error;
                }

                $listing_id = $this->get_listing_id($product->bc_id(), $channel);

                if (!$listing_id) {
                    /**
                     * Fires if product update import skipped.
                     *
                     * @param string $message Message.
                     * @param int $product_bc_id Product BC ID.
                     */
                    do_action('bigcommerce/import/update_product/skipped', sprintf(__('No listing found for product ID %d. Aborting.', 'bigcommerce'), $product->getId()));

                    return $error;
                }

                $listing = $this->channels->getChannelListing($channel_id, $listing_id)->getData();

                if ('disabled' === $listing['state']) {

                    $variants = array_map(function ($variant) {
                        return [
                            "product_id" => $variant['product_id'],
                            "variant_id" => $variant['variant_id'],
                            "state" => $variant['state']
                        ];
                    }, $listing['variants']);

                    // Update to active
                    $response = $this->channels->updateChannelListings(
                        $channel_id,
                        [
                            [
                                "listing_id" => $listing_id,
                                "product_id" => $product->bc_id(),
                                "state" => "active",
                                "variants" => $variants
                            ]
                        ]
                    );

                    $response_data = $response->getData();

                    if ( isset( $response_data[0] ) && 'active' == $response_data[0]['state'] )
                    {
                        wp_publish_post($post_id);
                    }
                } else {
                    wp_publish_post($post_id);
                }

            }
        } catch ( \BigCommerce\Api\v3\ApiException $ex ) {
            error_log(print_r($ex->getResponseBody(), true));
		} catch ( \Exception $e ) {
			$error_handler( $e->getMessage() );
		}

		remove_filter( 'bigcommerce/import/error', $error_handler, 0 );

		return $error;
	}

    /**
     * Find the listing ID associated with the product
     *
     * @param int      $product_id
     * @param \WP_Term $channel
     *
     * @return int
     */
    private function get_listing_id( $product_id, \WP_Term $channel ) {
        try {
            $product = Product::by_product_id( $product_id, $channel, ['post_status' => 'any'] );
        } catch ( Product_Not_Found_Exception $e ) {
            return 0;
        }

        $listing    = $product->get_listing_data();
        $listing_id = 0;
        if ( ! empty( $listing ) && isset( $listing->listing_id ) ) {
            $listing_id = (int) $listing->listing_id;
        }

        return $listing_id;
    }
}