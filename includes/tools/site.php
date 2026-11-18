<?php
defined('ABSPATH') || exit;

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('site_overview', [
        'group'       => 'site',
        'readonly'    => true,
        'description' => 'Start here. Site name and URLs, active theme, front page, post types with counts, taxonomies, active plugins, your roles, and which tool groups are enabled.',
        'run'         => function () {
            $types = [];
            foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
                $counts = (array) wp_count_posts($type->name);
                $types[] = [
                    'name'       => $type->name,
                    'label'      => $type->label,
                    'public'     => (bool) $type->public,
                    'counts'     => array_filter(array_map('intval', $counts)),
                    'taxonomies' => get_object_taxonomies($type->name),
                ];
            }
            $taxonomies = [];
            foreach (get_taxonomies(['show_ui' => true], 'objects') as $tax) {
                $taxonomies[] = ['name' => $tax->name, 'label' => $tax->label, 'hierarchical' => (bool) $tax->hierarchical, 'post_types' => $tax->object_type];
            }
            $plugins = [];
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $file => $plugin) {
                if (is_plugin_active($file)) {
                    $plugins[] = $plugin['Name'] . ' ' . $plugin['Version'];
                }
            }
            $theme = wp_get_theme();
            $user = wp_get_current_user();
            return [
                'name'           => get_bloginfo('name'),
                'tagline'        => get_bloginfo('description'),
                'home_url'       => home_url('/'),
                'wordpress'      => get_bloginfo('version'),
                'theme'          => ['name' => $theme->get('Name'), 'slug' => $theme->get_stylesheet(), 'block_theme' => $theme->is_block_theme()],
                'front_page'     => get_option('show_on_front') === 'page' ? ['page_id' => (int) get_option('page_on_front'), 'posts_page_id' => (int) get_option('page_for_posts')] : 'latest posts',
                'permalinks'     => get_option('permalink_structure'),
                'post_types'     => $types,
                'taxonomies'     => $taxonomies,
                'active_plugins' => $plugins,
                'you'            => ['id' => $user->ID, 'name' => $user->display_name, 'roles' => $user->roles],
                'enabled_groups' => Site_MCP_Tools::enabled_groups(),
            ];
        },
    ]);

    $settings = [
        'blogname'        => 'Site title',
        'blogdescription' => 'Tagline',
        'show_on_front'   => '"page" or "posts"',
        'page_on_front'   => 'Front page ID',
        'page_for_posts'  => 'Posts page ID',
        'posts_per_page'  => 'Posts per page',
        'timezone_string' => 'Timezone, e.g. America/Los_Angeles',
        'date_format'     => 'PHP date format',
        'site_icon'       => 'Attachment ID of the site icon',
    ];

    Site_MCP_Tools::add('site_settings_get', [
        'group'       => 'site',
        'readonly'    => true,
        'cap'         => 'manage_options',
        'description' => 'Read the general site settings: ' . implode(', ', array_keys($settings)) . '.',
        'run'         => function () use ($settings) {
            $out = [];
            foreach (array_keys($settings) as $key) {
                $out[$key] = get_option($key);
            }
            return $out;
        },
    ]);

    $props = [];
    foreach ($settings as $key => $label) {
        $props[$key] = ['type' => in_array($key, ['page_on_front', 'page_for_posts', 'posts_per_page', 'site_icon'], true) ? 'integer' : 'string', 'description' => $label];
    }
    Site_MCP_Tools::add('site_settings_update', [
        'group'       => 'site',
        'cap'         => 'manage_options',
        'description' => 'Update general site settings. Only the keys you pass are changed.',
        'properties'  => $props,
        'run'         => function ($args) use ($settings) {
            $changed = [];
            foreach (array_intersect_key($args, $settings) as $key => $value) {
                if ($key === 'show_on_front' && !in_array($value, ['page', 'posts'], true)) {
                    throw new Site_MCP_Error('show_on_front must be "page" or "posts".');
                }
                update_option($key, sanitize_option($key, $value));
                $changed[$key] = get_option($key);
            }
            return ['message' => 'Settings saved', 'changed' => $changed];
        },
    ]);

    Site_MCP_Tools::add('users_list', [
        'group'       => 'site',
        'readonly'    => true,
        'cap'         => 'list_users',
        'description' => 'List user accounts (ID, login, name, email, roles). Read-only: accounts and roles are managed in WordPress admin.',
        'properties'  => [
            'search'   => ['type' => 'string'],
            'role'     => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'run'         => function ($args) {
            $query = new WP_User_Query([
                'search'      => !empty($args['search']) ? '*' . $args['search'] . '*' : '',
                'role'        => $args['role'] ?? '',
                'number'      => Site_MCP_Tools::int_arg($args, 'per_page', 50, 1, 200),
                'paged'       => Site_MCP_Tools::int_arg($args, 'page', 1, 1, 10000),
                'count_total' => true,
            ]);
            return [
                'total' => $query->get_total(),
                'users' => array_map(function ($u) {
                    return ['id' => $u->ID, 'login' => $u->user_login, 'name' => $u->display_name, 'email' => $u->user_email, 'roles' => $u->roles, 'registered' => $u->user_registered];
                }, $query->get_results()),
            ];
        },
    ]);

    Site_MCP_Tools::add('render_page', [
        'group'       => 'site',
        'readonly'    => true,
        'description' => 'Fetch a page of this site as a logged-out visitor sees it, to check a change. Returns the HTTP status, <title> and either readable text (default) or raw HTML.',
        'properties'  => [
            'path'      => ['type' => 'string', 'description' => 'Path or full URL on this site, e.g. "/" or "/shop/".'],
            'format'    => ['type' => 'string', 'enum' => ['text', 'html'], 'default' => 'text'],
            'find'      => ['type' => 'string', 'description' => 'Optional: only return the HTML around the first match of this string (useful with format=html).'],
            'max_chars' => ['type' => 'integer', 'description' => 'Default 15000, or 3000 with find.'],
        ],
        'required'    => ['path'],
        'run'         => function ($args) {
            $home = home_url();
            $path = (string) $args['path'];
            $url = str_starts_with($path, 'http') ? $path : $home . '/' . ltrim($path, '/');
            if (!str_starts_with($url, $home)) {
                throw new Site_MCP_Error('Only pages on this site can be fetched.');
            }
            $response = wp_remote_get(add_query_arg('site_mcp_nocache', time(), $url), ['timeout' => 20, 'redirection' => 3, 'cookies' => []]);
            if (is_wp_error($response)) {
                throw new Site_MCP_Error($response->get_error_message());
            }
            $html = wp_remote_retrieve_body($response);
            $max = Site_MCP_Tools::int_arg($args, 'max_chars', empty($args['find']) ? 15000 : 3000, 500, 200000);
            preg_match('#<title[^>]*>(.*?)</title>#si', $html, $title);
            $out = ['status' => wp_remote_retrieve_response_code($response), 'url' => $url, 'title' => isset($title[1]) ? html_entity_decode(trim($title[1])) : ''];
            if (stripos($html, 'There has been a critical error') !== false) {
                $out['warning'] = 'The page shows the WordPress critical error screen.';
            }
            $body = $html;
            if (!empty($args['find'])) {
                $pos = strpos($html, $args['find']);
                if ($pos === false) {
                    $out['found'] = false;
                    return $out;
                }
                $body = substr($html, max(0, $pos - 500), $max);
            } elseif (($args['format'] ?? 'text') === 'text') {
                $body = preg_replace('#<(script|style|svg|noscript)\b.*?</\1>#si', ' ', $html);
                $body = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/header|/footer)[^>]*>#i', "\n", $body);
                $body = html_entity_decode(wp_strip_all_tags($body, false));
                $body = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n", $body)));
            }
            $out['length'] = strlen($body);
            $out['content'] = substr($body, 0, $max);
            return $out;
        },
    ]);

    Site_MCP_Tools::add('activity_log', [
        'group'       => 'site',
        'readonly'    => true,
        'description' => 'Recent changes and errors made through this server, newest first (who, when, which tool, arguments).',
        'properties'  => [
            'limit'  => ['type' => 'integer', 'default' => 30],
            'offset' => ['type' => 'integer', 'default' => 0],
        ],
        'run'         => function ($args) {
            return Site_MCP_Log::recent(Site_MCP_Tools::int_arg($args, 'limit', 30, 1, 200), Site_MCP_Tools::int_arg($args, 'offset', 0, 0, 100000));
        },
    ]);
});
