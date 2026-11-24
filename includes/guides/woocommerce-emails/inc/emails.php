<?php
/**
 * Branded email design. Every WooCommerce email, and WordPress's own account emails
 * (inc/account-emails.php), use one wrapper: woocommerce/emails/email-header.php (logo, accent rule,
 * heading, opens the card), email-footer.php (closes the card, site footer), email-styles.php (CSS that
 * WooCommerce inlines) and email-button.php. WooCommerce's stored email settings (colours, footer text,
 * header image, font) are ignored: the design lives here, in code, with the theme's version history.
 *
 * Rename mytheme_ to the theme's prefix.
 */

defined( 'ABSPATH' ) || exit;

/** Brand colours for email: the same values as the design tokens in assets/css/main.css. */
function mytheme_email_color( string $name ): string {
	$colors = array(
		'paper'  => '#f6f4ef', // page background around the card
		'white'  => '#ffffff', // the card
		'ink'    => '#1a1a1a', // headings, names
		'text'   => '#333333',
		'muted'  => '#6b6b6b',
		'line'   => '#e4e0d8',
		'accent' => '#c8962e', // the rule under the logo, footer dots, quote borders
		'action' => '#2b59c3', // buttons and links
	);
	return $colors[ $name ];
}

/** Font stacks: no web fonts in email. Pick the closest system fonts to the site's. */
function mytheme_email_font( string $kind = 'sans' ): string {
	return 'serif' === $kind
		? 'Georgia, "Times New Roman", Times, serif'
		: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
}

/** The address customers can write to, shown in every email footer. Set it to the store's contact address. */
function mytheme_contact_email(): string {
	return (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
}

/** The site logo (custom logo) as [url, width, height] at email size, or null if unset. Use a PNG or JPG: many email apps don't show SVG. */
function mytheme_email_logo(): ?array {
	$id  = (int) get_theme_mod( 'custom_logo' );
	$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
	if ( ! $src ) {
		return null;
	}
	$width  = min( 200, (int) $src[1] );
	$height = $src[1] ? (int) round( $src[2] * $width / $src[1] ) : 0;
	return array( $src[0], $width, $height );
}

/**
 * A call-to-action button that works in Outlook (the table cell carries the colour).
 * Use in email body templates: echo mytheme_email_button( $url, 'View your order' );
 */
function mytheme_email_button( string $url, string $label ): string {
	$action = mytheme_email_color( 'action' );
	$font   = esc_attr( mytheme_email_font() );
	return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" class="email-button" style="margin:24px 0;border-collapse:separate;">'
		. '<tr><td align="center" bgcolor="' . $action . '" style="background-color:' . $action . ';border-radius:3px;mso-padding-alt:14px 28px;">'
		. '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;padding:14px 28px;font-family:' . $font . ';font-size:16px;font-weight:bold;line-height:20px;color:#ffffff;text-decoration:none;border-radius:3px;">'
		. esc_html( $label )
		. '</a></td></tr></table>';
}

/** Footer for plain-text emails, replacing the stored "Built with WooCommerce" footer text. */
add_filter(
	'woocommerce_email_footer_text',
	static function (): string {
		return implode(
			"\n",
			array(
				get_bloginfo( 'name', 'display' ),
				home_url( '/' ),
				mytheme_contact_email(),
			)
		);
	},
	20
);
