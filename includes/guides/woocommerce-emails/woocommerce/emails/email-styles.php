<?php
/**
 * Email CSS. WooCommerce inlines it with Emogrifier; the media query stays in a <style> block.
 * Brand colors come from inc/emails.php, never from WooCommerce's stored email settings.
 * Covers the classes of both WooCommerce email modes (legacy and "email improvements").
 * Replaces WooCommerce's emails/email-styles.php (11.3.0).
 *
 * @package mytheme
 * @version 11.3.0
 */

defined( 'ABSPATH' ) || exit;

$paper  = mytheme_email_color( 'paper' );
$ink    = mytheme_email_color( 'ink' );
$text   = mytheme_email_color( 'text' );
$muted  = mytheme_email_color( 'muted' );
$line   = mytheme_email_color( 'line' );
$action = mytheme_email_color( 'action' );
$sans   = mytheme_email_font();
$serif  = mytheme_email_font( 'serif' );
$start  = is_rtl() ? 'right' : 'left';
$end    = is_rtl() ? 'left' : 'right';
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed values from inc/emails.php.
?>
body {
	margin: 0;
	padding: 0;
	background-color: <?php echo $paper; ?>;
	-webkit-text-size-adjust: none;
}

#body_content_inner {
	color: <?php echo $text; ?>;
	font-family: <?php echo $sans; ?>;
	font-size: 16px;
	line-height: 1.6;
	text-align: <?php echo $start; ?>;
}

#body_content p {
	margin: 0 0 16px;
}

h1 {
	color: <?php echo $ink; ?>;
	font-family: <?php echo $serif; ?>;
	font-weight: normal;
}

h2 {
	color: <?php echo $ink; ?>;
	display: block;
	font-family: <?php echo $serif; ?>;
	font-size: 20px;
	font-weight: normal;
	line-height: 1.3;
	margin: 24px 0 14px;
	text-align: <?php echo $start; ?>;
}

h3 {
	color: <?php echo $ink; ?>;
	display: block;
	font-family: <?php echo $serif; ?>;
	font-size: 17px;
	font-weight: normal;
	line-height: 1.3;
	margin: 20px 0 8px;
	text-align: <?php echo $start; ?>;
}

a,
.link {
	color: <?php echo $action; ?>;
	font-weight: normal;
	text-decoration: underline;
}

h2 a {
	color: <?php echo $ink; ?>;
	text-decoration: none;
}

img {
	border: none;
	display: inline-block;
	height: auto;
	max-width: 100%;
	outline: none;
	text-decoration: none;
	vertical-align: middle;
}

blockquote {
	margin: 0 0 16px;
	padding: 12px 16px;
	border-<?php echo $start; ?>: 3px solid <?php echo mytheme_email_color( 'accent' ); ?>;
	background-color: <?php echo $paper; ?>;
	color: <?php echo $ink; ?>;
}

#body_content blockquote p {
	margin: 0;
}

/* Order details table, addresses, item meta (both modes). */
.td {
	color: <?php echo $text; ?>;
	border: 0;
	border-bottom: 1px solid <?php echo $line; ?>;
	font-family: <?php echo $sans; ?>;
	font-size: 15px;
	vertical-align: middle;
	padding: 10px 8px;
}

table.td {
	border: 1px solid <?php echo $line; ?>;
	border-collapse: collapse;
}

thead th.td {
	color: <?php echo $ink; ?>;
	font-size: 13px;
	font-weight: bold;
	letter-spacing: .04em;
	text-transform: uppercase;
	background-color: <?php echo $paper; ?>;
}

tfoot th.td,
tfoot td.td,
.order-totals th.td,
.order-totals td.td {
	color: <?php echo $ink; ?>;
	font-weight: normal;
}

.order-totals-total th.td,
.order-totals-total td.td {
	font-weight: bold;
}

/* WooCommerce's rule between the items and totals tables carries its own gray inline style. */
hr {
	border: 0 !important;
	border-top: 1px solid <?php echo $line; ?> !important;
	margin: 20px 0 !important;
}

#email-footer-links a {
	white-space: nowrap;
}

.address-title {
	color: <?php echo $ink; ?>;
	font-family: <?php echo $serif; ?>;
	font-weight: normal;
}

.text,
.order-item-data {
	color: <?php echo $text; ?>;
	font-family: <?php echo $sans; ?>;
}

.address {
	color: <?php echo $text; ?>;
	font-style: normal;
	padding: 12px 14px;
	border: 1px solid <?php echo $line; ?>;
	overflow-wrap: anywhere;
	word-break: break-word;
}

.address-email {
	word-break: break-all;
}

.address-email + br {
	display: none;
}

#body_content td ul.wc-item-meta {
	font-size: 13px;
	margin: 6px 0 0;
	padding: 0;
	list-style: none;
}

#body_content td ul.wc-item-meta li {
	margin: 2px 0 0;
	padding: 0;
}

#body_content td ul.wc-item-meta li p {
	margin: 0;
}

.wc-item-meta-label {
	float: <?php echo $start; ?>;
	font-weight: normal;
	margin-<?php echo $end; ?>: .25em;
}

.additional-fields {
	padding: 12px 12px 0;
	color: <?php echo $text; ?>;
	border: 1px solid <?php echo $line; ?>;
	list-style: none outside;
}

/* "Email improvements" mode classes. */
.email-introduction {
	padding-bottom: 8px;
}

.hr {
	border-bottom: 1px solid <?php echo $line; ?>;
	margin: 16px 0;
}

.email-order-detail-heading span {
	color: <?php echo $muted; ?>;
	display: block;
	font-family: <?php echo $sans; ?>;
	font-size: 14px;
}

.email-order-item-meta {
	color: <?php echo $muted; ?>;
	font-size: 14px;
}

.email-order-details td,
.email-order-details th {
	padding: 8px;
}

.email-order-details .order-totals-total td,
.email-order-details .order-totals-total th,
.order-totals-total td,
.order-totals-total th {
	color: <?php echo $ink; ?>;
	font-weight: bold;
}

.email-additional-content,
.email-additional-content p {
	color: <?php echo $text; ?>;
	text-align: <?php echo $start; ?>;
}

.font-family {
	font-family: <?php echo $sans; ?>;
}

.text-align-left {
	text-align: <?php echo $start; ?>;
}

.text-align-right {
	text-align: <?php echo $end; ?>;
}

/* Phones: less padding, smaller heading. Gmail and Apple Mail apply this; others get the fluid 600px column. */
@media screen and (max-width: 600px) {
	#outer_cell {
		padding: 16px 10px !important;
	}

	#template_header_image {
		padding: 28px 20px 0 !important;
	}

	#header_wrapper {
		padding: 20px 20px 0 !important;
	}

	#header_wrapper h1 {
		font-size: 25px !important;
	}

	#body_content {
		padding: 22px 20px 28px !important;
	}

	#body_content_inner {
		font-size: 16px !important;
	}

	td.td {
		font-size: 14px !important;
	}
}
