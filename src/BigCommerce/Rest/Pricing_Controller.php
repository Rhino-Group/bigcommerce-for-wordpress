<?php


namespace BigCommerce\Rest;


use BigCommerce\Accounts\Customer;
use BigCommerce\Api\v3\Api\PricingApi;
use BigCommerce\Api\v3\Model\ItemPricing;
use BigCommerce\Api\v3\Model\PricingRequest;
use BigCommerce\Api\v3\ObjectSerializer;
use BigCommerce\Currency\With_Currency;
use BigCommerce\Customizer\Sections\Product_Single;
use BigCommerce\Exceptions\Channel_Not_Found_Exception;
use BigCommerce\Settings\Sections\Currency;
use BigCommerce\Settings\Sections\Import;
use BigCommerce\Taxonomies\Channel\Channel;
use BigCommerce\Taxonomies\Channel\Connections;

class Pricing_Controller extends Rest_Controller {
	use With_Currency;

	private $pricing_api;

	public function __construct( $namespace_base, $version, $rest_base, PricingApi $pricing_api ) {
		parent::__construct( $namespace_base, $version, $rest_base );
		$this->pricing_api = $pricing_api;
	}

	/**
	 * Add data to the JS config to support pricing requests
	 *
	 * @param array $config
	 *
	 * @return array
	 * @filter bigcommerce/js_config
	 */
	public function js_config( $config ) {
		$config['pricing'] = [
			'api_url'            => $this->get_base_url(),
			'ajax_pricing_nonce' => $this->is_nonce_enabled() ? wp_create_nonce( 'wp_rest' ) : false,
		];

		return $config;
	}

