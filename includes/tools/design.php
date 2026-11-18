<?php
defined('ABSPATH') || exit;

class Site_MCP_Design
{
    public static function route($type)
    {
        return $type === 'part' ? '/wp/v2/template-parts' : '/wp/v2/templates';
    }

    /** Finds a template or template part by id ("theme//slug") and returns [type, data]. */
    public static function find($id, $type = '')
    {
        $types = $type ? [$type] : ['template', 'part'];
        foreach ($types as $t) {
            try {
                return [$t, Site_MCP_Tools::rest('GET', self::route($t) . '/' . $id, ['context' => 'edit'])];
            } catch (Site_MCP_Error $e) {
                continue;
            }
        }
        throw new Site_MCP_Error("No template or template part with id $id. Use templates_list for ids like \"" . get_stylesheet() . '//front-page".');
    }

    public static function summary(array $t, $type)
    {
        return [
            'id'             => $t['id'],
            'type'           => $type,
            'slug'           => $t['slug'],
            'title'          => is_array($t['title']) ? ($t['title']['raw'] ?? $t['title']['rendered'] ?? '') : $t['title'],
            'description'    => $t['description'] ?? '',
            'area'           => $t['area'] ?? null,
            'source'         => $t['source'],
            'customized'     => $t['source'] === 'custom',
            'has_theme_file' => !empty($t['has_theme_file']),
            'wp_id'          => $t['wp_id'] ?? null,
            'modified'       => $t['modified'] ?? null,
        ];
    }

