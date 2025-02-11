<?php

namespace BigCommerce\Webhooks\Product;

use BigCommerce\Api\v3\Model\Product;
use BigCommerce\Import\Importers\Products\Product_Importer;
use BigCommerce\Import\Importers\Products\Product_Remover;
use BigCommerce\Logging\Error_Log;
use BigCommerce\Taxonomies\Channel\Channel;

class Channels_UnAssign extends Channels_Manager {

	public function handle_request( $product_id, $channel_id ) {
		$channel = $this->get_channel( $channel_id );

		if ( empty( $channel ) ) {
			do_action( 'bigcommerce/log', Error_Log::INFO, __( 'Requested channel does not exist', 'bigcommerce' ), [
				'channel_id' => $channel_id,
			], 'webhooks' );

			return;
		}

		$post_id = $this->match_post_id( $product_id, $channel );
		$post = array( 'ID' => $post_id, 'post_status' => 'draft' );
		wp_update_post($post);
	}

	private function match_post_id( $product_id, \WP_Term $channel ) {
		$args = [
			'meta_query'     => [
				[
					'key'   => 'bigcommerce_id',
					'value' => absint( $product_id ),
				],
			],
			'tax_query'      => [
				[
					'taxonomy' => $channel->taxonomy,
					'field'    => 'term_id',
					'terms'    => [ (int) $channel->term_id ],
					'operator' => 'IN',
				],
			],
			'post_type'      => \BigCommerce\Post_Types\Product\Product::NAME,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		];

		$posts = get_posts( $args );
		if ( empty( $posts ) ) {
			return 0;
		}

		return absint( reset( $posts ) );
	}

}
