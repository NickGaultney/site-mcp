<?php
/**
 * Site-wide sign-in dialog, printed once in footer.php: get_template_part('template-parts/login-dialog').
 * Any element with data-login-open opens it (give it the real My Account URL as its href, so it still
 * works without JavaScript). The form posts to WooCommerce's own login handler with its nonce, so
 * WooCommerce checks the password, shows errors and redirects back here. No custom authentication.
 */

defined('ABSPATH') || exit;

if (is_user_logged_in() || !function_exists('wc_get_page_permalink')) {
    return;
}
$account = wc_get_page_permalink('myaccount');
$here    = wp_unslash($_SERVER['REQUEST_URI'] ?? '/'); // WooCommerce passes it through wp_validate_redirect().
?>
<dialog class="login-dialog" id="login-dialog" aria-labelledby="login-dialog-title">
    <button class="login-dialog__close" type="button" aria-label="<?php esc_attr_e('Close', 'woocommerce'); ?>" data-login-close>&times;</button>
    <h2 id="login-dialog-title"><?php esc_html_e('Log in', 'woocommerce'); ?></h2>
    <form class="login-dialog__form" method="post" action="<?php echo esc_url($account); ?>">
        <p>
            <label for="dlg-username"><?php esc_html_e('Username or email address', 'woocommerce'); ?></label>
            <input type="text" name="username" id="dlg-username" autocomplete="username" required>
        </p>
        <p>
            <label for="dlg-password"><?php esc_html_e('Password', 'woocommerce'); ?></label>
            <input type="password" name="password" id="dlg-password" autocomplete="current-password" required>
        </p>
        <p class="login-dialog__remember">
            <label><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e('Remember me', 'woocommerce'); ?></label>
        </p>
        <?php wp_nonce_field('woocommerce-login', 'woocommerce-login-nonce'); ?>
        <input type="hidden" name="redirect" value="<?php echo esc_url($here); ?>">
        <button type="submit" class="button button--block" name="login" value="<?php esc_attr_e('Log in', 'woocommerce'); ?>"><?php esc_html_e('Log in', 'woocommerce'); ?></button>
        <p class="login-dialog__links">
            <a href="<?php echo esc_url(wc_lostpassword_url()); ?>"><?php esc_html_e('Lost your password?', 'woocommerce'); ?></a>
            <?php if (get_option('woocommerce_enable_myaccount_registration') === 'yes') : ?>
                · <a href="<?php echo esc_url($account); ?>"><?php esc_html_e('Register', 'woocommerce'); ?></a>
            <?php endif; ?>
        </p>
    </form>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('login-dialog');
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-login-open]')) {
            e.preventDefault();
            dialog.showModal();
            dialog.querySelector('input').focus();
        } else if (e.target.closest('[data-login-close]') || e.target === dialog) {
            dialog.close();
        }
    });
})();
</script>
