<?php
/**
 * Email header: paper background, white card, centred logo, accent rule, Georgia heading.
 * Opens the card; email-footer.php closes it. Design: inc/emails.php.
 * Replaces WooCommerce's emails/email-header.php (10.7.0) and ignores its stored header image/colors,
 * in both the legacy and the "email improvements" modes.
 *
 * @package mytheme
 * @version 10.7.0
 *
 * @var string        $email_heading Heading for the email.
 * @var WC_Email|null $email         Email object (null for wrapped messages).
 */

defined( 'ABSPATH' ) || exit;

$store_name = $store_name ?? get_bloginfo( 'name', 'display' );
$logo       = mytheme_email_logo();
$paper      = mytheme_email_color( 'paper' );
$white      = mytheme_email_color( 'white' );
$line       = mytheme_email_color( 'line' );
$accent     = mytheme_email_color( 'accent' );
$ink        = mytheme_email_color( 'ink' );
$serif      = esc_attr( mytheme_email_font( 'serif' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<meta name="x-apple-disable-message-reformatting">
		<title><?php echo esc_html( $store_name ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="margin:0;padding:0;background-color:<?php echo $paper; ?>;">
		<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="outer_wrapper" bgcolor="<?php echo $paper; ?>" style="background-color:<?php echo $paper; ?>;">
			<tr>
				<td align="center" valign="top" id="outer_cell" style="padding:40px 16px;">
					<!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
					<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" style="max-width:600px;">
						<tr>
							<td id="template_container" bgcolor="<?php echo $white; ?>" style="background-color:<?php echo $white; ?>;border:1px solid <?php echo $line; ?>;border-radius:3px;">
								<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header">
									<tr>
										<td align="center" id="template_header_image" style="padding:36px 40px 0;text-align:center;">
											<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" style="display:inline-block;text-decoration:none;">
												<?php if ( $logo ) : ?>
													<img src="<?php echo esc_url( $logo[0] ); ?>" width="<?php echo (int) $logo[1]; ?>" height="<?php echo (int) $logo[2]; ?>" alt="<?php echo esc_attr( $store_name ); ?>" style="display:block;width:<?php echo (int) $logo[1]; ?>px;max-width:100%;height:auto;margin:0 auto;border:0;">
												<?php else : ?>
													<span style="font-family:<?php echo $serif; ?>;font-size:20px;color:<?php echo $ink; ?>;"><?php echo esc_html( $store_name ); ?></span>
												<?php endif; ?>
											</a>
										</td>
									</tr>
									<tr>
										<td align="center" style="padding:24px 40px 0;">
											<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="56" class="email-rule">
												<tr><td height="1" style="border-bottom:2px solid <?php echo $accent; ?>;font-size:0;line-height:0;">&nbsp;</td></tr>
											</table>
										</td>
									</tr>
									<?php if ( ! empty( $email_heading ) ) : ?>
										<tr>
											<td align="center" id="header_wrapper" style="padding:24px 40px 0;text-align:center;">
												<h1 style="margin:0;font-family:<?php echo $serif; ?>;font-size:30px;line-height:1.2;font-weight:normal;color:<?php echo $ink; ?>;text-align:center;"><?php echo esc_html( $email_heading ); ?></h1>
											</td>
										</tr>
									<?php endif; ?>
								</table>
								<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body">
									<tr>
										<td valign="top" id="body_content" style="padding:28px 40px 40px;">
											<div id="body_content_inner">
