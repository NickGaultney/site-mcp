<?php
/**
 * Call-to-action button in WooCommerce email bodies (e.g. verify email), in the brand's
 * table-based button. Replaces WooCommerce's emails/email-button.php; see mytheme_email_button().
 *
 * @package mytheme
 * @version 11.0.0
 *
 * @var string $url   Button link.
 * @var string $label Button text.
 */

defined( 'ABSPATH' ) || exit;

echo mytheme_email_button( $url, $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in mytheme_email_button().
