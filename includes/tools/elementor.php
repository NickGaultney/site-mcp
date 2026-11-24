<?php
defined('ABSPATH') || exit;

/**
 * Reading a site built with Elementor, to rebuild it as a theme. Works from
 * Elementor's stored data (post meta and the kit), so it keeps working after
 * Elementor is switched off. Read-only.
 */
class Site_MCP_Elementor
{
    /** Settings that are styling rather than content, left out unless detail=full. */
    const STYLE = '/(color|typography|font|_size|^size|margin|padding|border|radius|shadow|background|^align|_align|width|height|gap|spacing|position|z_index|css_classes|animation|motion|hover|transition|opacity|display|^flex|_flex|direction|justify|wrap|overflow|order|_element_id|hide_|_mobile$|_tablet$|_laptop$|_widescreen$|_mobile_extra$|_tablet_extra$|structure|content_width|boxed_width|column_size|_inline_size|space_between|stretch|min_height|weight|line_height|letter|word_spacing|text_transform|text_decoration|text_shadow|blend|filter|stroke|_offset|_rotate|_scale|_skew|sticky|_position|__dynamic__)/';

    public static function present()
    {
        static $present = null;
        if ($present === null) {
            global $wpdb;
            $present = (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_edit_mode' LIMIT 1");
        }
        return $present;
    }

    public static function data($post_id)
    {
        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    /** Drops empty values: '', [], and Elementor's empty size and dimension objects. */
    public static function clean($value)
    {
        if (!is_array($value)) {
            return $value === '' || $value === null ? null : $value;
        }
        if (array_key_exists('unit', $value) && (($value['size'] ?? null) === '' || ($value['size'] ?? 'x') === null) && !array_filter(array_intersect_key($value, array_flip(['top', 'right', 'bottom', 'left'])), 'strlen')) {
            return null;
        }
        if (isset($value['url']) && $value['url'] === '' && empty($value['id'])) {
            return null;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($k === 'sizes' || $k === 'isLinked' || $k === 'is_external' && !$v || $k === 'nofollow' && !$v || $k === 'custom_attributes' && !$v) {
                continue;
            }
            $v = self::clean($v);
            if ($v !== null && $v !== []) {
                $out[$k] = $v;
            }
        }
        return $out ?: null;
    }

    /** The kit's global colours and fonts, by id, to resolve "globals/colors?id=primary". */
    public static function globals()
    {
        static $globals = null;
        if ($globals !== null) {
            return $globals;
        }
        $globals = ['colors' => [], 'typography' => []];
        $kit = (array) get_post_meta((int) get_option('elementor_active_kit'), '_elementor_page_settings', true);
        foreach (['system_colors', 'custom_colors'] as $key) {
            foreach ((array) ($kit[$key] ?? []) as $c) {
                $globals['colors'][$c['_id'] ?? ''] = ['title' => $c['title'] ?? '', 'color' => $c['color'] ?? ''];
            }
        }
        foreach (['system_typography', 'custom_typography'] as $key) {
            foreach ((array) ($kit[$key] ?? []) as $t) {
                $id = $t['_id'] ?? '';
                unset($t['_id']);
                $globals['typography'][$id] = self::clean($t);
            }
        }
        return $globals;
    }

    /** The devices this site's Elementor designs for: desktop plus the kit's active breakpoints. */
    public static function devices()
    {
        static $devices = null;
        if ($devices === null) {
            $kit = (array) get_post_meta((int) get_option('elementor_active_kit'), '_elementor_page_settings', true);
            $active = !empty($kit['active_breakpoints']) ? (array) $kit['active_breakpoints'] : ['viewport_mobile', 'viewport_tablet'];
            $devices = array_merge(['desktop'], array_map(function ($b) {
                return preg_replace('/^viewport_/', '', $b);
            }, $active));
        }
        return $devices;
    }

    /** "" when an element shows everywhere, else a note naming the devices Elementor hides it on. */
    public static function hidden_note(array $settings)
    {
        $hidden = array_values(array_filter(self::devices(), function ($device) use ($settings) {
            return !empty($settings["hide_$device"]);
        }));
        if (!$hidden) {
            return '';
        }
        return count($hidden) === count(self::devices()) ? ' [HIDDEN ON EVERY DEVICE: never seen, do not rebuild]' : ' [hidden on ' . implode(', ', $hidden) . ']';
    }

    /** How many elements in a page are hidden on every device. */
    public static function hidden_everywhere(array $elements)
    {
        $count = 0;
        foreach ($elements as $el) {
            if (str_starts_with(self::hidden_note((array) ($el['settings'] ?? [])), ' [HIDDEN')) {
                $count++;
            } elseif (!empty($el['elements'])) {
                $count += self::hidden_everywhere($el['elements']);
            }
        }
        return $count;
    }

    private static function resolve_globals(array $refs)
    {
        $globals = self::globals();
        $out = [];
        foreach ($refs as $setting => $ref) {
            if (preg_match('#globals/(colors|typography)\?id=([\w-]+)#', (string) $ref, $m)) {
                $g = $globals[$m[1]][$m[2]] ?? null;
                $out[$setting] = $m[1] === 'colors' ? 'global ' . ($g ? "{$g['title']} {$g['color']}" : $m[2]) : 'global font ' . ($g['title'] ?? $m[2]);
            }
        }
        return $out;
    }

    /**
     * An indented outline of a page's elements, one per line:
     *   container [hidden on mobile] {settings}
     *     heading "About me" {header_size: h1}
     */
    public static function outline(array $elements, $full, $depth = 0)
    {
        $lines = [];
        foreach ($elements as $el) {
            $type = ($el['elType'] ?? '') === 'widget' ? ($el['widgetType'] ?? 'widget') : ($el['elType'] ?? '?');
            $settings = (array) ($el['settings'] ?? []);
            $hidden = self::hidden_note($settings);
            $refs = (array) ($settings['__globals__'] ?? []);
            unset($settings['__globals__']);
            if (!$full) {
                $settings = array_filter($settings, function ($key) {
                    return $key === 'header_size' || !preg_match(self::STYLE, $key);
                }, ARRAY_FILTER_USE_KEY);
            }
            $settings = (array) self::clean($settings);
            if ($full && $refs) {
                $settings = self::resolve_globals($refs) + $settings;
            }
            $text = '';
            foreach (['title', 'editor', 'text', 'html'] as $key) {
                if (isset($settings[$key]) && is_string($settings[$key])) {
                    $text = ' ' . wp_json_encode(trim(preg_replace('/\s+/', ' ', $settings[$key])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    unset($settings[$key]);
                    break;
                }
            }
            $lines[] = str_repeat('  ', $depth) . $type . $text . $hidden . ($settings ? ' ' . wp_json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
            if (!empty($el['elements'])) {
                $lines = array_merge($lines, self::outline($el['elements'], $full, $depth + 1));
            }
        }
        return $lines;
    }

    /** Widget types used in a page, with counts. */
    public static function widgets(array $elements, array &$counts = [])
    {
        foreach ($elements as $el) {
            if (($el['elType'] ?? '') === 'widget') {
                $type = $el['widgetType'] ?? 'widget';
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            if (!empty($el['elements'])) {
                self::widgets($el['elements'], $counts);
            }
        }
        return $counts;
    }

    /** The CSS Elementor generated for a post (or the kit), from uploads/elementor/css. */
    public static function css($post_id)
    {
        $file = wp_upload_dir()['basedir'] . "/elementor/css/post-$post_id.css";
        return is_readable($file) ? (string) file_get_contents($file) : null;
    }
}

add_action('site_mcp_register_tools', function () {
    if (!Site_MCP_Elementor::present()) {
        return;
    }

    Site_MCP_Tools::add('elementor_overview', [
        'group'       => 'content',
        'readonly'    => true,
        'cap'         => 'edit_theme_options',
        'description' => 'For rebuilding an Elementor site as a theme: Elementor and Elementor Pro status, the site kit (global colours, global fonts, layout and button settings, custom CSS), and every page, post and template built with Elementor, with its type, the widgets it uses, and display conditions for headers, footers, popups and other theme-builder templates. Read each one with elementor_page_get.',
        'run'         => function () {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugins = [];
            foreach (get_plugins() as $file => $data) {
                if (str_starts_with($file, 'elementor') || stripos($data['Name'], 'elementor') !== false) {
                    $plugins[] = ['plugin' => dirname($file), 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active($file)];
                }
            }
            $kit_id = (int) get_option('elementor_active_kit');
            $kit = (array) Site_MCP_Elementor::clean((array) get_post_meta($kit_id, '_elementor_page_settings', true));
            unset($kit['system_colors'], $kit['custom_colors'], $kit['system_typography'], $kit['custom_typography']);
            $documents = [];
            foreach (get_posts(['post_type' => 'any', 'post_status' => ['publish', 'private', 'draft', 'future'], 'numberposts' => 500, 'meta_key' => '_elementor_edit_mode', 'meta_value' => 'builder', 'orderby' => 'post_type', 'order' => 'ASC']) as $post) {
                $type = get_post_meta($post->ID, '_elementor_template_type', true);
                if ($type === 'kit') {
                    continue;
                }
                $doc = ['id' => $post->ID, 'title' => $post->post_title, 'post_type' => $post->post_type, 'template_type' => $type, 'status' => $post->post_status];
                if ($post->post_type === 'elementor_library') {
                    $doc['conditions'] = (array) get_post_meta($post->ID, '_elementor_conditions', true);
                } else {
                    $doc['link'] = wp_make_link_relative(get_permalink($post));
                }
                $data = Site_MCP_Elementor::data($post->ID);
                $doc['widgets'] = Site_MCP_Elementor::widgets($data);
                if ($hidden = Site_MCP_Elementor::hidden_everywhere($data)) {
                    $doc['hidden_everywhere'] = $hidden;
                }
                $documents[] = $doc;
            }
            return [
                'plugins'    => $plugins,
                'kit'        => ['id' => $kit_id, 'colors' => Site_MCP_Elementor::globals()['colors'], 'fonts' => Site_MCP_Elementor::globals()['typography'], 'settings' => $kit],
                'documents'  => $documents,
                'notes'      => [
                    'Widgets named form are Elementor Pro forms: rebuild them in Fluent Forms. fluent-form-widget embeds an existing Fluent Form (use its shortcode).',
                    'Theme-builder templates (header, footer, single-post, archive, popup) apply site-wide according to their conditions.',
                    'Generated CSS for any document: elementor_page_get with css=true (the kit\'s CSS is the kit id).',
                    'hidden_everywhere counts elements hidden on every device (' . implode(', ', Site_MCP_Elementor::devices()) . '): visitors never see them, so a rebuild leaves them out. elementor_page_get marks them, and elements hidden on some devices.',
                ],
            ];
        },
    ]);

    Site_MCP_Tools::add('elementor_page_get', [
        'group'       => 'content',
        'readonly'    => true,
        'cap'         => 'edit_theme_options',
        'description' => 'Read one Elementor page or template as an indented outline: every section, container and widget with its text, links, images and settings, marked [hidden on ...] where Elementor hides it on some devices or [HIDDEN ON EVERY DEVICE] where visitors never see it. detail=content (default) leaves out styling; detail=full includes every setting with global colours and fonts resolved. css=true adds the CSS Elementor generated for it. Read these for intent (what hides where, sticky, widget settings); for exact values, measure the rendered page, because kit, widget, responsive and custom CSS layers combine.',
        'properties'  => [
            'id'        => ['type' => 'integer', 'description' => 'Post ID from elementor_overview (a page, a template, or the kit).'],
            'detail'    => ['type' => 'string', 'enum' => ['content', 'full'], 'default' => 'content'],
            'css'       => ['type' => 'boolean'],
            'max_chars' => ['type' => 'integer', 'description' => 'Default 40000.'],
        ],
        'required'    => ['id'],
        'run'         => function ($args) {
            $post = get_post((int) $args['id']);
            if (!$post) {
                throw new Site_MCP_Error('No post with that ID.');
            }
            $data = Site_MCP_Elementor::data($post->ID);
            $max = Site_MCP_Tools::int_arg($args, 'max_chars', 40000, 2000, 300000);
            $outline = implode("\n", Site_MCP_Elementor::outline($data, ($args['detail'] ?? 'content') === 'full'));
            $out = ['id' => $post->ID, 'title' => $post->post_title, 'template_type' => get_post_meta($post->ID, '_elementor_template_type', true)];
            $page = Site_MCP_Elementor::clean((array) get_post_meta($post->ID, '_elementor_page_settings', true));
            if ($page) {
                $out['page_settings'] = $page;
            }
            if (!$data && !$page) {
                $out['warning'] = 'This post has no Elementor data.';
            }
            if (strlen($outline) > $max) {
                $out['truncated'] = 'The outline is ' . strlen($outline) . " characters; showing the first $max. Pass a larger max_chars, or detail=content.";
                $outline = substr($outline, 0, $max);
            }
            $out['outline'] = $outline;
            if (!empty($args['css'])) {
                $css = Site_MCP_Elementor::css($post->ID);
                $out['css'] = $css === null ? 'No generated CSS file (Elementor may print CSS inline, or it was never generated).' : substr($css, 0, $max);
            }
            return $out;
        },
    ]);
});
