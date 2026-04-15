<?php
/**
 * Cart Header
 *
 * @package BigCommerce
 *
 * @version 1.0.0
 */
?>

<div class="bc-cart-header" role="row">
	<div class="bc-cart-header__item" role="columnheader"><?php esc_html_e( 'Item', 'bigcommerce' ); ?></div>
	<div class="bc-cart-header__qty" role="columnheader"><?php esc_html_e( 'Qty', 'bigcommerce' ); ?></div>
	<div class="bc-cart-header__price" role="columnheader"><?php esc_html_e( 'Price', 'bigcommerce' ); ?></div>
</div>