	/**
	 * Nonce is enabled or disabled via Customizer
	 *
	 * @return bool
	 */
	public function is_nonce_enabled(): bool {

        // 2024-08-01: Allow changing nonce setting regardless of import mode. Needed for full-page caching.
		return get_option( Product_Single::ENABLE_PRICE_NONCE, 'yes' ) === 'yes';
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => $this->get_collection_params(),
			],
		] );
	}


	/**
	 * Retrieves a collection of products.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$customer = new Customer( get_current_user_id() );

		/**
		 * Filter the customer group ID passed to the BigCommerce API.
		 * Null to use the default guest group. 0 to use unmodified catalog pricing.
		 *
		 * @param int|null The customer group ID
		 */
		$customer_group = apply_filters( 'bigcommerce/pricing/customer_group_id', $customer->get_group_id() );
		$currency_code  = apply_filters( 'bigcommerce/currency/code', 'USD' );

		$args = [
			'items'             => $this->filter_empty_options( $request->get_param( 'items' ) ?: [] ),
			'channel_id'        => $this->get_channel_id(),
			'currency_code'     => $currency_code,
			'customer_group_id' => $customer_group,
		];
		/**
		 * Filters pricing request arguments.
		 *
		 * @param array            $args    Arguments.
		 * @param \WP_REST_Request $request Full details about the request.
		 */
		$args = apply_filters( 'bigcommerce/pricing/request_args', $args, $request );

		try {
			$pricing_request  = new PricingRequest( $args );
			$pricing_response = $this->pricing_api->getPrices( $pricing_request );

			// convert items from objects to JSON
			$items    = array_map( [ $this, 'format_prices' ], $pricing_response->getData() );
			$response = [
				'items' => $items,
			];

			$rest_response = rest_ensure_response( $response );

			// Add cache differentiation header for Imperva CDN caching.
			$incoming_cache_key = $request->get_header( 'X_BC_Pricing_Key' );
			if ( ! empty( $incoming_cache_key ) ) {
				$cache_key = sanitize_text_field( $incoming_cache_key );
			} else {
				$cache_key = $this->generate_pricing_cache_key( $args['items'] );
			}
			$rest_response->header( 'X-BC-Pricing-Key', $cache_key );

			return $rest_response;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'gateway_error', $e->getMessage(), [ 'exception' => $e ] );
		}
	}

	private function filter_empty_options( $items ) {
		$items = array_map( function ( $item ) {
			if ( empty( $item['options'] ) ) {
				return $item;
			}
			$item['options'] = array_filter( $item['options'], function ( $option ) {
				return (int) $option['value_id'] !== 0;
			} );
			if ( empty( $item['options'] ) ) {
				unset( $item['options'] );
			}

			return $item;
		}, $items );

		return $items;
	}

	/**
	 * Generate a deterministic cache key from pricing request items.
	 *
	 * Normalizes the items array (sorted by product_id, options sorted by option_id)
	 * and produces a djb2 hash as a base-36 string. This key is used as an
	 * X-BC-Pricing-Key header so Imperva can cache POST responses per unique
	 * product/option combination.
	 *
	 * @param array $items The pricing request items array.
	 *
	 * @return string The base-36 encoded djb2 hash of the normalized items.
	 */
	private function generate_pricing_cache_key( array $items ): string {
		// Normalize each item to only include cache-relevant fields.
		$normalized_items = array_map( function ( array $item ): array {
			$normalized_item = [
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
			];

			if ( ! empty( $item['variant_id'] ) ) {
				$normalized_item['variant_id'] = (int) $item['variant_id'];
			}

			if ( ! empty( $item['options'] ) && is_array( $item['options'] ) ) {
				$options = array_map( function ( array $option ): array {
					return [
						'option_id' => (int) ( $option['option_id'] ?? 0 ),
						'value_id'  => (int) ( $option['value_id'] ?? 0 ),
					];
				}, $item['options'] );

				// Sort options by option_id for deterministic ordering.
				usort( $options, function ( array $option_a, array $option_b ): int {
					return $option_a['option_id'] <=> $option_b['option_id'];
				} );

				$normalized_item['options'] = $options;
			}

			return $normalized_item;
		}, $items );

		// Sort items by product_id for deterministic ordering.
		usort( $normalized_items, function ( array $item_a, array $item_b ): int {
			return $item_a['product_id'] <=> $item_b['product_id'];
		} );

		$json_string = wp_json_encode( $normalized_items );

		// djb2 hash algorithm — matches the JavaScript implementation.
		$hash = 5381;
		$length = strlen( $json_string );
		for ( $index = 0; $index < $length; $index++ ) {
			$hash = ( ( $hash << 5 ) + $hash + ord( $json_string[ $index ] ) ) & 0xFFFFFFFF;
		}

		return base_convert( (string) $hash, 10, 36 );
	}

	private function get_channel_id() {
		try {
			$connections = new Connections();
			$current     = $connections->current();

			return (int) get_term_meta( $current->term_id, Channel::CHANNEL_ID, true );
		} catch ( Channel_Not_Found_Exception $e ) {
			return 0;
		}
	}

	private function format_prices( ItemPricing $item ) {
		$return_data = [
			'product_id' => $item->getProductId(),
			'variant_id' => $item->getVariantId(),
			'options'    => $this->object_to_array( $item->getOptions() ),
		];
		$retail      = $item->getRetailPrice();
		$calculated  = $item->getCalculatedPrice();
		$original    = $item->getPrice();
		$sale        = $item->getSalePrice();
		$price       = $item->getPriceRange();
		$minimum     = $price->getMinimum();
		$maximum     = $price->getMaximum();
		switch ( get_option( Currency::PRICE_DISPLAY, Currency::DISPLAY_TAX_EXCLUSIVE ) ) {
			case Currency::DISPLAY_TAX_INCLUSIVE:
				$min_value        = $minimum ? $minimum->getTaxInclusive() : 0;
				$max_value        = $maximum ? $maximum->getTaxInclusive() : 0;
				$retail_value     = $retail ? $retail->getTaxInclusive() : 0;
				$calculated_value = $calculated ? $calculated->getTaxInclusive() : 0;
				$original_value   = $original ? $original->getTaxInclusive() : 0;
				$sale_value       = $sale ? $sale->getTaxInclusive() : 0;
				break;
			case Currency::DISPLAY_TAX_EXCLUSIVE:
			default:
				$min_value        = $minimum ? $minimum->getTaxExclusive() : 0;
				$max_value        = $maximum ? $maximum->getTaxExclusive() : 0;
				$retail_value     = $retail ? $retail->getTaxExclusive() : 0;
				$calculated_value = $calculated ? $calculated->getTaxExclusive() : 0;
				$original_value   = $original ? $original->getTaxExclusive() : 0;
				$sale_value       = $sale ? $sale->getTaxExclusive() : 0;
				break;
		}

		$return_data['retail_price'] = [
			'raw'       => $retail_value,
			'formatted' => $this->format_currency( $retail_value ),
		];

		$return_data['display_type']     = 'simple';
		$return_data['calculated_price'] = [
			'raw'       => $calculated_value,
			'formatted' => $this->format_currency( $calculated_value ),
		];

		if ( $sale_value && $sale_value < $original_value && $calculated_value < $original_value ) {
			// If the sale value and calculated value is different and less the original value, it's on sale.
			// If it's more, we shouldn't display it as a sale.
			// Calculated value might be different than Sale value if the customer is in a group with special pricing.
			$return_data['display_type']   = 'sale';
			$return_data['original_price'] = [
				'raw'       => $original_value,
				'formatted' => $this->format_currency( $original_value ),
			];

            return apply_filters( 'bigcommerce/pricing/format_price', $return_data, $item );
		}

		if ( $min_value != $max_value ) {
			$return_data['display_type'] = 'price_range';
			$return_data['price_range']  = [
				'min' => [
					'raw'       => $min_value,
					'formatted' => $this->format_currency( $min_value ),
				],
				'max' => [
					'raw'       => $max_value,
					'formatted' => $this->format_currency( $max_value ),
				],
			];

            return apply_filters( 'bigcommerce/pricing/format_price', $return_data, $item );
		}

        return apply_filters( 'bigcommerce/pricing/format_price', $return_data, $item );
	}

	/**
	 * Convert an API response object into an associative array
	 *
	 * @param object|array $object A BigCommerce API response object, or an array thereof
	 *
	 * @return array
	 */
	private function object_to_array( $object ) {
		if ( is_array( $object ) ) {
			return array_map( [ $this, 'object_to_array' ], $object );
		}
		$json = wp_json_encode( ObjectSerializer::sanitizeForSerialization( $object ) );

		return json_decode( $json, true );
	}

	/**
	 * Checks if a given request has access to read pricing.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return true|\WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		// no access checks for now
		return true;
	}


	/**
	 * Validate the items parameter.
	 *
	 * GET requests send items as a JSON-encoded query string, so we
	 * decode it before running standard schema validation.
	 * WordPress runs validate BEFORE sanitize, so decoding must happen here.
	 *
	 * @param mixed            $value   Raw parameter value.
	 * @param \WP_REST_Request $request Full request object.
	 * @param string           $param   Parameter name.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function validate_items_param( $value, $request, string $param ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
			if ( ! is_array( $value ) ) {
				return new \WP_Error(
					'rest_invalid_type',
					__( 'The items parameter must be a valid JSON array.', 'bigcommerce' ),
					[ 'param' => $param ]
				);
			}
		}

		// Set the decoded value so schema validation sees an array.
		$request->set_param( $param, $value );

		return rest_validate_request_arg( $value, $request, $param );
	}

	/**
	 * Sanitize the items parameter.
	 *
	 * Decodes a JSON string (GET query param) into a PHP array.
	 *
	 * @param mixed $items Raw items parameter value.
	 *
	 * @return array Decoded items array.
	 */
	public function sanitize_items_param( $items ): array {
		if ( is_string( $items ) ) {
			$decoded = json_decode( $items, true );

			return is_array( $decoded ) ? $decoded : [];
		}

		return is_array( $items ) ? $items : [];
	}

	public function get_collection_params() {
		return [
			'context' => $this->get_context_param(),
			'items'   => [
				'description'       => __( 'The option values for the product to add to the cart', 'bigcommerce' ),
				'sanitize_callback' => [ $this, 'sanitize_items_param' ],
				'validate_callback' => [ $this, 'validate_items_param' ],
				'required'          => true,
				'type'              => 'array',
				'items'             => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [
							'type'     => 'integer',
							'required' => true,
						],
						'variant_id' => [
							'type' => 'integer',
						],
						'options'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'option_id' => [
										'type' => 'integer',
									],
									'value_id'  => [
										'type' => 'integer',
									],
								],
							],
						],
					],
				],
			],
		];
	}
}
