<?php
/**
 * Empty cart: a centred card with a way back to the shop. WooCommerce's default message is removed in
 * inc/woocommerce.php, but the action still runs, so notices such as "… removed. Undo?" show.
 *
 * Keep the wc-empty-cart-message class: WooCommerce's cart script looks for it to show this state in
 * place when an AJAX update empties the cart.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @version 7.0.1
 */

defined('ABSPATH') || exit;

do_action('woocommerce_cart_is_empty');
?>
<div class="cart-empty-state wc-empty-cart-message">
    <p class="cart-empty-state__title"><?php esc_html_e('Your cart is currently empty.', 'woocommerce'); ?></p>
    <?php if (wc_get_page_id('shop') > 0) : ?>
        <a class="button" href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>"><?php echo esc_html(apply_filters('woocommerce_return_to_shop_text', __('Return to shop', 'woocommerce'))); ?></a>
    <?php endif; ?>
</div>
