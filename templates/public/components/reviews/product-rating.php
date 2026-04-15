<?php
/**
 * @var float   $stars        The number of stars out of 5
 * @var int     $percentage   The star rating converted to a percentage (e.g., 4.2 stars = 84%)
 * @var int     $review_count The number of reviews the product has received
 * @var string  $link         Destination for the link to reviews
 * @var Product $product
 * @version 1.0.0
 */

use BigCommerce\Post_Types\Product\Product;

?>
<div class="bc-single-product__ratings">
	<div
		class="bc-single-product__rating"
		role="img"
		aria-label="<?php echo esc_attr( sprintf( _n( 'Rated %s out of 5 stars, based on %d review', 'Rated %s out of 5 stars, based on %d reviews', $review_count, 'bigcommerce' ), number_format_i18n( $stars, 1 ), $review_count ) ); ?>"
	>
		<div class="bc-single-product__rating--mask" style="width: <?php echo (int) $percentage; ?>%">
			<div class="bc-single-product__rating--top" aria-hidden="true">
				<span class="bc-rating-star"></span>
				<span class="bc-rating-star"></span>
				<span class="bc-rating-star"></span>
				<span class="bc-rating-star"></span>
				<span class="bc-rating-star"></span>
			</div>
		</div>
		<div class="bc-single-product__rating--bottom" aria-hidden="true">
			<span class="bc-rating-star"></span>
			<span class="bc-rating-star"></span>
			<span class="bc-rating-star"></span>
			<span class="bc-rating-star"></span>
			<span class="bc-rating-star"></span>
		</div>
	</div>
	<div class="bc-single-product__rating-reviews">
		<!-- data-js="bc-single-product-reviews-anchor" is required -->
		<a
			<?php if ( $link ) { ?>
				href="<?php echo esc_url( $link ); ?>"
			<?php } ?>
			class="bc-link bc-single-product__reviews-anchor"
			data-js="bc-single-product-reviews-anchor"
		>
			<?php printf( esc_html( _n( '%d review', '%d reviews', $review_count, 'bigcommerce' ) ), $review_count ); ?>
		</a>
	</div>
</div>