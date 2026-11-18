<?php
defined('ABSPATH') || exit;

use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Permission\PermissionManager;

/**
 * Fluent Cart write tools. Reading orders, customers, coupons and reports comes
 * from Fluent Cart's own abilities (the abilities group).
 * Prices here are in store currency units (28.00), not cents.
 */
class Site_MCP_Store
{
    const POST_TYPE = 'fluent-products';
    const GALLERY_META = 'fluent-products-gallery-image';

    public static function can($permission)
    {
        return function () use ($permission) {
            return PermissionManager::hasPermission($permission);
        };
    }

    public static function product($id)
    {
        $post = get_post((int) $id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            throw new Site_MCP_Error('Product not found.');
        }
        $detail = ProductDetail::query()->where('post_id', $post->ID)->first();
        if (!$detail) {
            throw new Site_MCP_Error('This product has no Fluent Cart details row.');
        }
        return [$post, $detail];
    }

    public static function variants($post_id)
    {
        return ProductVariation::query()->where('post_id', $post_id)->orderBy('serial_index')->get();
    }

    public static function format($post_id)
    {
        [$post, $detail] = self::product($post_id);
        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'link'           => get_permalink($post),
            'excerpt'        => $post->post_excerpt,
            'categories'     => wp_get_object_terms($post->ID, 'product-categories', ['fields' => 'slugs']),
            'variation_type' => $detail->variation_type,
            'stock'          => $detail->stock_availability,
            'price_range'    => [$detail->min_price / 100, $detail->max_price / 100],
            'images'         => array_map(function ($img) {
                return ['id' => $img['id'] ?? null, 'url' => $img['url'] ?? ''];
            }, (array) get_post_meta($post->ID, self::GALLERY_META, true)),
            'variants'       => self::variants($post->ID)->map(function ($v) {
                return [
                    'id'            => $v->id,
                    'title'         => $v->variation_title,
                    'sku'           => $v->sku,
                    'price'         => $v->item_price / 100,
                    'compare_price' => $v->compare_price / 100,
                    'manage_stock'  => (bool) $v->manage_stock,
                    'available'     => (int) $v->available,
                    'stock_status'  => $v->stock_status,
                    'order'         => (int) $v->serial_index,
                ];
            })->toArray(),
            'content'        => $post->post_content,
        ];
    }

    /** Applies variant changes through Fluent Cart's own product update, which recalculates price ranges and stock. */
    public static function update_variants($post_id, array $changes)
    {
        [$post, $detail] = self::product($post_id);
        $rows = [];
        foreach (self::variants($post_id) as $v) {
            $change = null;
            foreach ($changes as $c) {
                if ((int) ($c['id'] ?? 0) === (int) $v->id) {
                    $change = $c;
                }
            }
            if ($change === null) {
                continue;
            }
            $manage = array_key_exists('manage_stock', $change) ? (bool) $change['manage_stock'] : (array_key_exists('available', $change) ? true : (bool) $v->manage_stock);
            $rows[] = [
                'id'              => $v->id,
                'variation_title' => $change['title'] ?? $v->variation_title,
                'sku'             => array_key_exists('sku', $change) ? $change['sku'] : $v->sku,
                'item_price'      => isset($change['price']) ? round($change['price'] * 100) : $v->item_price,
                'compare_price'   => isset($change['compare_price']) ? round($change['compare_price'] * 100) : $v->compare_price,
                'manage_stock'    => $manage ? 1 : 0,
                'available'       => (int) ($change['available'] ?? $v->available),
                'serial_index'    => $change['order'] ?? $v->serial_index,
            ];
        }
        if (count($rows) !== count($changes)) {
            throw new Site_MCP_Error('Some variant ids do not belong to this product. Get them with product_get.');
        }
        $result = ProductResource::update([
            // updateWpPost() rewrites these columns, so pass the current values through.
            'post_title'     => $post->post_title,
            'post_status'    => $post->post_status,
            'post_name'      => $post->post_name,
            'comment_status' => $post->comment_status,
            'detail'         => ['id' => $detail->id, 'variation_type' => $detail->variation_type],
            'variants'       => $rows,
        ], $post->ID);
        if (is_wp_error($result)) {
            throw new Site_MCP_Error($result->get_error_message());
        }
    }

    public static function set_images($post_id, array $ids)
    {
        $gallery = [];
        foreach ($ids as $id) {
            if (get_post_type((int) $id) !== 'attachment') {
                throw new Site_MCP_Error("Image $id is not a media item.");
            }
            $gallery[] = ['id' => (int) $id, 'title' => get_the_title((int) $id), 'url' => wp_get_attachment_url((int) $id)];
        }
        update_post_meta($post_id, self::GALLERY_META, $gallery);
        $gallery ? set_post_thumbnail($post_id, $gallery[0]['id']) : delete_post_thumbnail($post_id);
    }

    public static function variant_schema($for_update)
    {
        $props = [
            'title'         => ['type' => 'string', 'description' => 'e.g. "Sunday Sock".'],
            'price'         => ['type' => 'number', 'description' => 'In store currency, e.g. 28.00.'],
            'compare_price' => ['type' => 'number', 'description' => 'Optional "was" price; 0 for none.'],
            'available'     => ['type' => 'integer', 'description' => 'Units in stock. Setting this turns on stock tracking; 0 marks it sold out.'],
            'manage_stock'  => ['type' => 'boolean', 'description' => 'false = unlimited stock.'],
            'sku'           => ['type' => 'string'],
        ];
        if ($for_update) {
            $props = ['id' => ['type' => 'integer'], 'order' => ['type' => 'integer']] + $props;
        }
        return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $props, 'required' => $for_update ? ['id'] : ['title', 'price']]];
    }
}

