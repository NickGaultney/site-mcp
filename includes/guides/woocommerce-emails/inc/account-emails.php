<?php
/**
 * WordPress core account emails in the branded design of inc/emails.php.
 *
 * Core sends these as plain text, outside WooCommerce: the wp-login.php password reset (also wp-admin's
 * "Send password reset" and `wp user reset-password`; the site's own "Forgot your password?" links go to
 * WooCommerce's /my-account/lost-password/, whose email is WooCommerce's), the new-user "set your
 * password" email (wp-login.php registration, users added in wp-admin), and the password-changed and
 * email-changed notices. Each is turned into HTML, wrapped with WC()->mailer()->wrap_message() (the same
 * header and footer as every WooCommerce email), styled inline, and given a text/html Content-Type header
 * for that message only. Everything else (Fluent Forms, Two Factor codes) is untouched.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text email body → email HTML: escaped, blank lines → paragraphs, line breaks kept, URLs and
 * email addresses linked. A paragraph that is just $button_url becomes a button.
 */
function mytheme_account_email_html( string $text, string $button_url = '', string $button_label = '' ): string {
	$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	$html = '';
	foreach ( preg_split( "/\n\s*\n/", $text ) as $para ) {
		$para = trim( $para );
		if ( '' === $para ) {
			continue;
		}
		if ( $button_url && $para === $button_url ) {
			$html .= mytheme_email_button( $button_url, $button_label )
				. '<p style="font-size:13px;color:' . mytheme_email_color( 'muted' ) . ';word-break:break-all;">If the button doesn&#8217;t work, copy this link into your browser:<br>'
				. mytheme_account_email_link( $button_url ) . '</p>';
			continue;
		}
		$lines = array_map( 'mytheme_account_email_linkify', explode( "\n", $para ) );
		$html .= '<p>' . implode( '<br>', $lines ) . '</p>';
	}
	return $html;
}

/** One line of plain text, escaped, with its URLs and email addresses as links. */
function mytheme_account_email_linkify( string $line ): string {
	$parts = preg_split( '#(https?://[^\s<>"]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})#', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = '';
	foreach ( $parts as $i => $part ) {
		if ( 0 === $i % 2 ) {
			$out .= esc_html( $part );
			continue;
		}
		$trail = '';
		if ( preg_match( '/[.,;:!?)]+$/', $part, $m ) ) { // Sentence punctuation after a URL isn't part of it.
			$trail = $m[0];
			$part  = substr( $part, 0, -strlen( $trail ) );
		}
		$out .= ( str_contains( $part, '://' ) ? mytheme_account_email_link( $part ) : '<a href="mailto:' . esc_attr( $part ) . '">' . esc_html( $part ) . '</a>' ) . esc_html( $trail );
	}
	return $out;
}

function mytheme_account_email_link( string $url ): string {
	return '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a>';
}

/**
 * A core email array (to, subject, message, headers) with its message wrapped in the branded design.
 * $button: [url, label] to show the link as a button, if it appears alone in a paragraph.
 */
function mytheme_account_email_wrap( array $email, string $heading, array $button = array() ): array {
	if ( ! function_exists( 'WC' ) || ! isset( $email['message'] ) || preg_match( '/<html|<body/i', $email['message'] ) ) {
		return $email;
	}
	$body    = mytheme_account_email_html( $email['message'], $button[0] ?? '', $button[1] ?? '' );
	$wrapped = WC()->mailer()->wrap_message( $heading, $body );

	// Inline the email stylesheet the way WooCommerce does for its own emails. A clone of one of the
	// mailer's emails: `new WC_Email()` would add a fresh set of hooks per message.
	static $styler = null;
	if ( ! $styler ) {
		$emails = WC()->mailer()->get_emails();
		$styler = clone reset( $emails );
		$styler->email_type = 'html';
	}
	$email['message'] = $styler->style_inline( $wrapped );

	$headers = $email['headers'] ?? array();
	$headers = is_array( $headers ) ? $headers : array_filter( preg_split( "/\r?\n/", (string) $headers ) );
	$headers = array_filter( $headers, static fn( $h ) => ! preg_match( '/^\s*content-type:/i', $h ) );
	$headers[]        = 'Content-Type: text/html; charset=UTF-8';
	$email['headers'] = array_values( $headers );
	return $email;
}

/** Fill core's ###PLACEHOLDERS### now (core fills them after the filter, unescaped, into what is now HTML). */
function mytheme_account_email_fill( string $message, array $user, array $extra = array() ): string {
	return strtr(
		$message,
		$extra + array(
			'###USERNAME###'    => $user['user_login'] ?? '',
			'###ADMIN_EMAIL###' => mytheme_contact_email(), // "contact the site administrator at …" names the contact address.
			'###EMAIL###'       => $user['user_email'] ?? '',
			'###SITENAME###'    => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			'###SITEURL###'     => home_url(),
		)
	);
}

/** The first wp-login.php?action=rp link in a message (reset and set-password emails). */
function mytheme_account_email_rp_url( string $message ): string {
	return preg_match( '#https?://\S+/wp-login\.php\?\S*action=rp\S*#', $message, $m ) ? $m[0] : '';
}

// Password reset from wp-login.php.
add_filter(
	'retrieve_password_notification_email',
	static function ( $email ) {
		$url = mytheme_account_email_rp_url( $email['message'] ?? '' );
		return mytheme_account_email_wrap( $email, 'Reset your password', $url ? array( $url, 'Reset your password' ) : array() );
	},
	99
);

// New user (registered at wp-login.php or added in wp-admin): the "set your password" email.
add_filter(
	'wp_new_user_notification_email',
	static function ( $email ) {
		$url = mytheme_account_email_rp_url( $email['message'] ?? '' );
		return mytheme_account_email_wrap( $email, 'Welcome', $url ? array( $url, 'Set your password' ) : array() );
	},
	99
);

// Password changed.
add_filter(
	'password_change_email',
	static function ( $email, $user ) {
		$email['message'] = mytheme_account_email_fill( (string) $email['message'], (array) $user );
		$email['subject'] = mytheme_account_email_fill( (string) $email['subject'], (array) $user );
		return mytheme_account_email_wrap( $email, 'Your password was changed' );
	},
	99,
	2
);

// Email address changed (sent to the old address).
add_filter(
	'email_change_email',
	static function ( $email, $user, $userdata ) {
		$extra            = array( '###NEW_EMAIL###' => $userdata['user_email'] ?? '' );
		$email['message'] = mytheme_account_email_fill( (string) $email['message'], (array) $user, $extra );
		$email['subject'] = mytheme_account_email_fill( (string) $email['subject'], (array) $user, $extra );
		return mytheme_account_email_wrap( $email, 'Your email address was changed' );
	},
	99,
	3
);