    /** Deep merge where lists are replaced wholesale and null removes a key. */
    public static function merge($base, $changes)
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } elseif (is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

add_action('site_mcp_register_tools', function () {
    // Templates, patterns and global styles only exist in block themes.
    if (!wp_is_block_theme()) {
        return;
    }
    $type_prop = ['type' => 'string', 'enum' => ['template', 'part'], 'description' => 'template (whole page layouts) or part (header, footer…). Detected from the id if omitted.'];

    Site_MCP_Tools::add('templates_list', [
        'group'       => 'design',
        'readonly'    => true,
        'cap'         => 'edit_theme_options',
        'description' => 'List the block templates (front-page, page, single, archive, single-fluent-products…) and template parts (header, footer) the site uses. customized=true means a database copy overrides the theme file.',
        'properties'  => ['type' => ['type' => 'string', 'enum' => ['template', 'part', 'all'], 'default' => 'all']],
        'run'         => function ($args) {
            $out = [];
            foreach (['template', 'part'] as $type) {
                if (($args['type'] ?? 'all') !== 'all' && $args['type'] !== $type) {
                    continue;
                }
                foreach (Site_MCP_Tools::rest('GET', Site_MCP_Design::route($type), ['context' => 'edit']) as $t) {
                    $out[] = Site_MCP_Design::summary($t, $type);
                }
            }
            return $out;
        },
    ]);

    Site_MCP_Tools::add('template_get', [
        'group'       => 'design',
        'readonly'    => true,
        'cap'         => 'edit_theme_options',
        'description' => 'Get a template or template part\'s block markup as currently used on the site. Pattern references like <!-- wp:pattern {"slug":"..."} /--> can be expanded with pattern_get. Set theme_file=true to also get the theme file version (when customized).',
        'properties'  => [
            'id'         => ['type' => 'string', 'description' => 'e.g. "' . get_stylesheet() . '//front-page" or "' . get_stylesheet() . '//header".'],
            'type'       => $type_prop,
            'theme_file' => ['type' => 'boolean', 'default' => false],
        ],
        'required'    => ['id'],
        'run'         => function ($args) {
            [$type, $t] = Site_MCP_Design::find($args['id'], $args['type'] ?? '');
            $out = Site_MCP_Design::summary($t, $type) + ['content' => $t['content']['raw'] ?? ''];
            if (!empty($args['theme_file']) && !empty($t['has_theme_file'])) {
                $file = get_block_file_template($t['id'], $type === 'part' ? 'wp_template_part' : 'wp_template');
                $out['theme_file_content'] = $file ? $file->content : null;
            }
            return $out;
        },
    ]);

    Site_MCP_Tools::add('template_update', [
        'group'       => 'design',
        'cap'         => 'edit_theme_options',
        'description' => 'Change a template or template part (e.g. the header, footer or product page layout). Saved as a database override of the theme file, with revisions; the theme files are not touched. Pass full content or find/replace edits.',
        'properties'  => [
            'id'          => ['type' => 'string'],
            'type'        => $type_prop,
            'content'     => ['type' => 'string', 'description' => 'Full block markup.'],
            'edits'       => Site_MCP_Tools::edits_schema(),
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ],
        'required'    => ['id'],
        'run'         => function ($args) {
            [$type, $t] = Site_MCP_Design::find($args['id'], $args['type'] ?? '');
            $params = array_intersect_key($args, array_flip(['title', 'description']));
            if (isset($args['content']) || !empty($args['edits'])) {
                $content = $args['content'] ?? ($t['content']['raw'] ?? '');
                if (!empty($args['edits'])) {
                    $content = Site_MCP_Tools::apply_edits($content, $args['edits']);
                }
                $params['content'] = $content;
            }
            if (!$params) {
                throw new Site_MCP_Error('Nothing to change: pass content, edits, title or description.');
            }
            $saved = Site_MCP_Tools::rest('POST', Site_MCP_Design::route($type) . '/' . $t['id'], $params);
            $out = ['message' => 'Saved as a database override', 'id' => $saved['id'], 'wp_id' => $saved['wp_id']];
            if (isset($params['content']) && ($warnings = Site_MCP_Tools::block_warnings($params['content']))) {
                $out['block_warnings'] = $warnings;
            }
            return $out;
        },
    ]);

    Site_MCP_Tools::add('template_create', [
        'group'       => 'design',
        'cap'         => 'edit_theme_options',
        'description' => 'Create a new template or template part. Template slugs follow the WordPress hierarchy (e.g. "page-about" applies to the page with slug about, "single-post-holiday" etc.), or use any slug and assign it to pages with post_update template=<slug>.',
        'properties'  => [
            'type'        => ['type' => 'string', 'enum' => ['template', 'part']],
            'slug'        => ['type' => 'string'],
            'title'       => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'content'     => ['type' => 'string'],
            'area'        => ['type' => 'string', 'enum' => ['header', 'footer', 'uncategorized'], 'description' => 'Template parts only.'],
        ],
        'required'    => ['type', 'slug', 'title', 'content'],
        'run'         => function ($args) {
            $params = array_intersect_key($args, array_flip(['slug', 'title', 'description', 'content']));
            if ($args['type'] === 'part') {
                $params['area'] = $args['area'] ?? 'uncategorized';
            }
            $saved = Site_MCP_Tools::rest('POST', Site_MCP_Design::route($args['type']), $params);
            return ['message' => 'Created', 'id' => $saved['id'], 'wp_id' => $saved['wp_id']];
        },
    ]);

    Site_MCP_Tools::add('template_reset', [
        'group'       => 'design',
        'cap'         => 'edit_theme_options',
        'destructive' => true,
        'description' => 'Remove a template\'s database override so the theme file version is used again. For a template that only exists in the database (no theme file), this deletes it.',
        'properties'  => ['id' => ['type' => 'string'], 'type' => $type_prop],
        'required'    => ['id'],
        'run'         => function ($args) {
            [$type, $t] = Site_MCP_Design::find($args['id'], $args['type'] ?? '');
            if ($t['source'] !== 'custom') {
                return ['message' => 'Not customized; already using the theme file.', 'id' => $t['id']];
            }
            Site_MCP_Tools::rest('DELETE', Site_MCP_Design::route($type) . '/' . $t['id'], ['force' => true]);
            return ['message' => !empty($t['has_theme_file']) ? 'Reset to the theme file' : 'Deleted', 'id' => $t['id']];
        },
    ]);

    Site_MCP_Tools::add('patterns_list', [
        'group'       => 'design',
        'readonly'    => true,
        'cap'         => 'edit_posts',
        'description' => 'List registered block patterns (theme sections like the hero or product grid). Theme patterns are read-only code; to customize one, copy its markup into the page or template in place of the <!-- wp:pattern --> reference, or save it as a synced pattern (post_create post_type=wp_block).',
        'properties'  => ['search' => ['type' => 'string'], 'category' => ['type' => 'string']],
        'run'         => function ($args) {
            $out = [];
            foreach (WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p) {
                if (!empty($args['category']) && !in_array($args['category'], $p['categories'] ?? [], true)) {
                    continue;
                }
                if (!empty($args['search']) && stripos($p['name'] . ' ' . $p['title'], $args['search']) === false) {
                    continue;
                }
                $out[] = ['name' => $p['name'], 'title' => $p['title'], 'categories' => $p['categories'] ?? [], 'description' => $p['description'] ?? ''];
            }
            return $out;
        },
    ]);

    Site_MCP_Tools::add('pattern_get', [
        'group'       => 'design',
        'readonly'    => true,
        'cap'         => 'edit_posts',
        'description' => 'Get a registered pattern\'s block markup (as rendered from the theme).',
        'properties'  => ['name' => ['type' => 'string', 'description' => 'e.g. "' . get_stylesheet() . '/hero".']],
        'required'    => ['name'],
        'run'         => function ($args) {
            $p = WP_Block_Patterns_Registry::get_instance()->get_registered($args['name']);
            if (!$p) {
                throw new Site_MCP_Error('Pattern not found.');
            }
            return ['name' => $p['name'], 'title' => $p['title'], 'content' => $p['content']];
        },
    ]);

    Site_MCP_Tools::add('global_styles_get', [
        'group'       => 'design',
        'readonly'    => true,
        'cap'         => 'edit_theme_options',
        'description' => 'Get the site-wide style customizations (theme.json-shaped settings and styles, plus custom CSS in styles.css) and a summary of the theme\'s palette, fonts and sizes. Set include_theme=true for the full theme.json base.',
        'properties'  => ['include_theme' => ['type' => 'boolean', 'default' => false]],
        'run'         => function ($args) {
            $id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
            $user = Site_MCP_Tools::rest('GET', '/wp/v2/global-styles/' . $id, ['context' => 'edit']);
            $settings = wp_get_global_settings();
            $out = [
                'id'             => $id,
                'customizations' => ['settings' => $user['settings'] ?: (object) [], 'styles' => $user['styles'] ?: (object) []],
                'effective'      => [
                    'palette'    => $settings['color']['palette'] ?? [],
                    'fonts'      => array_map(function ($f) {
                        return ['slug' => $f['slug'], 'name' => $f['name'] ?? '', 'fontFamily' => $f['fontFamily']];
                    }, array_merge(...array_values($settings['typography']['fontFamilies'] ?? [[]]))),
                    'font_sizes' => $settings['typography']['fontSizes'] ?? [],
                    'spacing'    => $settings['spacing']['spacingSizes'] ?? [],
                    'layout'     => $settings['layout'] ?? [],
                ],
            ];
            if (!empty($args['include_theme'])) {
                $out['theme'] = Site_MCP_Tools::rest('GET', '/wp/v2/global-styles/themes/' . get_stylesheet(), ['context' => 'edit']);
            }
            return $out;
        },
    ]);

    Site_MCP_Tools::add('global_styles_update', [
        'group'       => 'design',
        'cap'         => 'edit_theme_options',
        'description' => 'Change site-wide styles. settings and styles are deep-merged into the current customizations (lists like a palette are replaced whole, null removes a key). To recolor theme colors set settings.color.palette.theme to the full list. css sets the site\'s custom CSS (replaces it; use css_edits to change part of it). Saved with revisions.',
        'properties'  => [
            'settings'  => ['type' => 'object'],
            'styles'    => ['type' => 'object', 'description' => 'e.g. {"color": {"background": "var(--wp--preset--color--cream)"}, "elements": {"link": {...}}, "blocks": {"core/button": {...}}}'],
            'css'       => ['type' => 'string'],
            'css_edits' => Site_MCP_Tools::edits_schema(),
            'replace'   => ['type' => 'boolean', 'default' => false, 'description' => 'Replace customizations instead of merging.'],
        ],
        'run'         => function ($args) {
            $id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
            $current = Site_MCP_Tools::rest('GET', '/wp/v2/global-styles/' . $id, ['context' => 'edit']);
            $settings = (array) ($current['settings'] ?: []);
            $styles = (array) ($current['styles'] ?: []);
            if (isset($args['settings'])) {
                $settings = !empty($args['replace']) ? (array) $args['settings'] : Site_MCP_Design::merge($settings, (array) $args['settings']);
            }
            if (isset($args['styles'])) {
                $styles = !empty($args['replace']) ? (array) $args['styles'] : Site_MCP_Design::merge($styles, (array) $args['styles']);
            }
            if (isset($args['css'])) {
                $styles['css'] = $args['css'];
            }
            if (!empty($args['css_edits'])) {
                $styles['css'] = Site_MCP_Tools::apply_edits($styles['css'] ?? '', $args['css_edits']);
            }
            Site_MCP_Tools::rest('POST', '/wp/v2/global-styles/' . $id, ['settings' => $settings ?: (object) [], 'styles' => $styles ?: (object) []]);
            return ['message' => 'Global styles saved', 'id' => $id];
        },
    ]);
});
