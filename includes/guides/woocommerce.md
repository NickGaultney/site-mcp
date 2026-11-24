---
title: WooCommerce pages styled by a classic theme
plugins: woocommerce
---

# WooCommerce pages styled by a classic theme

Use this whenever you build or restyle a classic PHP theme on a site with WooCommerce, including every Site MCP rebuild. The theme owns every pixel and WooCommerce supplies the markup and behaviour:

- WooCommerce's own CSS is off.
- One theme stylesheet, keyed to design tokens, styles the shop, product, cart, checkout and account pages.
- Only the two cart templates are overridden. Everything else is hooks, so WooCommerce updates rarely break anything.

This is adopted from a production store. The cart was rebuilt as a list with a sticky summary card, and checkout became two columns. My Account, the notices and the forms were restyled, all in about 500 lines.

## Before you start

- **The pages must be classic.** Cart, Checkout and My Account must contain the shortcodes `[woocommerce_cart]`, `[woocommerce_checkout]` and `[woocommerce_my_account]`. The Cart and Checkout *blocks* render in React and ignore PHP template overrides and most hooks. If a page has the blocks, ask the owner, then replace its content with the shortcode. Check the payment plugins still show on the classic checkout: some offer express buttons only for blocks.
- Page templates print these pages at full width. The theme's `page.php` can drop its narrow content column when `is_cart() || is_checkout() || is_account_page()`.

## The files

Each file's path is where it goes in the theme. Read it with `guide` (topic `woocommerce`, file `<path>`) and write it with theme_file_write, renaming `mytheme_` to the theme's prefix and `mytheme-woocommerce` to its slug.

| File | What it does |
| --- | --- |
| `inc/woocommerce.php` | Theme support, WooCommerce CSS off, scripts only on shop pages, the header cart badge, hooks for the theme's own shop wrapper, and the optional add-to-cart redirect and pre-Pay notice |
| `woocommerce/cart/cart.php` | Cart as a list beside a sticky summary card. It keeps every WooCommerce hook, quantities, variation and add-on data, and AJAX updates |
| `woocommerce/cart/cart-empty.php` | Empty cart as a centred card |
| `assets/css/woocommerce.css` | All WooCommerce styling, loaded only on shop pages. It covers buttons and forms, notices, tables, the shop grid, the product page, cart, checkout, order received and account |
| `template-parts/login-dialog.php` | Optional site-wide sign-in `<dialog>` that posts to WooCommerce's own login handler |

## Steps

1. Write `inc/woocommerce.php`, then delete the blocks that don't fit:
    - the `woocommerce.php` wrapper hooks, if the theme uses WooCommerce's own shop templates;
    - the redirect to the cart, for large catalogues;
    - the pre-Pay notice, if the store has no condition to state.
2. Write `assets/css/woocommerce.css`. Set its tokens at the top to the theme's colours and fonts. They default to the starter theme's `--color-*` and `--font-*` tokens. Keep the structure.
3. Write the two cart templates. Keep the `@version` lines: they match WooCommerce's templates, so WooCommerce's status page can flag them when it updates its own.
4. Add the header badge: in header.php, put `<?php echo mytheme_cart_count_html(); ?>` inside the cart link. Add the site-wide CSS below to `main.css`.
5. Optional: the sign-in dialog. Call `get_template_part('template-parts/login-dialog')` in footer.php. Give the header's account link `data-login-open` for signed-out visitors, keeping its href as the My Account URL.
6. Optional, digital-only stores: the switches below.
7. Verify, as below, before calling it done.

### Site-wide CSS for main.css

The badge and the dialog show on every page, and woocommerce.css loads only on shop pages:

```css
.cart-count { display: inline-grid; place-items: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 99px; background: var(--color-accent); color: #fff; font: 600 11px/1 var(--font-body); }
.cart-count[data-count="0"] { display: none; }
.login-dialog { width: min(420px, 100% - 32px); padding: 32px; border: 0; border-radius: 6px; }
.login-dialog::backdrop { background: rgb(0 0 0 / .45); }
.login-dialog__close { position: absolute; top: 8px; right: 8px; width: 44px; height: 44px; border: 0; background: none; font-size: 26px; cursor: pointer; }
.login-dialog input:not([type="checkbox"]) { width: 100%; min-height: 48px; padding: 10px 14px; font-size: 16px; }
.login-dialog label { display: block; margin-bottom: 6px; font-weight: 600; }
.login-dialog__links { margin: 16px 0 0; text-align: center; font-size: 14px; }
```

