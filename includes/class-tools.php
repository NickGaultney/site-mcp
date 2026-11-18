<?php
defined('ABSPATH') || exit;

class Site_MCP_Error extends Exception
{
}

/**
 * Tool registry. Each tool declares its group (which the site owner can switch
 * off), a capability check, and a JSON schema for its arguments.
 */
class Site_MCP_Tools
{
    const GROUPS = [
        'site'      => 'Site overview, settings, users (read), page previews and the activity log',
        'content'   => 'Posts, pages, products and any other post type, taxonomies, comments, revisions',
        'media'     => 'Media library uploads and edits',
        'design'    => 'Templates, template parts, patterns and global styles (saved in the database, never to theme files)',
        'store'     => 'Fluent Cart product pricing, stock and new products',
        'forms'     => 'Fluent Forms forms and submissions',
        'abilities' => 'Abilities registered by other plugins (for example Fluent Cart orders, customers, coupons and reports)',
        'theme'     => 'Theme code: PHP, CSS, JS, theme.json, patterns and template files, with version history and undo (administrators only)',
        'plugins'   => 'Plugins: list, install from WordPress.org, and turn on and off, with the site checked after each change (administrators only)',
        'features'  => 'Site Features: custom functionality such as post types, a CRM or email automations, built as its own plugin so it survives a theme change, with version history and undo (administrators only)',
    ];

    private static $tools = [];
    private static $loaded = false;

    /**
     * @param array $def {
     *   group, description, properties (array), required (string[]),
     *   cap (string capability or callable(array $args): bool),
     *   readonly (bool), destructive (bool), run (callable(array $args): mixed)
     * }
     */
    public static function add($name, array $def)
    {
        self::$tools[$name] = $def + [
            'properties'  => [],
            'required'    => [],
            'cap'         => 'edit_posts',
            'readonly'    => false,
            'destructive' => false,
        ];
    }

    public static function all()
    {
        if (!self::$loaded) {
            self::$loaded = true;
            do_action('site_mcp_register_tools');
        }
        return self::$tools;
    }

    public static function enabled_groups()
    {
        $saved = get_option('site_mcp_groups');
        return is_array($saved) ? $saved : array_keys(self::GROUPS);
    }

    public static function available()
    {
        $groups = self::enabled_groups();
        $out = [];
        foreach (self::all() as $name => $def) {
            if (!in_array($def['group'], $groups, true)) {
                continue;
            }
            // Callable caps depend on arguments, so they are checked at call time.
            if (is_string($def['cap']) && !current_user_can($def['cap'])) {
                continue;
            }
            $out[$name] = $def;
        }
        return $out;
    }

