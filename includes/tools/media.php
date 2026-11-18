<?php
defined('ABSPATH') || exit;

function site_mcp_media_item($id)
{
    $post = get_post($id);
    $meta = wp_get_attachment_metadata($id);
    return [
        'id'          => $post->ID,
        'title'       => $post->post_title,
        'url'         => wp_get_attachment_url($id),
        'mime'        => $post->post_mime_type,
        'alt'         => get_post_meta($id, '_wp_attachment_image_alt', true),
        'caption'     => $post->post_excerpt,
        'width'       => $meta['width'] ?? null,
        'height'      => $meta['height'] ?? null,
        'sizes'       => isset($meta['sizes']) ? array_map(function ($size) use ($id) {
            return wp_get_attachment_image_url($id, $size);
        }, array_combine(array_keys($meta['sizes']), array_keys($meta['sizes']))) : [],
        'attached_to' => $post->post_parent,
        'date'        => $post->post_date,
    ];
}

function site_mcp_media_fields($id, $args)
{
    $post = [];
    foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $arg => $field) {
        if (isset($args[$arg])) {
            $post[$field] = $args[$arg];
        }
    }
    if ($post) {
        wp_update_post(wp_slash(['ID' => $id] + $post));
    }
    if (isset($args['alt'])) {
        update_post_meta($id, '_wp_attachment_image_alt', wp_slash(sanitize_text_field($args['alt'])));
    }
}

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('media_list', [
        'group'       => 'media',
        'readonly'    => true,
        'cap'         => 'upload_files',
        'description' => 'List or search the media library. Returns URLs, alt text, dimensions and image size URLs.',
        'properties'  => [
            'search'    => ['type' => 'string'],
            'mime_type' => ['type' => 'string', 'description' => 'e.g. "image" or "application/pdf".'],
            'per_page'  => ['type' => 'integer', 'default' => 30],
            'page'      => ['type' => 'integer', 'default' => 1],
        ],
        'run'         => function ($args) {
            $q = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                's'              => $args['search'] ?? '',
                'post_mime_type' => $args['mime_type'] ?? '',
                'posts_per_page' => Site_MCP_Tools::int_arg($args, 'per_page', 30, 1, 100),
                'paged'          => Site_MCP_Tools::int_arg($args, 'page', 1, 1, 10000),
                'fields'         => 'ids',
            ]);
            return ['total' => (int) $q->found_posts, 'items' => array_map('site_mcp_media_item', $q->posts)];
        },
    ]);

    Site_MCP_Tools::add('media_upload', [
        'group'       => 'media',
        'cap'         => 'upload_files',
        'description' => 'Add a file to the media library from a public URL or base64 data (images, PDFs, video; the types WordPress allows). Optionally attach it to a post and set it as the featured image.',
        'properties'  => [
            'url'         => ['type' => 'string', 'description' => 'Public http(s) URL to download.'],
            'base64'      => ['type' => 'string', 'description' => 'File contents as base64 (max ~15 MB). Requires filename.'],
            'filename'    => ['type' => 'string', 'description' => 'e.g. "poppy-field.jpg". Defaults to the URL\'s file name.'],
            'title'       => ['type' => 'string'],
            'alt'         => ['type' => 'string', 'description' => 'Alt text describing the image.'],
            'caption'     => ['type' => 'string'],
            'attach_to'   => ['type' => 'integer', 'description' => 'Post ID to attach to.'],
            'set_featured' => ['type' => 'boolean', 'description' => 'With attach_to: make it the featured image.'],
        ],
        'run'         => function ($args) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $parent = (int) ($args['attach_to'] ?? 0);
            if ($parent) {
                Site_MCP_Content::require_cap('edit_post', $parent);
            }
            if (!empty($args['url'])) {
                $tmp = download_url($args['url'], 60);
                if (is_wp_error($tmp)) {
                    throw new Site_MCP_Error('Download failed: ' . $tmp->get_error_message());
                }
                $name = $args['filename'] ?? basename(parse_url($args['url'], PHP_URL_PATH));
            } elseif (!empty($args['base64']) && !empty($args['filename'])) {
                $bytes = base64_decode(preg_replace('#^data:[^,]+,#', '', $args['base64']), true);
                if ($bytes === false || strlen($bytes) > 15 * MB_IN_BYTES) {
                    throw new Site_MCP_Error('base64 is invalid or larger than 15 MB.');
                }
                $tmp = wp_tempnam($args['filename']);
                file_put_contents($tmp, $bytes);
                $name = $args['filename'];
            } else {
                throw new Site_MCP_Error('Pass url, or base64 with filename.');
            }

            $id = media_handle_sideload(['name' => sanitize_file_name($name), 'tmp_name' => $tmp], $parent, $args['title'] ?? null);
            if (is_wp_error($id)) {
                @unlink($tmp);
                throw new Site_MCP_Error($id->get_error_message());
            }
            site_mcp_media_fields($id, $args);
            if ($parent && !empty($args['set_featured'])) {
                set_post_thumbnail($parent, $id);
            }
            return ['message' => 'Uploaded', 'id' => $id] + site_mcp_media_item($id);
        },
    ]);

    Site_MCP_Tools::add('media_update', [
        'group'       => 'media',
        'cap'         => 'upload_files',
        'description' => 'Change a media item\'s title, alt text, caption or description. Delete media with post_delete.',
        'properties'  => [
            'id'          => ['type' => 'integer'],
            'title'       => ['type' => 'string'],
            'alt'         => ['type' => 'string'],
            'caption'     => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ],
        'required'    => ['id'],
        'run'         => function ($args) {
            $id = (int) $args['id'];
            if (get_post_type($id) !== 'attachment') {
                throw new Site_MCP_Error('Not a media item.');
            }
            Site_MCP_Content::require_cap('edit_post', $id);
            site_mcp_media_fields($id, $args);
            return ['message' => 'Updated', 'id' => $id] + site_mcp_media_item($id);
        },
    ]);
});
