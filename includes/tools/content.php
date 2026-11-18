<?php
defined('ABSPATH') || exit;

class Site_MCP_Content
{
    const HIDDEN_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time'];

    public static function find_post($args)
    {
        if (!empty($args['id'])) {
            $post = get_post((int) $args['id']);
        } elseif (!empty($args['slug'])) {
            $post = get_page_by_path($args['slug'], OBJECT, $args['post_type'] ?? ['post', 'page']);
        } else {
            throw new Site_MCP_Error('Pass id, or slug with post_type.');
        }
        if (!$post) {
            throw new Site_MCP_Error('Post not found.');
        }
        return $post;
    }

    public static function require_cap($cap, $id)
    {
        if (!current_user_can($cap, $id)) {
            throw new Site_MCP_Error("Your account is not allowed to $cap #$id.");
        }
    }

    public static function summary(WP_Post $post)
    {
        return [
            'id'       => $post->ID,
            'type'     => $post->post_type,
            'title'    => $post->post_title,
            'slug'     => $post->post_name,
            'status'   => $post->post_status,
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'parent'   => $post->post_parent,
            'link'     => get_permalink($post),
        ];
    }

    public static function full(WP_Post $post)
    {
        $out = self::summary($post) + [
            'content'        => $post->post_content,
            'excerpt'        => $post->post_excerpt,
            'menu_order'     => $post->menu_order,
            'template'       => get_page_template_slug($post),
            'featured_media' => (int) get_post_thumbnail_id($post),
            'author'         => (int) $post->post_author,
            'comment_status' => $post->comment_status,
            'terms'          => [],
            'meta'           => [],
            'revisions'      => count(wp_get_post_revisions($post->ID, ['fields' => 'ids'])),
        ];
        foreach (get_object_taxonomies($post->post_type) as $tax) {
            $terms = get_the_terms($post, $tax);
            if ($terms && !is_wp_error($terms)) {
                $out['terms'][$tax] = array_map(function ($t) {
                    return ['id' => $t->term_id, 'slug' => $t->slug, 'name' => $t->name];
                }, $terms);
            }
        }
        $show_protected = current_user_can('manage_options');
        foreach (get_post_meta($post->ID) as $key => $values) {
            if (in_array($key, self::HIDDEN_META, true) || (!$show_protected && is_protected_meta($key, 'post'))) {
                continue;
            }
            $values = array_map('maybe_unserialize', $values);
            $out['meta'][$key] = count($values) === 1 ? $values[0] : $values;
        }
        return $out;
    }

    /** Shared by create and update: post fields, terms, meta. */
    public static function save(array $args, $post = null)
    {
        $data = [];
        $map = [
            'title'          => 'post_title',
            'content'        => 'post_content',
            'excerpt'        => 'post_excerpt',
            'status'         => 'post_status',
            'slug'           => 'post_name',
            'parent'         => 'post_parent',
            'menu_order'     => 'menu_order',
            'date'           => 'post_date',
            'comment_status' => 'comment_status',
            'template'       => 'page_template',
        ];
        foreach ($map as $arg => $field) {
            if (array_key_exists($arg, $args)) {
                $data[$field] = $args[$arg];
            }
        }
        if (!empty($args['edits'])) {
            if (!$post) {
                throw new Site_MCP_Error('edits can only be used when updating.');
            }
            $data['post_content'] = Site_MCP_Tools::apply_edits(array_key_exists('post_content', $data) ? $data['post_content'] : $post->post_content, $args['edits']);
        }
        if (isset($data['post_status']) && in_array($data['post_status'], ['publish', 'future'], true)) {
            $type = get_post_type_object($post ? $post->post_type : $args['post_type']);
            if ($type && !current_user_can($type->cap->publish_posts)) {
                throw new Site_MCP_Error('Your account is not allowed to publish ' . $type->label . '.');
            }
        }

        if ($post) {
            $data['ID'] = $post->ID;
            $id = wp_update_post(wp_slash($data), true);
        } else {
            $data['post_type'] = $args['post_type'];
            $data += ['post_status' => 'draft'];
            $id = wp_insert_post(wp_slash($data), true);
        }
        if (is_wp_error($id)) {
            throw new Site_MCP_Error($id->get_error_message());
        }

        if (array_key_exists('featured_media', $args)) {
            $args['featured_media'] ? set_post_thumbnail($id, (int) $args['featured_media']) : delete_post_thumbnail($id);
        }
        foreach ((array) ($args['terms'] ?? []) as $tax => $terms) {
            $taxonomy = get_taxonomy($tax);
            if (!$taxonomy || !is_object_in_taxonomy(get_post_type($id), $tax)) {
                throw new Site_MCP_Error("Taxonomy $tax does not apply to this post type.");
            }
            if (!current_user_can($taxonomy->cap->assign_terms)) {
                throw new Site_MCP_Error("Your account is not allowed to assign $tax terms.");
            }
            $terms = array_map(function ($t) {
                return is_numeric($t) ? (int) $t : $t;
            }, (array) $terms);
            $result = wp_set_object_terms($id, $terms, $tax);
            if (is_wp_error($result)) {
                throw new Site_MCP_Error($result->get_error_message());
            }
        }
        foreach ((array) ($args['meta'] ?? []) as $key => $value) {
            if (is_protected_meta($key, 'post') && !current_user_can('manage_options')) {
                throw new Site_MCP_Error("Only administrators can change protected meta ($key).");
            }
            $value === null ? delete_post_meta($id, $key) : update_post_meta($id, $key, wp_slash($value));
        }

        clean_post_cache($id);
        $saved = get_post($id);
        $out = ['message' => $post ? 'Updated' : 'Created', 'id' => $id, 'status' => $saved->post_status, 'link' => get_permalink($saved)];
        if (array_key_exists('post_content', $data) && ($warnings = Site_MCP_Tools::block_warnings($saved->post_content))) {
            $out['block_warnings'] = $warnings;
        }
        return $out;
    }