    public static function describe($name, array $def)
    {
        $schema = ['type' => 'object', 'properties' => $def['properties'] ? $def['properties'] : (object) []];
        if ($def['required']) {
            $schema['required'] = $def['required'];
        }
        return [
            'name'        => $name,
            'description' => $def['description'],
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint'    => (bool) $def['readonly'],
                'destructiveHint' => (bool) $def['destructive'],
                'openWorldHint'   => false,
            ],
        ];
    }

    /** Runs a tool and returns an MCP tools/call result. */
    public static function call($name, $args)
    {
        $tools = self::available();
        if (!isset($tools[$name])) {
            return self::result(['error' => "Unknown or disabled tool: $name"], true);
        }
        $def = $tools[$name];
        $args = is_array($args) ? $args : [];

        try {
            foreach ($def['required'] as $key) {
                if (!array_key_exists($key, $args) || $args[$key] === '' || $args[$key] === null) {
                    throw new Site_MCP_Error("Missing required argument: $key");
                }
            }
            if (is_callable($def['cap']) ? !call_user_func($def['cap'], $args) : !current_user_can($def['cap'])) {
                throw new Site_MCP_Error('Your WordPress account is not allowed to do this.');
            }
            $data = call_user_func($def['run'], $args);
            if (is_wp_error($data)) {
                throw new Site_MCP_Error($data->get_error_message());
            }
            if (!$def['readonly']) {
                Site_MCP_Log::add($name, $args, 'ok', self::summarize($data));
            }
            return self::result($data, false);
        } catch (Throwable $e) {
            $message = $e instanceof Site_MCP_Error ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
            Site_MCP_Log::add($name, $args, 'error', $message);
            return self::result(['error' => $message], true);
        }
    }

    private static function result($data, $is_error)
    {
        $text = is_string($data) ? $data : wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return ['content' => [['type' => 'text', 'text' => (string) $text]], 'isError' => $is_error];
    }

    private static function summarize($data)
    {
        if (is_array($data)) {
            if (isset($data['message']) && is_scalar($data['message'])) {
                return $data['message'] . (isset($data['link']) ? ' · ' . $data['link'] : (isset($data['path']) ? ' · ' . $data['path'] : ''));
            }
        }
        return '';
    }

    /* ---------- Helpers shared by tool files ---------- */

    /** Applies [{find, replace, all?}] edits. Each find must match (exactly once unless all=true). */
    public static function apply_edits($content, $edits)
    {
        foreach ((array) $edits as $i => $edit) {
            $find = isset($edit['find']) ? (string) $edit['find'] : '';
            $replace = isset($edit['replace']) ? (string) $edit['replace'] : '';
            if ($find === '') {
                throw new Site_MCP_Error("Edit #$i has an empty 'find'.");
            }
            $count = substr_count($content, $find);
            if ($count === 0) {
                throw new Site_MCP_Error("Edit #$i: 'find' text was not found. Nothing was saved. Fetch the current content and copy the text exactly.");
            }
            if ($count > 1 && empty($edit['all'])) {
                throw new Site_MCP_Error("Edit #$i: 'find' text matches $count times. Add more surrounding text, or set all=true. Nothing was saved.");
            }
            $content = str_replace($find, $replace, $content);
        }
        return $content;
    }

    public static function edits_schema()
    {
        return [
            'type'        => 'array',
            'description' => 'Find-and-replace edits applied to the current content, in order. Use instead of resending the whole content for small changes. Each find must match exactly once unless all=true; if any edit fails nothing is saved.',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'find'    => ['type' => 'string'],
                    'replace' => ['type' => 'string'],
                    'all'     => ['type' => 'boolean', 'description' => 'Replace every occurrence.'],
                ],
                'required'   => ['find', 'replace'],
            ],
        ];
    }

    /** Returns a list of problems with the block comment structure (unclosed or mismatched blocks). */
    public static function block_warnings($content)
    {
        if (!preg_match_all('#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\b(?:[^>]*?)(/)?\s*-->#s', $content, $m, PREG_SET_ORDER)) {
            return [];
        }
        $stack = [];
        $warnings = [];
        foreach ($m as $tag) {
            $closing = $tag[1] === '/';
            $self = isset($tag[3]) && $tag[3] === '/';
            $block = $tag[2];
            if ($self) {
                continue;
            }
            if (!$closing) {
                $stack[] = $block;
                continue;
            }
            $open = array_pop($stack);
            if ($open !== $block) {
                $warnings[] = "Closing wp:$block does not match open wp:" . ($open ?: '(none)');
                if ($open) {
                    $stack[] = $open;
                }
            }
        }
        foreach ($stack as $open) {
            $warnings[] = "Block wp:$open is never closed";
        }
        return $warnings;
    }

    /** Runs an internal REST request as the current user and returns its data, or throws. */
    public static function rest($method, $route, array $params = [])
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);
        if ($response->is_error()) {
            $message = is_array($data) && isset($data['message']) ? $data['message'] : 'REST request failed';
            throw new Site_MCP_Error($message);
        }
        return $data;
    }

    public static function int_arg($args, $key, $default, $min, $max)
    {
        $value = isset($args[$key]) ? (int) $args[$key] : $default;
        return max($min, min($max, $value));
    }
}
