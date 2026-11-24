<?php
/**
 * Cart: line items on the left, a sticky summary card on the right (stacked on phones). Replaces
 * WooCommerce's cart table with a list, keeping every hook, filter and form field it has, so payment
 * buttons, coupon and product add-on plugins, AJAX quantity updates and the nonce keep working.
 *
 * Each line shows the parent product's name, with variation attributes and add-on data underneath
 * (wc_get_formatted_cart_item_data), rather than "Product – Red, Large".
 *
 * @see https://woocommerce.com/document/template-structure/
 * @version 11.3.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_cart');
?>
<div class="cart-page">
    <form class="woocommerce-cart-form cart-lines" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
        <?php do_action('woocommerce_before_cart_table'); ?>

        <ul class="cart-lines__list woocommerce-cart-form__contents">
            <?php do_action('woocommerce_before_cart_contents'); ?>
            <?php
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                $_product   = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
                $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
                if (!$_product instanceof WC_Product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) {
                    continue;
                }
                $parent    = $_product->get_parent_id() ? wc_get_product($_product->get_parent_id()) : null;
                $base_name = $parent ? $parent->get_name() : WC()->cart->get_item_product_name($cart_item, $_product);
                $name      = apply_filters('woocommerce_cart_item_name', $base_name, $cart_item, $cart_item_key);
                $permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
                $thumbnail = apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_thumbnail'), $cart_item, $cart_item_key);
                ?>
                <li class="cart-line woocommerce-cart-form__cart-item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>" data-product="<?php echo esc_attr($product_id); ?>">
                    <?php if ($permalink) : ?>
                        <a class="cart-line__thumb" href="<?php echo esc_url($permalink); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
                    <?php else : ?>
                        <span class="cart-line__thumb"><?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <?php endif; ?>

                    <div class="cart-line__info">
                        <?php
                        echo $permalink
                            ? wp_kses_post(apply_filters('woocommerce_cart_item_name', sprintf('<a class="cart-line__name" href="%s">%s</a>', esc_url($permalink), $base_name), $cart_item, $cart_item_key))
                            : '<span class="cart-line__name">' . wp_kses_post($name) . '</span>';

                        do_action('woocommerce_after_cart_item_name', $cart_item, $cart_item_key);

                        // Variation attributes and add-on data. Passing the parent's name lists every attribute.
                        echo wc_get_formatted_cart_item_data($cart_item, false, $base_name); // phpcs:ignore WordPress.Security.EscapeOutput

                        if ($_product->backorders_require_notification() && $_product->is_on_backorder($cart_item['quantity'])) {
                            echo wp_kses_post(apply_filters('woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__('Available on backorder', 'woocommerce') . '</p>', $product_id, $cart_item, $cart_item_key));
                        }

                        if (!$_product->is_sold_individually()) {
                            $quantity = woocommerce_quantity_input([
                                'input_name'   => "cart[{$cart_item_key}][qty]",
                                'input_value'  => $cart_item['quantity'],
                                'max_value'    => $_product->get_max_purchase_quantity(),
                                'min_value'    => 0,
                                'product_name' => $name,
                            ], $_product, false);
                            echo '<div class="cart-line__qty">' . apply_filters('woocommerce_cart_item_quantity', $quantity, $cart_item_key, $cart_item) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
                        }

                        echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput
                            'woocommerce_cart_item_remove_link',
                            sprintf(
                                '<a role="button" href="%s" class="cart-line__remove remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
                                esc_url(wc_get_cart_remove_url($cart_item_key)),
                                /* translators: %s: product name */
                                esc_attr(sprintf(__('Remove %s from cart', 'woocommerce'), wp_strip_all_tags($name))),
                                esc_attr($product_id),
                                esc_attr($_product->get_sku()),
                                esc_html__('Remove', 'woocommerce')
                            ),
                            $cart_item_key
                        );
                        ?>
                    </div>

                    <span class="cart-line__price"><?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                </li>
            <?php endforeach; ?>
            <?php do_action('woocommerce_cart_contents'); ?>
        </ul>

        <div class="cart-actions">
            <?php if (wc_coupons_enabled()) : ?>
                <details class="cart-coupon"<?php echo WC()->cart->get_coupons() ? ' open' : ''; ?>>
                    <summary><?php esc_html_e('Have a coupon code?', 'woocommerce'); ?></summary>
                    <div class="cart-coupon__row coupon">
                        <label for="coupon_code" class="screen-reader-text"><?php esc_html_e('Coupon:', 'woocommerce'); ?></label>
                        <input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e('Coupon code', 'woocommerce'); ?>">
                        <button type="submit" class="button button--ghost" name="apply_coupon" value="<?php esc_attr_e('Apply coupon', 'woocommerce'); ?>"><?php esc_html_e('Apply', 'woocommerce'); ?></button>
                        <?php do_action('woocommerce_cart_coupon'); ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php // WooCommerce's cart script enables this button when a quantity changes. Hidden by CSS when nothing in the cart has a quantity box. ?>
            <button type="submit" class="button button--ghost cart-update" name="update_cart" value="<?php esc_attr_e('Update cart', 'woocommerce'); ?>"><?php esc_html_e('Update cart', 'woocommerce'); ?></button>

            <?php do_action('woocommerce_cart_actions'); ?>
            <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
        </div>

        <?php do_action('woocommerce_after_cart_contents'); ?>
        <?php do_action('woocommerce_after_cart_table'); ?>
    </form>

    <aside class="cart-summary" aria-label="<?php esc_attr_e('Cart totals', 'woocommerce'); ?>">
        <?php // WooCommerce's own totals: subtotal, coupons, shipping and its calculator, fees, taxes, total and the checkout button, refreshed by its cart script. ?>
        <?php woocommerce_cart_totals(); ?>
        <a class="button button--ghost button--block" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Continue shopping', 'woocommerce'); ?></a>
    </aside>
</div>

<?php do_action('woocommerce_before_cart_collaterals'); ?>
<div class="cart-collaterals">
    <?php do_action('woocommerce_cart_collaterals'); // Cross-sells and other plugins; the totals are in the summary card. ?>
</div>

<?php do_action('woocommerce_after_cart'); ?>
