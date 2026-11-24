---
title: Branded WooCommerce and account emails
plugins: woocommerce
---

# Branded WooCommerce and account emails

Use this when the owner wants the store's emails to match the site. Every WooCommerce email, and WordPress's own account emails, share one design defined in the theme's code:
- a centred logo over an accent rule;
- the heading and body in a white card on a tinted page;
- a short footer with the site's name and links.

It works in both of WooCommerce's email modes (legacy and "email improvements") and in Outlook. Adopted from a production store with 24 branded emails.

WooCommerce's stored email settings (colours, header image, footer text) stop having any effect, so tell the owner the design is changed through Claude now, not in WooCommerce → Settings → Emails.

## The files

Each path is where the file goes in the theme. Read each with `guide` (topic `woocommerce-emails`, file `<path>`), rename `mytheme_` to the theme's prefix, and write it with theme_file_write.

| File | What it does |
| --- | --- |
| `inc/emails.php` | The brand palette, font stacks, contact address, logo, the Outlook-safe `mytheme_email_button()`, and the plain-text footer |
| `woocommerce/emails/email-header.php` | Opens the email: logo (or the site name), accent rule, heading, card |
| `woocommerce/emails/email-footer.php` | Closes the card; footer with the site name, My account, website and contact address |
| `woocommerce/emails/email-styles.php` | The CSS WooCommerce inlines: body text, headings, links, order tables, addresses, item meta, both email modes, phones |
| `woocommerce/emails/email-button.php` | WooCommerce's own buttons (such as verify email) in the brand's button |
| `inc/account-emails.php` | WordPress's plain-text account emails as branded HTML: password reset from wp-login, new user, password changed, email changed. It uses WooCommerce's `wrap_message()` and `style_inline()` |

## Steps

1. Write `inc/emails.php`. Set the palette to the site's tokens, the font stacks to the nearest system fonts (no web fonts in email), and `mytheme_contact_email()` to the store's contact address.
2. Write the four `woocommerce/emails/` files. Their `@version` lines match WooCommerce's templates, so WooCommerce → Status flags them when WooCommerce changes its own. When that happens, compare with the new version and update.
3. Write `inc/account-emails.php`.
4. Check the logo is a PNG or JPG (Customizer → Site Identity). Many email apps don't show SVG.
5. Send yourself one of each and check them in a real inbox (Gmail on a phone, plus Outlook if the owner uses it):
    - WooCommerce → Settings → Emails has a preview for each email;
    - place a test order;
    - use "Lost your password?" on the account page and on wp-login.php.

## Optional, same pattern

- **Wording per email.** Set subject, heading and an opening line in one place with `woocommerce_email_subject_{id}` and `woocommerce_email_heading_{id}`. Those run after WooCommerce reads its stored settings, so the code wins. Override a body template only to change what it says, and keep the order-details hooks in it.
- **Staff emails to one address, with a Bcc.** Use this when the owner wants staff emails out of the dashboard settings:

```php
function mytheme_admin_email_ids(): array { return array( 'new_order', 'cancelled_order', 'failed_order' ); }
function mytheme_admin_bcc( $headers ) {   // $headers is a string or an array, depending on the sender
	$bcc = 'Bcc: owner@example.com';      // the owner's address, if they want a copy
	if ( is_array( $headers ) ) { $headers[] = $bcc; return $headers; }
	$headers = (string) $headers;
	return $headers . ( '' === $headers || str_ends_with( $headers, "\n" ) ? '' : "\r\n" ) . $bcc . "\r\n";
}
foreach ( mytheme_admin_email_ids() as $id ) {
	add_filter( "woocommerce_email_recipient_{$id}", 'mytheme_contact_email', 99 );
}
add_filter( 'woocommerce_email_headers', static fn( $headers, $id = '' ) => in_array( $id, mytheme_admin_email_ids(), true ) ? mytheme_admin_bcc( $headers ) : $headers, 99, 2 );
// WordPress's admin notices that customers can set off:
$notice = static function ( $email ) { $email['to'] = mytheme_contact_email(); $email['headers'] = mytheme_admin_bcc( $email['headers'] ?? '' ); return $email; };
add_filter( 'wp_new_user_notification_email_admin', $notice, 99 );
add_filter( 'wp_password_change_notification_email', $notice, 99 );
add_filter( 'comment_moderation_recipients', static fn() => array( mytheme_contact_email() ), 99 );
add_filter( 'comment_moderation_headers', 'mytheme_admin_bcc', 99 );
```

- **A custom purchase email.** For stores that unlock content on purchase, a "your purchase is ready" email can replace processing/completed for those orders. Send it once per order, with a set-your-password link for accounts created at checkout.

## Gotchas

- **Only `mytheme_email_button()` survives Outlook.** It's a table cell carrying the colour; a styled link alone loses its background there.
- **Email clients ignore most CSS.** WooCommerce inlines `email-styles.php` (Emogrifier). Only the phone media query stays in a `<style>` block, and Gmail and Apple Mail honour it.
- **Core account emails.** Core fills its `###PLACEHOLDERS###` after the filters run, without escaping, which is unsafe once the message is HTML. So `inc/account-emails.php` fills them first. It leaves emails that are already HTML (from other plugins) alone.
- **Sending.** All of this goes through `wp_mail`, which needs an SMTP or email-service plugin (such as FluentSMTP) to reach inboxes reliably.
