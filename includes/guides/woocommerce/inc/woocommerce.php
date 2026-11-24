<?php
/**
 * WooCommerce, restyled by the theme. WooCommerce supplies markup and behaviour; the theme owns every pixel
 * (assets/css/woocommerce.css). Only the cart has template overrides (woocommerce/cart/); everything else is
 * hooks here, so WooCommerce updates rarely break anything.
 *
 * Rename mytheme_ to the theme's own prefix, and 'mytheme-woocommerce' to its slug.
 */

defined('ABSPATH') || exit;

if (!class_exists('WooCommerce')) {
    return;
}

add_action('after_setup_theme', function () {
    add_theme_support('woocommerce', [
        'thumbnail_image_width' => 400,
        'single_image_width'    => 600,
        'product_grid'          => ['default_columns' => 3, 'default_rows' => 4],
    ]);
    add_theme_support('wc-product-gallery-lightbox');
});

/** Shop, product, cart, checkout and account pages: where WooCommerce's styles and scripts are needed. */
function mytheme_is_woo_context()
{
    return is_woocommerce() || is_cart() || is_checkout() || is_account_page();
}

// WooCommerce's three stylesheets are replaced by the theme's.
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

add_action('wp_enqueue_scripts', function () {
    if (mytheme_is_woo_context()) {
        $file = 'assets/css/woocommerce.css';
        wp_enqueue_style('mytheme-woocommerce', get_theme_file_uri($file), [], filemtime(get_theme_file_path($file)));
    } else {
        // Pages outside the shop only link to products. If the theme puts AJAX add-to-cart buttons on other
        // pages (a home page product row), widen mytheme_is_woo_context() to include them instead.
        foreach (['woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'wc-order-attribution', 'sourcebuster-js', 'jquery-blockui', 'wc-jquery-blockui', 'js-cookie', 'wc-js-cookie'] as $handle) {
            wp_dequeue_script($handle);
        }
    }
    // Classic pages use no WooCommerce blocks. Keep this if any page content uses WooCommerce blocks.
    wp_dequeue_style('wc-blocks-style');
}, 100);

// The cart summary card prints the totals itself (woocommerce/cart/cart.php); cross-sells and other
// plugins' collaterals still run below the cart.
remove_action('woocommerce_cart_collaterals', 'woocommerce_cart_totals', 10);

// The theme's empty-cart template says it; the action still runs so "… removed. Undo?" shows.
remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message', 10);

// Header cart badge: print mytheme_cart_count_html() in header.php; AJAX add-to-cart keeps it current.
function mytheme_cart_count_html()
{
    $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    /* translators: %d: number of items in the cart */
    return '<span class="cart-count" data-count="' . (int) $count . '" aria-label="' . esc_attr(sprintf(_n('%d item in cart', '%d items in cart', $count, 'mytheme'), $count)) . '">' . (int) $count . '</span>';
}
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $fragments['.cart-count'] = mytheme_cart_count_html();
    return $fragments;
});

/*
 * Shop, category and product pages through the theme's own woocommerce.php (theme root): the theme prints
 * the page header and breadcrumbs, so WooCommerce's wrappers, sidebar, breadcrumb and title go. Delete this
 * block if the theme has no woocommerce.php and relies on WooCommerce's templates.
 */
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
add_filter('woocommerce_show_page_title', '__return_false');

/*
 * Small catalogues only: "Add to cart" goes straight to the cart, so the notice needs no "View cart" button.
 * Delete for stores where people add several products in one visit.
 */
add_filter('woocommerce_add_to_cart_redirect', function () {
    return wc_get_cart_url();
});
add_filter('wc_add_to_cart_message_html', function ($message) {
    return trim(wp_strip_all_tags(preg_replace('#<a[^>]*>.*?</a>#s', '', $message)));
});

/*
 * Optional: a store-wide condition the buyer must see as they pay (final sale, made to order, delivery
 * times), right above Place order, linking the policy page when it is published. Delete if there is none.
 */
add_action('woocommerce_review_order_before_submit', function () {
    $page = get_page_by_path('refund-policy');
    printf(
        '<p class="checkout-final">%s%s</p>',
        esc_html__('All sales are final.', 'mytheme'),
        $page && $page->post_status === 'publish' ? ' <a href="' . esc_url(get_permalink($page)) . '" target="_blank">' . esc_html__('Refund policy', 'mytheme') . '</a>' : ''
    );
});