### Digital-only switches

Only for stores that sell no physical goods. They go in `inc/woocommerce.php`:

```php
add_filter('woocommerce_is_sold_individually', '__return_true');   // one of each, no quantity boxes
add_filter('woocommerce_enable_order_notes_field', '__return_false');
add_filter('woocommerce_checkout_fields', function ($fields) {
    unset($fields['billing']['billing_company'], $fields['billing']['billing_address_2']);   // keep company for B2B stores
    return $fields;
});
```

Settings that suit them too, after asking the owner:
- `woocommerce_checkout_phone_field` = `optional`;
- shipping zones turned off, with every product virtual.

When a purchase unlocks content tied to an account, also set these to require accounts and make them at checkout:
- `woocommerce_enable_guest_checkout` = `no`;
- `woocommerce_enable_signup_and_login_from_checkout` = `yes`;
- `woocommerce_registration_generate_username` = `yes`;
- `woocommerce_registration_generate_password` = `yes`.

## Patterns worth reusing (no code: adapt per site)

- **Own shop and product templates.** A `woocommerce.php` in the theme root renders the shop, categories and products from the theme's own parts. The wrapper hooks in `inc/woocommerce.php` clear WooCommerce's chrome for it.
  - A product grid of `repeat(auto-fill, minmax(min(200px, 40vw), 1fr))` gives two columns on phones with no media queries.
  - Variations can be separate "Add to cart · $price" links to each variation's `add_to_cart_url()`, with no dropdown and no JavaScript, when a product has few variations.
- **Cart suggestions.** Up to five best sellers (`orderby total_sales`) not in the cart and, for downloads, not already bought (`wc_customer_bought_product()`). Each has a plain add-to-cart link, which JavaScript can upgrade:
  1. POST to `WC_AJAX::get_endpoint('add_to_cart')`.
  2. Fetch the cart page again and swap in `.cart-page` and `.cart-count`.
  3. Fall back to the link on any error.
  4. Respect `prefers-reduced-motion`.
- **Account menu.** Rename or re-point items with `woocommerce_account_menu_items` and `woocommerce_get_endpoint_url`, and 301 any old endpoint with `template_redirect`.

## Verify

At 1440 px and 390 px:
- the cart with one, two and no items, a coupon applied, and a quantity changed and updated (the page updates in place);
- a variable product in the cart (its attributes listed under the name);
- shipping and tax rows, when the store has them;
- checkout signed out and signed in, with every payment method's box;
- a payment plugin's buttons still showing on the cart and checkout;
- an order placed, and the order received page;
- My Account signed out (both login cards) and signed in (every tab).

Also check WooCommerce → Status shows no outdated template overrides.

## Gotchas

- **Block cart and checkout ignore all of this.** Switch to the shortcodes first.
- **Don't lay out `form.checkout` with CSS grid.** WooCommerce and payment plugins inject hidden children into it, and grid then misplaces "Your order". Use the floats in the stylesheet.
- **Hidden widgets lose their styles with WooCommerce's CSS off:** the show-password eye, Select2 boxes, and the "Have a coupon?" and "Returning customer?" toggles on checkout. The stylesheet covers them; check any others a plugin adds.
- **Keep every hook call in the overridden templates.** Payment buttons, coupon and add-on plugins, and the cart nonce hang off them, and leaving one out breaks a plugin silently.
- **Keep `.woocommerce-cart-form`, `.cart_totals` and `.wc-empty-cart-message`.** WooCommerce's cart script swaps them in place after AJAX updates. Without the last one, emptying the cart by AJAX leaves a blank page.
- **`wc-cart-fragments` costs a request on every page.** Load it only where the cart can change without a page load, as `inc/woocommerce.php` does.
- **Inputs under 16 px make iOS zoom the page on focus.**
- **Variation names.** WooCommerce names a variation "Product – Attribute". The cart template shows the parent's name, with the attributes listed underneath.
