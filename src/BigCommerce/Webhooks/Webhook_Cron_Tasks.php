<?php


namespace BigCommerce\Webhooks;

/**
 * Class Webhook_Cron_Tasks
 *
 * @package BigCommerce\Webhooks
 */
class Webhook_Cron_Tasks {

	const UPDATE_PRODUCT = 'bigcommerce/webhooks/cron/update_product';

	/**
	 * Schedule a product update cron task.
	 *
	 * @param int $product_id BigCommerce product ID.
	 */
	public function set_product_update_cron_task( int $product_id ): void {
		// Use positional args to avoid PHP named-parameter issues in cron callbacks.
		$args = [ $product_id ];
		if ( ! wp_next_scheduled( self::UPDATE_PRODUCT, $args ) ) {
			wp_schedule_single_event( time(), self::UPDATE_PRODUCT, $args );
		}
	}


}
