<?php
defined('ABSPATH') || exit;

/**
 * MCP over Streamable HTTP (JSON responses only, no SSE) at /wp-json/site-mcp/v1/mcp.
 * Requests authenticate with an OAuth bearer token (see Site_MCP_OAuth) or a
 * WordPress application password, and the user must have been granted access
 * on Settings → Site MCP.
 */
class Site_MCP_Server
{
    const PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public static function boot()
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'challenge'], 10, 3);
    }

    /** Points unauthenticated clients at the OAuth metadata (MCP authorization spec). */
    public static function challenge($response, $server, $request)
    {
        if ($request->get_route() === '/site-mcp/v1/mcp' && $response->get_status() === 401) {
            $response->header('WWW-Authenticate', sprintf('Bearer resource_metadata="%s", scope="%s"', Site_MCP_OAuth::resource_metadata_url(), Site_MCP_OAuth::SCOPE));
        }
        return $response;
    }

    public static function endpoint()
    {
        return rest_url('site-mcp/v1/mcp');
    }

    public static function routes()
    {
        register_rest_route('site-mcp/v1', '/mcp', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle'],
                'permission_callback' => [__CLASS__, 'authorize'],
            ],
            [
                // No server-initiated streams. 405 tells clients not to open one.
                'methods'             => 'GET, DELETE',
                'callback'            => function () {
                    $response = new WP_REST_Response(['message' => 'Use POST.'], 405);
                    $response->header('Allow', 'POST');
                    return $response;
                },
                'permission_callback' => '__return_true',
            ],
        ]);
        // Used by the connection check on Settings → Site MCP: does the Authorization header reach PHP?
        register_rest_route('site-mcp/v1', '/check', [
            'methods'             => 'GET',
            'callback'            => function () {
                return ['authorization' => Site_MCP_OAuth::authorization_header() === 'Bearer site-mcp-check'];
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public static function user_has_access($user_id)
    {
        return (bool) get_user_meta($user_id, 'site_mcp_access', true) && user_can($user_id, 'edit_posts');
    }

    public static function authorize()
    {
        if (!is_user_logged_in()) {
            return new WP_Error('site_mcp_unauthorized', 'Sign in with OAuth, or use an application password (Authorization: Basic).', ['status' => 401]);
        }
        // Cookie sessions are not accepted: only a bearer token or an application password.
        if (!Site_MCP_OAuth::$grant_id && !rest_get_authenticated_app_password()) {
            return new WP_Error('site_mcp_token_required', 'This endpoint needs an OAuth bearer token or an application password.', ['status' => 401]);
        }
        if (!self::user_has_access(get_current_user_id())) {
            return new WP_Error('site_mcp_forbidden', 'This account has not been given Site MCP access.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $request)
    {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) {
            return new WP_REST_Response(self::error(null, -32700, 'Parse error'), 400);
        }

        $is_batch = array_is_list($body);
        $messages = $is_batch ? $body : [$body];
        $responses = [];
        foreach ($messages as $message) {
            $reply = self::dispatch(is_array($message) ? $message : []);
            if ($reply !== null) {
                $responses[] = $reply;
            }
        }

        if (!$responses) {
            // Only notifications: 202 with no body.
            add_filter('rest_pre_serve_request', [__CLASS__, 'empty_body'], 10, 2);
            return new WP_REST_Response(null, 202);
        }
        return new WP_REST_Response($is_batch ? $responses : $responses[0], 200);
    }

    public static function empty_body($served, $result)
    {
        return $result->get_status() === 202 ? true : $served;
    }

    private static function dispatch(array $message)
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        if (!array_key_exists('id', $message)) {
            return null; // notification
        }

        switch ($method) {
            case 'initialize':
                $asked = $params['protocolVersion'] ?? '';
                return self::ok($id, [
                    'protocolVersion' => in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[0],
                    'capabilities'    => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'site-mcp-' . sanitize_title(get_bloginfo('name')), 'version' => SITE_MCP_VERSION],
                    'instructions'    => self::instructions(),
                ]);
            case 'ping':
                return self::ok($id, (object) []);
            case 'tools/list':
                $tools = [];
                foreach (Site_MCP_Tools::available() as $name => $def) {
                    $tools[] = Site_MCP_Tools::describe($name, $def);
                }
                return self::ok($id, ['tools' => $tools]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                return self::ok($id, Site_MCP_Tools::call($name, $params['arguments'] ?? []));
            case 'resources/list':
                return self::ok($id, ['resources' => []]);
            case 'prompts/list':
                return self::ok($id, ['prompts' => array_values(array_map(function ($p) {
                    return array_diff_key($p, ['text' => 1]);
                }, self::prompts()))]);
            case 'prompts/get':
                $prompt = self::prompts()[(string) ($params['name'] ?? '')] ?? null;
                if (!$prompt) {
                    return self::error($id, -32602, 'Unknown prompt.');
                }
                $notes = trim((string) ($params['arguments']['notes'] ?? ''));
                return self::ok($id, ['description' => $prompt['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $prompt['text'] . ($notes ? "\n\n# From the user\n\n$notes" : '')]]]]);
            default:
                return self::error($id, -32601, "Method not found: $method");
        }
    }

    private static function ok($id, $result)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error($id, $code, $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** Prompts this user can run: the site rebuild playbook, for administrators who can build themes. */
    private static function prompts()
    {
        $code = Site_MCP_Code::theme();
        if (!in_array('theme', Site_MCP_Tools::enabled_groups(), true) || !current_user_can('install_themes') || $code->unavailable()) {
            return [];
        }
        return ['rebuild_site' => [
            'name'        => 'rebuild_site',
            'title'       => 'Rebuild this site as a fast custom theme',
            'description' => 'Replace a page builder (such as Elementor) and its theme with a hand-written theme: inventory, faithful copy, modern design, speed, then a reversible switch. The live site is untouched until the owner approves the switch.',
            'arguments'   => [['name' => 'notes', 'description' => 'Anything the owner wants: design wishes, pages to drop, deadlines.', 'required' => false]],
            'text'        => self::rebuild_text(),
        ]];
    }

    /** The rebuild playbook, served as the rebuild_site prompt and by the rebuild_guide tool. */
    public static function rebuild_text()
    {
        return (string) file_get_contents(__DIR__ . '/rebuild.md');
    }

    public static function instructions()
    {
        $site = get_bloginfo('name');
        $text = <<<TXT
You are connected to the live WordPress site "$site" (%s). Changes take effect immediately for visitors.

- Start with site_overview to learn the post types, theme and enabled tools.
- Content is WordPress block markup (<!-- wp:paragraph --> ...). Keep block comments balanced and valid; tools warn if they are not.
- For small changes, pass find/replace "edits" instead of resending the whole content.
%s
- Posts, pages and templates keep revisions. Use post_revisions and post_revision_restore to undo.
- After a visible change, check the page with render_page.
- Every change is recorded in an activity log the site owner can review.
TXT;
        $can = function (Site_MCP_Code $code) {
            return in_array($code->key, Site_MCP_Tools::enabled_groups(), true) && current_user_can($code->cap) && !$code->unavailable();
        };
        $theme = $can(Site_MCP_Code::theme())
            ? '- Theme code (PHP, CSS, JS, theme.json, patterns and template files) is edited with the theme_file_* tools. Each save is syntax-checked and the site is loaded afterwards; a change that breaks a page is rolled back automatically. Every save becomes a version in the theme\'s history with your message, so write a clear one. theme_history and theme_diff show what changed; theme_revert undoes one version; theme_restore puts files back to an earlier version. Change related files in one theme_file_write call (files) so they become one version.'
            : '- Theme code (PHP, CSS, JS and the template files themselves) is not editable with your account or on this server.';
        if ($can(Site_MCP_Code::features())) {
            $theme .= <<<'TXT'

- New functionality (custom post types and fields, a CRM, email automations, integrations, admin screens, shortcodes, REST endpoints) goes in the Site Features plugin with the feature_* tools, never in the theme, so it keeps working if the theme changes. The theme is only for how the site looks. Check feature_list first. They work like the theme tools: syntax check, health check, automatic rollback and history.
  - Each feature is a folder features/<slug>/ whose feature.php is loaded on every request. Start feature.php with a header comment containing "Feature: <Name>" and "Description: <one line>", and keep the rest of the feature's files in its folder (SITE_FEATURES_DIR and SITE_FEATURES_URL point at features/).
  - Prefix every function, class, constant, hook, option, post type and table with the feature's slug, so features never collide with each other or with plugins.
  - Features have no activation hook. Create or change database tables with dbDelta behind a stored schema version, checked on admin_init and init. Rewrite rules are flushed automatically after each change.
  - Prefer the site's existing plugins (Fluent Cart, Fluent Forms, and FluentCRM or FluentSMTP if installed) and their hooks over rebuilding what they already do. Before building a large system such as a CRM or email marketing from scratch, say whether an established plugin would serve the owner better.
  - Scheduled tasks use WP-Cron, which only runs when the site gets visits unless the host runs it on a timer, and email goes out through wp_mail, which needs an SMTP or email service plugin to reliably reach inboxes. Tell the owner when a feature depends on either.
  - Pass check_paths for the admin screens, REST routes and pages a feature adds, so they are tested too.
TXT;
        }
        $layout = wp_is_block_theme()
            ? '- Templates, template parts and global styles edited here are saved to the database and override the theme\'s files. template_reset removes the override and restores the theme file version.'
            : '- This site uses a classic PHP theme: page layouts, and much of the copy on pages like the home page, are PHP templates in the theme, changed with the theme_file_* tools. Posts are content.';
        if ($can(Site_MCP_Code::theme()) && is_file(Site_MCP_Code::theme()->root() . '/README.md')) {
            $layout .= ' The theme has a README.md explaining how it is organised: read it (theme_file_read) before changing the theme.';
        }
        if ($can(Site_MCP_Code::theme()) && current_user_can('install_themes')) {
            $layout .= "\n- To rebuild the site in a new theme (for example to replace Elementor), first call rebuild_guide and follow it: build beside the live theme with theme_create, preview with theme_preview_link, and switch with theme_activate only when the owner says so.";
        }
        $standards = '';
        $site_tools = in_array('site', Site_MCP_Tools::enabled_groups(), true);
        if ($site_tools && ($guides = Site_MCP_Guides::applicable())) {
            $standards .= "\n- Site MCP has standard guides for this site: " . implode('; ', array_map(function ($g) {
                return "{$g['title']} (guide topic {$g['topic']})";
            }, $guides)) . '. Read the matching guide with the guide tool before working in its area, and follow it unless the owner wants otherwise.';
        }
        if ($site_tools && Site_MCP_Share::enabled()) {
            $standards .= "\n- The owner shares improvements with Site MCP's maintainer, so every site can get them. When you finish something another WordPress site could reuse and have checked that it works, call suggest_standard once: styling for a plugin's own pages (such as WooCommerce), a tuned plugin configuration (such as LiteSpeed Cache), a fix for a plugin conflict, or a performance, accessibility or security improvement. Not brand colours, copy or anything only this site needs, and never passwords, keys or customer data. Tell the owner what you shared.";
        }
        $text = sprintf($text, home_url('/'), $layout . "\n" . $theme . $standards);
        $extra = trim((string) get_option('site_mcp_instructions', ''));
        return $extra ? $text . "\n\nNotes from the site owner:\n" . $extra : $text;
    }
}
