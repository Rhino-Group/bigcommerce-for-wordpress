<?php

/**
 * Template for rendering messages
 *
 * @var string $content    The contents of the message. May contain markup.
 * @var string $type       The type of message, probably one of: notice, error, warning, success
 * @var string $key        The key/error code that accompanies the message
 * @var string $attributes Sanitized HTML attributes to add to the wrapper div
 * @version 1.0.1
 */

// Determine the appropriate ARIA role based on message type.
// Errors and warnings use role="alert" (assertive), notices/success use role="status" (polite).
$aria_role = in_array( $type, [ 'error', 'warning' ], true ) ? 'alert' : 'status';
$aria_live  = in_array( $type, [ 'error', 'warning' ], true ) ? 'assertive' : 'polite';
?>

<!-- class="bc-alert" is required -->
<div
	class="bc-alert bc-alert--<?php echo sanitize_html_class( $type ); ?>"
	data-message-key="<?php echo esc_attr( $key ); ?>"
	role="<?php echo esc_attr( $aria_role ); ?>"
	aria-live="<?php echo esc_attr( $aria_live ); ?>"
	aria-atomic="true"
	<?php echo $attributes; ?>
>
	<?php echo $content; ?>
</div>
