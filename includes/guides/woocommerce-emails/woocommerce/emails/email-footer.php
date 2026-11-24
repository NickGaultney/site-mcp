<?php
/**
 * Email footer: closes the card from email-header.php, then the site footer (site name, My account,
 * website, contact email). Written here, not taken from WooCommerce's footer-text setting, so there is
 * no "Built with WooCommerce". Replaces WooCommerce's emails/email-footer.php (10.4.0).
 *
 * @package mytheme
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

$site    = get_bloginfo( 'name', 'display' );
$home    = home_url( '/' );
$contact = mytheme_contact_email();
$ink     = mytheme_email_color( 'ink' );
$muted   = mytheme_email_color( 'muted' );
$action  = mytheme_email_color( 'action' );
$sans    = esc_attr( mytheme_email_font() );
$serif   = esc_attr( mytheme_email_font( 'serif' ) );
$link    = 'color:' . $action . ';text-decoration:none;';
$dot     = '<span style="color:' . mytheme_email_color( 'accent' ) . ';">&nbsp;&middot;&nbsp;</span>';
?>
											</div>
										</td>
									</tr>
								</table>
							</td>
						</tr>
						<tr>
							<td align="center" id="email-footer-links" style="padding:28px 24px 0;text-align:center;font-family:<?php echo $sans; ?>;font-size:13px;line-height:1.7;color:<?php echo $muted; ?>;">
								<p style="margin:0 0 6px;font-family:<?php echo $serif; ?>;font-size:15px;color:<?php echo $ink; ?>;"><?php echo esc_html( $site ); ?></p>
								<p style="margin:0;">
									<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="<?php echo $link; ?>">My account</a><?php echo $dot; // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="<?php echo esc_url( $home ); ?>" style="<?php echo $link; ?>"><?php echo esc_html( preg_replace( '#^https?://|/$#', '', $home ) ); ?></a><?php echo $dot; // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="mailto:<?php echo esc_attr( $contact ); ?>" style="<?php echo $link; ?>"><?php echo esc_html( $contact ); ?></a>
								</p>
							</td>
						</tr>
					</table>
					<!--[if mso]></td></tr></table><![endif]-->
				</td>
			</tr>
		</table>
	</body>
</html>