add_action('site_mcp_register_tools', function () {
    if (!class_exists(ProductResource::class)) {
        return;
    }

    Site_MCP_Tools::add('product_get', [
        'group'       => 'store',
        'readonly'    => true,
        'cap'         => Site_MCP_Store::can('products/view'),
        'description' => 'Get a store product with its variants (id, title, price, stock), categories, images and description. Find products with posts_list post_type=fluent-products.',
        'properties'  => ['id' => ['type' => 'integer']],
        'required'    => ['id'],
        'run'         => function ($args) {
            return Site_MCP_Store::format($args['id']);
        },
    ]);

    Site_MCP_Tools::add('product_update', [
        'group'       => 'store',
        'cap'         => Site_MCP_Store::can('products/edit'),
        'description' => 'Update a product: variant prices, stock, titles, SKUs and order; and optionally its post fields (title, content, excerpt, status, slug), categories and images. Only what you pass changes.',
        'properties'  => [
            'id'         => ['type' => 'integer'],
            'variants'   => Site_MCP_Store::variant_schema(true),
            'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'product-categories slugs; replaces the current set.'],
            'image_ids'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Gallery attachment IDs in order; the first is the main image. Replaces the gallery.'],
            'edits'      => Site_MCP_Tools::edits_schema(),
        ] + array_intersect_key(Site_MCP_Content::fields_schema(), array_flip(['title', 'content', 'excerpt', 'status', 'slug', 'comment_status', 'meta'])),
        'required'    => ['id'],
        'run'         => function ($args) {
            [$post] = Site_MCP_Store::product($args['id']);
            Site_MCP_Content::require_cap('edit_post', $post->ID);
            $fields = array_intersect_key($args, array_flip(['title', 'content', 'excerpt', 'status', 'slug', 'comment_status', 'meta', 'edits']));
            if (isset($args['categories'])) {
                $fields['terms'] = ['product-categories' => $args['categories']];
            }
            if ($fields) {
                Site_MCP_Content::save($fields, $post);
            }
            if (!empty($args['variants'])) {
                Site_MCP_Store::update_variants($post->ID, $args['variants']);
            }
            if (isset($args['image_ids'])) {
                Site_MCP_Store::set_images($post->ID, $args['image_ids']);
            }
            return ['message' => 'Product updated'] + Site_MCP_Store::format($post->ID);
        },
    ]);

    Site_MCP_Tools::add('product_create', [
        'group'       => 'store',
        'cap'         => Site_MCP_Store::can('products/create'),
        'description' => 'Create a physical product with one or more variants (e.g. one per yarn base). Upload images first with media_upload and pass their IDs. Created as a draft unless status=publish.',
        'properties'  => [
            'title'      => ['type' => 'string'],
            'slug'       => ['type' => 'string'],
            'content'    => ['type' => 'string', 'description' => 'Long description (block markup).'],
            'excerpt'    => ['type' => 'string', 'description' => 'Short description shown near the price.'],
            'status'     => ['type' => 'string', 'enum' => ['draft', 'publish'], 'default' => 'draft'],
            'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'product-categories slugs.'],
            'image_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
            'variants'   => Site_MCP_Store::variant_schema(false),
        ],
        'required'    => ['title', 'variants'],
        'run'         => function ($args) {
            $variants = [];
            foreach ($args['variants'] as $i => $v) {
                $manage = $v['manage_stock'] ?? isset($v['available']);
                $available = (int) ($v['available'] ?? 0);
                $variants[] = [
                    'variation_title'  => $v['title'],
                    'sku'              => $v['sku'] ?? null,
                    'serial_index'     => $i + 1,
                    'stock_status'     => !$manage || $available > 0 ? 'in-stock' : 'out-of-stock',
                    'payment_type'     => 'onetime',
                    'total_stock'      => (string) $available,
                    'available'        => (string) $available,
                    'item_price'       => (string) round($v['price'] * 100),
                    'compare_price'    => (string) round(($v['compare_price'] ?? 0) * 100),
                    'fulfillment_type' => 'physical',
                    'manage_stock'     => $manage ? '1' : '0',
                    'other_info'       => ['payment_type' => 'onetime'],
                ];
            }
            $prices = array_column($variants, 'item_price');
            $insert = new class extends \FluentCart\App\Services\Async\DummyProductService {
                public function run(array $p)
                {
                    $this->insert($p);
                }
            };
            $insert->run([
                'post_title'   => $args['title'],
                'post_excerpt' => $args['excerpt'] ?? '',
                'post_content' => $args['content'] ?? '',
                'detail'       => [
                    'fulfillment_type'   => 'physical',
                    'manage_stock'       => '1',
                    'variation_type'     => count($variants) > 1 ? 'simple_variations' : 'simple',
                    'stock_availability' => in_array('in-stock', array_column($variants, 'stock_status'), true) ? 'in-stock' : 'out-of-stock',
                    'min_price'          => (string) min($prices),
                    'max_price'          => (string) max($prices),
                ],
                'variants'     => $variants,
            ]);
            $created = get_posts(['post_type' => Site_MCP_Store::POST_TYPE, 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'author' => get_current_user_id()]);
            if (!$created || $created[0]->post_title !== $args['title']) {
                throw new Site_MCP_Error('Product creation failed.');
            }
            $id = $created[0]->ID;
            wp_update_post(['ID' => $id, 'post_name' => sanitize_title($args['slug'] ?? $args['title']), 'post_status' => $args['status'] ?? 'draft']);
            if (!empty($args['categories'])) {
                wp_set_object_terms($id, $args['categories'], 'product-categories');
            }
            if (!empty($args['image_ids'])) {
                Site_MCP_Store::set_images($id, $args['image_ids']);
            }
            return ['message' => 'Product created'] + Site_MCP_Store::format($id);
        },
    ]);

    Site_MCP_Tools::add('product_variant_add', [
        'group'       => 'store',
        'cap'         => Site_MCP_Store::can('products/edit'),
        'description' => 'Add a variant (e.g. a new yarn base) to an existing product.',
        'properties'  => ['product_id' => ['type' => 'integer']] + Site_MCP_Store::variant_schema(false)['items']['properties'],
        'required'    => ['product_id', 'title', 'price'],
        'run'         => function ($args) {
            [$post, $detail] = Site_MCP_Store::product($args['product_id']);
            Site_MCP_Content::require_cap('edit_post', $post->ID);
            $manage = $args['manage_stock'] ?? isset($args['available']);
            $result = ProductVariationResource::create([
                'post_id'         => $post->ID,
                'detail_id'       => $detail->id,
                'serial_index'    => Site_MCP_Store::variants($post->ID)->count() + 1,
                'variation_title' => $args['title'],
                'sku'             => $args['sku'] ?? null,
                'item_price'      => round($args['price'] * 100),
                'compare_price'   => round(($args['compare_price'] ?? 0) * 100),
                'manage_stock'    => $manage ? 1 : 0,
                'total_stock'     => (int) ($args['available'] ?? 0),
                'available'       => (int) ($args['available'] ?? 0),
                'downloadable'    => false,
                'other_info'      => ['payment_type' => 'onetime'],
            ]);
            if (is_wp_error($result)) {
                throw new Site_MCP_Error($result->get_error_message());
            }
            if ($detail->variation_type === 'simple') {
                $detail->update(['variation_type' => 'simple_variations']);
            }
            // Recalculate the product's price range and stock.
            Site_MCP_Store::update_variants($post->ID, [['id' => $result['data']->id]]);
            return ['message' => 'Variant added'] + Site_MCP_Store::format($post->ID);
        },
    ]);

    Site_MCP_Tools::add('product_variant_delete', [
        'group'       => 'store',
        'destructive' => true,
        'cap'         => Site_MCP_Store::can('products/edit'),
        'description' => 'Delete a product variant. Refused while open orders reference it; to stop selling it, set available=0 instead.',
        'properties'  => ['product_id' => ['type' => 'integer'], 'variant_id' => ['type' => 'integer']],
        'required'    => ['product_id', 'variant_id'],
        'run'         => function ($args) {
            [$post] = Site_MCP_Store::product($args['product_id']);
            $variants = Site_MCP_Store::variants($post->ID);
            if (!$variants->firstWhere('id', (int) $args['variant_id'])) {
                throw new Site_MCP_Error('That variant does not belong to this product.');
            }
            if ($variants->count() < 2) {
                throw new Site_MCP_Error('A product needs at least one variant. Delete the product with post_delete instead.');
            }
            $result = ProductVariationResource::delete((int) $args['variant_id']);
            if (is_wp_error($result)) {
                throw new Site_MCP_Error($result->get_error_message());
            }
            $remaining = Site_MCP_Store::variants($post->ID)->first();
            ProductDetail::query()->where('post_id', $post->ID)->where('default_variation_id', (int) $args['variant_id'])->update(['default_variation_id' => $remaining->id]);
            Site_MCP_Store::update_variants($post->ID, [['id' => $remaining->id]]);
            return ['message' => 'Variant deleted'] + Site_MCP_Store::format($post->ID);
        },
    ]);
});