    public static function fields_schema()
    {
        return [
            'title'          => ['type' => 'string'],
            'content'        => ['type' => 'string', 'description' => 'Full content (block markup). Replaces the existing content.'],
            'excerpt'        => ['type' => 'string'],
            'status'         => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private', 'future']],
            'slug'           => ['type' => 'string'],
            'parent'         => ['type' => 'integer'],
            'menu_order'     => ['type' => 'integer'],
            'date'           => ['type' => 'string', 'description' => 'Local time, Y-m-d H:i:s. With status=future, schedules the post.'],
            'comment_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
            'template'       => ['type' => 'string', 'description' => 'Custom template slug, e.g. "page-wide"; empty for default.'],
            'featured_media' => ['type' => 'integer', 'description' => 'Attachment ID; 0 removes it.'],
            'terms'          => ['type' => 'object', 'description' => 'Replace terms per taxonomy: {"category": ["news", 12]}. Strings are slugs (created if missing), numbers are IDs.', 'additionalProperties' => ['type' => 'array']],
            'meta'           => ['type' => 'object', 'description' => 'Set meta values: {"key": value}. null deletes the key. Keys starting with _ need an administrator.'],
        ];
    }
}

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('posts_list', [
        'group'       => 'content',
        'readonly'    => true,
        'description' => 'List or search posts of any type (post, page, fluent-products, wp_block, wp_navigation, attachment…). Returns IDs, titles, slugs, status and links, without content.',
        'properties'  => [
            'post_type' => ['type' => ['string', 'array'], 'default' => 'any', 'description' => 'A post type, a list of them, or "any".'],
            'status'    => ['type' => 'string', 'default' => 'any', 'description' => 'publish, draft, pending, private, future, trash or any (all but trash).'],
            'search'    => ['type' => 'string'],
            'parent'    => ['type' => 'integer'],
            'taxonomy'  => ['type' => 'string', 'description' => 'With term: only posts in this term.'],
            'term'      => ['type' => 'string', 'description' => 'Term slug.'],
            'orderby'   => ['type' => 'string', 'enum' => ['date', 'modified', 'title', 'menu_order', 'ID'], 'default' => 'date'],
            'order'     => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
            'per_page'  => ['type' => 'integer', 'default' => 20, 'description' => 'Max 100.'],
            'page'      => ['type' => 'integer', 'default' => 1],
        ],
        'run'         => function ($args) {
            $query = [
                'post_type'      => $args['post_type'] ?? 'any',
                'post_status'    => $args['status'] ?? 'any',
                's'              => $args['search'] ?? '',
                'orderby'        => $args['orderby'] ?? 'date',
                'order'          => $args['order'] ?? 'DESC',
                'posts_per_page' => Site_MCP_Tools::int_arg($args, 'per_page', 20, 1, 100),
                'paged'          => Site_MCP_Tools::int_arg($args, 'page', 1, 1, 100000),
                'perm'           => 'editable',
                'no_found_rows'  => false,
                'suppress_filters' => true,
            ];
            if (isset($args['parent'])) {
                $query['post_parent'] = (int) $args['parent'];
            }
            if (!empty($args['taxonomy']) && !empty($args['term'])) {
                $query['tax_query'] = [['taxonomy' => $args['taxonomy'], 'field' => 'slug', 'terms' => $args['term']]];
            }
            if (($args['post_type'] ?? '') === 'attachment' && $query['post_status'] === 'any') {
                $query['post_status'] = 'inherit';
            }
            $q = new WP_Query($query);
            return ['total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages, 'posts' => array_map(['Site_MCP_Content', 'summary'], $q->posts)];
        },
    ]);

    Site_MCP_Tools::add('post_get', [
        'group'       => 'content',
        'readonly'    => true,
        'description' => 'Get one post of any type with its full content (block markup), excerpt, template, featured image, terms and meta.',
        'properties'  => [
            'id'        => ['type' => 'integer'],
            'slug'      => ['type' => 'string', 'description' => 'Alternative to id, with post_type.'],
            'post_type' => ['type' => 'string'],
        ],
        'run'         => function ($args) {
            $post = Site_MCP_Content::find_post($args);
            Site_MCP_Content::require_cap('edit_post', $post->ID);
            return Site_MCP_Content::full($post);
        },
    ]);

    Site_MCP_Tools::add('post_create', [
        'group'       => 'content',
        'description' => 'Create a post of any type (post, page, wp_block for a synced pattern, wp_navigation for a menu…). Defaults to draft. For store products with prices use product_create.',
        'properties'  => ['post_type' => ['type' => 'string', 'default' => 'post']] + Site_MCP_Content::fields_schema(),
        'required'    => ['post_type', 'title'],
        'run'         => function ($args) {
            $type = get_post_type_object($args['post_type']);
            if (!$type) {
                throw new Site_MCP_Error('Unknown post type ' . $args['post_type']);
            }
            if (!current_user_can($type->cap->create_posts)) {
                throw new Site_MCP_Error('Your account is not allowed to create ' . $type->label . '.');
            }
            return Site_MCP_Content::save($args);
        },
    ]);

    Site_MCP_Tools::add('post_update', [
        'group'       => 'content',
        'description' => 'Update a post of any type. Only the fields you pass change. Use edits for small changes to long content. The previous version is kept as a revision.',
        'properties'  => ['id' => ['type' => 'integer'], 'edits' => Site_MCP_Tools::edits_schema()] + Site_MCP_Content::fields_schema(),
        'required'    => ['id'],
        'run'         => function ($args) {
            $post = Site_MCP_Content::find_post($args);
            Site_MCP_Content::require_cap('edit_post', $post->ID);
            return Site_MCP_Content::save($args, $post);
        },
    ]);

    Site_MCP_Tools::add('post_delete', [
        'group'       => 'content',
        'destructive' => true,
        'description' => 'Move a post to the trash (restore it with post_update and a status), or delete it permanently with force=true.',
        'properties'  => [
            'id'    => ['type' => 'integer'],
            'force' => ['type' => 'boolean', 'default' => false],
        ],
        'required'    => ['id'],
        'run'         => function ($args) {
            $post = Site_MCP_Content::find_post($args);
            Site_MCP_Content::require_cap('delete_post', $post->ID);
            if ($post->post_type === 'attachment') {
                $ok = wp_delete_attachment($post->ID, true);
            } elseif ($post->post_type === 'fluent-products' && !empty($args['force']) && class_exists('FluentCart\Api\Resource\ProductResource')) {
                // Fluent Cart keeps variants and details in its own tables; its delete removes them (and refuses with open orders).
                $result = \FluentCart\Api\Resource\ProductResource::delete($post->ID);
                if (is_wp_error($result)) {
                    throw new Site_MCP_Error($result->get_error_message());
                }
                clean_post_cache($post->ID);
                $ok = get_post($post->ID) ? wp_delete_post($post->ID, true) : true;
            } elseif (!empty($args['force']) || !EMPTY_TRASH_DAYS) {
                $ok = wp_delete_post($post->ID, true);
            } else {
                $ok = wp_trash_post($post->ID);
            }
            if (!$ok) {
                throw new Site_MCP_Error('Delete failed.');
            }
            return ['message' => !empty($args['force']) || $post->post_type === 'attachment' ? 'Deleted permanently' : 'Moved to trash', 'id' => $post->ID];
        },
    ]);

    Site_MCP_Tools::add('post_revisions', [
        'group'       => 'content',
        'readonly'    => true,
        'description' => 'List saved revisions of a post, page or template (newest first). Pass revision_id to get one revision\'s full content.',
        'properties'  => [
            'id'          => ['type' => 'integer', 'description' => 'Post ID (for a template, its wp_id from template_get).'],
            'revision_id' => ['type' => 'integer'],
        ],
        'run'         => function ($args) {
            if (!empty($args['revision_id'])) {
                $rev = wp_get_post_revision((int) $args['revision_id']);
                if (!$rev) {
                    throw new Site_MCP_Error('Revision not found.');
                }
                Site_MCP_Content::require_cap('edit_post', $rev->post_parent);
                return ['id' => $rev->ID, 'parent' => $rev->post_parent, 'date' => $rev->post_date, 'title' => $rev->post_title, 'content' => $rev->post_content, 'excerpt' => $rev->post_excerpt];
            }
            $post = Site_MCP_Content::find_post($args);
            Site_MCP_Content::require_cap('edit_post', $post->ID);
            return array_values(array_map(function ($rev) {
                $author = get_userdata($rev->post_author);
                return ['revision_id' => $rev->ID, 'date' => $rev->post_date, 'author' => $author ? $author->display_name : '', 'title' => $rev->post_title, 'content_length' => strlen($rev->post_content)];
            }, wp_get_post_revisions($post->ID)));
        },
    ]);

    Site_MCP_Tools::add('post_revision_restore', [
        'group'       => 'content',
        'description' => 'Restore a post, page or template to an earlier revision (the current version is kept as a new revision, so this can be undone too).',
        'properties'  => ['revision_id' => ['type' => 'integer']],
        'required'    => ['revision_id'],
        'run'         => function ($args) {
            $rev = wp_get_post_revision((int) $args['revision_id']);
            if (!$rev) {
                throw new Site_MCP_Error('Revision not found.');
            }
            Site_MCP_Content::require_cap('edit_post', $rev->post_parent);
            $id = wp_restore_post_revision($rev->ID);
            if (!$id) {
                throw new Site_MCP_Error('Restore failed.');
            }
            return ['message' => 'Restored', 'id' => $id, 'link' => get_permalink($id)];
        },
    ]);

    /* ---------- Taxonomies ---------- */

    Site_MCP_Tools::add('terms_list', [
        'group'       => 'content',
        'readonly'    => true,
        'description' => 'List terms in a taxonomy (category, post_tag, product-categories…), with descriptions and counts.',
        'properties'  => [
            'taxonomy' => ['type' => 'string'],
            'search'   => ['type' => 'string'],
            'parent'   => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 100],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required'    => ['taxonomy'],
        'run'         => function ($args) {
            if (!taxonomy_exists($args['taxonomy'])) {
                throw new Site_MCP_Error('Unknown taxonomy.');
            }
            $per = Site_MCP_Tools::int_arg($args, 'per_page', 100, 1, 500);
            $query = ['taxonomy' => $args['taxonomy'], 'hide_empty' => false, 'number' => $per, 'offset' => $per * (Site_MCP_Tools::int_arg($args, 'page', 1, 1, 10000) - 1), 'search' => $args['search'] ?? ''];
            if (isset($args['parent'])) {
                $query['parent'] = (int) $args['parent'];
            }
            $terms = get_terms($query);
            if (is_wp_error($terms)) {
                throw new Site_MCP_Error($terms->get_error_message());
            }
            return array_map(function ($t) {
                return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count, 'description' => $t->description, 'link' => get_term_link($t)];
            }, $terms);
        },
    ]);

    Site_MCP_Tools::add('term_save', [
        'group'       => 'content',
        'description' => 'Create a term, or update one when id is given (name, slug, description, parent, meta).',
        'properties'  => [
            'taxonomy'    => ['type' => 'string'],
            'id'          => ['type' => 'integer', 'description' => 'Omit to create.'],
            'name'        => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent'      => ['type' => 'integer'],
            'meta'        => ['type' => 'object', 'description' => '{"key": value}; null deletes.'],
        ],
        'required'    => ['taxonomy'],
        'run'         => function ($args) {
            $tax = get_taxonomy($args['taxonomy']);
            if (!$tax) {
                throw new Site_MCP_Error('Unknown taxonomy.');
            }
            $fields = array_intersect_key($args, array_flip(['name', 'slug', 'description', 'parent']));
            if (!empty($args['id'])) {
                Site_MCP_Content::require_cap('edit_term', (int) $args['id']);
                $result = wp_update_term((int) $args['id'], $tax->name, wp_slash($fields));
            } else {
                if (!current_user_can($tax->cap->edit_terms) || empty($args['name'])) {
                    throw new Site_MCP_Error(empty($args['name']) ? 'name is required to create a term.' : 'Your account is not allowed to create terms here.');
                }
                $result = wp_insert_term(wp_slash($args['name']), $tax->name, wp_slash($fields));
            }
            if (is_wp_error($result)) {
                throw new Site_MCP_Error($result->get_error_message());
            }
            foreach ((array) ($args['meta'] ?? []) as $key => $value) {
                $value === null ? delete_term_meta($result['term_id'], $key) : update_term_meta($result['term_id'], $key, wp_slash($value));
            }
            return ['message' => empty($args['id']) ? 'Created' : 'Updated', 'id' => $result['term_id'], 'link' => get_term_link((int) $result['term_id'], $tax->name)];
        },
    ]);

    Site_MCP_Tools::add('term_delete', [
        'group'       => 'content',
        'destructive' => true,
        'description' => 'Permanently delete a term. Posts keep existing but lose the term.',
        'properties'  => ['taxonomy' => ['type' => 'string'], 'id' => ['type' => 'integer']],
        'required'    => ['taxonomy', 'id'],
        'run'         => function ($args) {
            Site_MCP_Content::require_cap('delete_term', (int) $args['id']);
            $result = wp_delete_term((int) $args['id'], $args['taxonomy']);
            if (!$result || is_wp_error($result)) {
                throw new Site_MCP_Error(is_wp_error($result) ? $result->get_error_message() : 'Term not found or is the default term.');
            }
            return ['message' => 'Deleted', 'id' => (int) $args['id']];
        },
    ]);

    /* ---------- Comments and reviews ---------- */

    Site_MCP_Tools::add('comments_list', [
        'group'       => 'content',
        'readonly'    => true,
        'cap'         => 'moderate_comments',
        'description' => 'List comments and reviews, filtered by post and status.',
        'properties'  => [
            'post_id'  => ['type' => 'integer'],
            'status'   => ['type' => 'string', 'enum' => ['all', 'hold', 'approve', 'spam', 'trash'], 'default' => 'all'],
            'search'   => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 30],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'run'         => function ($args) {
            $per = Site_MCP_Tools::int_arg($args, 'per_page', 30, 1, 200);
            $comments = get_comments([
                'post_id' => (int) ($args['post_id'] ?? 0),
                'status'  => $args['status'] ?? 'all',
                'search'  => $args['search'] ?? '',
                'number'  => $per,
                'offset'  => $per * (Site_MCP_Tools::int_arg($args, 'page', 1, 1, 10000) - 1),
                'type'    => '',
            ]);
            return array_map(function ($c) {
                return ['id' => (int) $c->comment_ID, 'post_id' => (int) $c->comment_post_ID, 'author' => $c->comment_author, 'email' => $c->comment_author_email, 'date' => $c->comment_date, 'status' => wp_get_comment_status($c), 'type' => $c->comment_type, 'parent' => (int) $c->comment_parent, 'content' => $c->comment_content];
            }, $comments);
        },
    ]);

    Site_MCP_Tools::add('comment_save', [
        'group'       => 'content',
        'cap'         => 'moderate_comments',
        'description' => 'Moderate or edit a comment (status approve/hold/spam/trash, content), or reply to one by passing reply_to and content.',
        'properties'  => [
            'id'       => ['type' => 'integer', 'description' => 'Comment to update.'],
            'status'   => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'trash']],
            'content'  => ['type' => 'string'],
            'reply_to' => ['type' => 'integer', 'description' => 'Comment ID to reply to (creates a new approved reply as you).'],
        ],
        'run'         => function ($args) {
            if (!empty($args['reply_to'])) {
                $parent = get_comment((int) $args['reply_to']);
                if (!$parent || empty($args['content'])) {
                    throw new Site_MCP_Error('reply_to must be an existing comment, and content is required.');
                }
                $user = wp_get_current_user();
                $id = wp_insert_comment(wp_slash([
                    'comment_post_ID'      => $parent->comment_post_ID,
                    'comment_parent'       => $parent->comment_ID,
                    'comment_content'      => $args['content'],
                    'user_id'              => $user->ID,
                    'comment_author'       => $user->display_name,
                    'comment_author_email' => $user->user_email,
                    'comment_approved'     => 1,
                ]));
                return ['message' => 'Reply posted', 'id' => $id];
            }
            $comment = get_comment((int) ($args['id'] ?? 0));
            if (!$comment) {
                throw new Site_MCP_Error('Comment not found.');
            }
            if (isset($args['content'])) {
                wp_update_comment(wp_slash(['comment_ID' => $comment->comment_ID, 'comment_content' => $args['content']]));
            }
            if (!empty($args['status'])) {
                wp_set_comment_status($comment->comment_ID, $args['status']);
            }
            return ['message' => 'Updated', 'id' => (int) $comment->comment_ID];
        },
    ]);
});
