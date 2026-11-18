<?php
defined('ABSPATH') || exit;

/**
 * OAuth 2.1 for the MCP endpoint, as the MCP authorization spec expects, so the
 * server can be added as a custom connector in Claude (web, desktop, mobile) or
 * in Claude Code without pasting a password.
 *
 * Discovery: /.well-known/oauth-protected-resource and /.well-known/oauth-authorization-server
 * Clients:   dynamic registration (RFC 7591) or client ID metadata documents (an https client_id)
 * Flow:      authorization code + PKCE (S256), with rotating refresh tokens
 *
 * Every authorization is a "grant" (one connected app for one user) that the
 * site owner can see and revoke on Settings → Site MCP.
 */
class Site_MCP_OAuth
{
    const DB_VERSION = '1';
    const SCOPE = 'mcp';
    const CODE_TTL = 600;
    const ACCESS_TTL = HOUR_IN_SECONDS;
    const REFRESH_TTL = 60 * DAY_IN_SECONDS;

    /** Grant ID behind the current request's bearer token. */
    public static $grant_id = 0;

    public static function boot()
    {
        self::maybe_install();
        add_filter('determine_current_user', [__CLASS__, 'bearer_user'], 30);
        add_action('init', [__CLASS__, 'route'], 1);
    }

    /* ---------- Storage ---------- */

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'site_mcp_oauth_' . $name;
    }

    private static function maybe_install()
    {
        if (get_option('site_mcp_oauth_db') === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('clients') . " (
            client_id varchar(255) NOT NULL,
            client_name varchar(255) NOT NULL DEFAULT '',
            redirect_uris text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (client_id)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('grants') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(255) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            last_used datetime NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('tokens') . " (
            token_hash char(64) NOT NULL,
            type varchar(10) NOT NULL,
            grant_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL,
            client_id varchar(255) NOT NULL,
            data text NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY grant_id (grant_id),
            KEY expires_at (expires_at)
        ) $charset;");
        update_option('site_mcp_oauth_db', self::DB_VERSION);
    }

    private static function new_token($type, $user_id, $client_id, $grant_id, $ttl, array $data = [])
    {
        global $wpdb;
        $token = 'smcp_' . $type[0] . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $wpdb->insert(self::table('tokens'), [
            'token_hash' => hash('sha256', $token),
            'type'       => $type,
            'grant_id'   => $grant_id,
            'user_id'    => $user_id,
            'client_id'  => $client_id,
            'data'       => wp_json_encode($data),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
        ]);
        return $token;
    }

    /** Returns the stored row for an unexpired token of the given type, or null. */
    private static function find_token($token, $type)
    {
        global $wpdb;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('tokens') . ' WHERE token_hash = %s AND type = %s AND expires_at > %s', hash('sha256', $token), $type, gmdate('Y-m-d H:i:s')), ARRAY_A);
        if ($row) {
            $row['data'] = json_decode($row['data'], true) ?: [];
        }
        return $row;
    }

    private static function delete_token($token)
    {
        global $wpdb;
        $wpdb->delete(self::table('tokens'), ['token_hash' => hash('sha256', $token)]);
    }

    private static function cleanup()
    {
        global $wpdb;
        if (wp_rand(1, 20) === 1) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('tokens') . ' WHERE expires_at < %s', gmdate('Y-m-d H:i:s')));
            // Grants whose refresh token expired are dead connections.
            $wpdb->query('DELETE g FROM ' . self::table('grants') . ' g LEFT JOIN ' . self::table('tokens') . " t ON t.grant_id = g.id AND t.type = 'refresh' WHERE t.token_hash IS NULL AND g.created_at < '" . gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS) . "'");
        }
    }

    /** Connected apps for a user: [{id, client, created_at, last_used}]. */
    public static function grants_for($user_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT g.*, c.client_name FROM ' . self::table('grants') . ' g LEFT JOIN ' . self::table('clients') . ' c ON c.client_id = g.client_id WHERE g.user_id = %d ORDER BY g.id DESC', $user_id), ARRAY_A);
        foreach ($rows as &$row) {
            $row['client'] = self::client_label($row['client_id'], $row['client_name']);
        }
        return $rows;
    }

    public static function revoke_user($user_id)
    {
        global $wpdb;
        $wpdb->delete(self::table('tokens'), ['user_id' => $user_id]);
        $wpdb->delete(self::table('grants'), ['user_id' => $user_id]);
    }

    /* ---------- URLs ---------- */

    public static function issuer()
    {
        return untrailingslashit(home_url());
    }

    public static function url($endpoint)
    {
        return self::issuer() . '/site-mcp/oauth/' . $endpoint;
    }

    public static function resource_metadata_url()
    {
        return self::issuer() . '/.well-known/oauth-protected-resource' . wp_parse_url(Site_MCP_Server::endpoint(), PHP_URL_PATH);
    }

    /* ---------- Bearer authentication for the MCP endpoint ---------- */

    public static function bearer_user($user_id)
    {
        if ($user_id || !self::is_mcp_request()) {
            return $user_id;
        }
        $header = self::authorization_header();
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $user_id;
        }
        $row = self::find_token($m[1], 'access');
        if (!$row) {
            return $user_id;
        }
        self::$grant_id = (int) $row['grant_id'];
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('grants') . ' SET last_used = %s WHERE id = %d AND (last_used IS NULL OR last_used < %s)', gmdate('Y-m-d H:i:s'), self::$grant_id, gmdate('Y-m-d H:i:s', time() - 60)));
        return (int) $row['user_id'];
    }

    /** Some Apache and CGI setups move or drop the Authorization header; look everywhere it can be. */
    public static function authorization_header()
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function is_mcp_request()
    {
        $route = '/site-mcp/v1/mcp';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains(wp_parse_url($uri, PHP_URL_PATH) ?? '', $route) || (isset($_GET['rest_route']) && $_GET['rest_route'] === $route);
    }

    /* ---------- Routing ---------- */

    public static function route()
    {
        $path = '/' . trim(wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $base = '/' . trim(wp_parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = '/' . ltrim(substr($path, strlen($base)), '/');
        }

        if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
            self::json(self::protected_resource_metadata());
        }
        if (str_starts_with($path, '/.well-known/oauth-authorization-server') || str_starts_with($path, '/.well-known/openid-configuration')) {
            self::json(self::authorization_server_metadata());
        }
        switch ($path) {
            case '/site-mcp/oauth/register':
                self::register();
                break;
            case '/site-mcp/oauth/authorize':
                self::authorize();
                break;
            case '/site-mcp/oauth/token':
                self::token();
                break;
        }
    }

    private static function json($data, $status = 200)
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            echo wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private static function oauth_error($error, $description, $status = 400)
    {
        self::json(['error' => $error, 'error_description' => $description], $status);
    }

    public static function protected_resource_metadata()
    {
        return [
            'resource'                 => Site_MCP_Server::endpoint(),
            'authorization_servers'    => [self::issuer()],
            'scopes_supported'         => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => get_bloginfo('name'),
        ];
    }

    public static function authorization_server_metadata()
    {
        return [
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => self::url('authorize'),
            'token_endpoint'                        => self::url('token'),
            'registration_endpoint'                 => self::url('register'),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported'                      => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
        ];
    }

    /** Request parameters from a form-encoded or JSON body. */
    private static function body()
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $data = json_decode(file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return wp_unslash($_POST);
    }

    /* ---------- Clients ---------- */

    private static function valid_redirect_uri($uri)
    {
        $parts = wp_parse_url($uri);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        return $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private static function register()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::json(null);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::oauth_error('invalid_request', 'Use POST.', 405);
        }
        $req = self::body();
        $uris = array_values(array_filter((array) ($req['redirect_uris'] ?? []), 'is_string'));
        if (!$uris || count($uris) > 10) {
            self::oauth_error('invalid_redirect_uri', 'Provide 1 to 10 redirect_uris.');
        }
        foreach ($uris as $uri) {
            if (!self::valid_redirect_uri($uri)) {
                self::oauth_error('invalid_redirect_uri', "Redirect URI must be https, or http on localhost: $uri");
            }
        }
        global $wpdb;
        $client_id = 'smcp_c_' . bin2hex(random_bytes(16));
        $name = sanitize_text_field(substr((string) ($req['client_name'] ?? ''), 0, 120));
        $wpdb->insert(self::table('clients'), [
            'client_id'     => $client_id,
            'client_name'   => $name,
            'redirect_uris' => wp_json_encode($uris),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::json([
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => $uris,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** Returns ['name' => ..., 'redirect_uris' => [...]] for a registered or metadata-document client, or null. */
    private static function client($client_id)
    {
        if (!is_string($client_id) || $client_id === '') {
            return null;
        }
        if (str_starts_with($client_id, 'https://')) {
            return self::metadata_document_client($client_id);
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('clients') . ' WHERE client_id = %s', $client_id), ARRAY_A);
        return $row ? ['name' => $row['client_name'], 'redirect_uris' => json_decode($row['redirect_uris'], true) ?: []] : null;
    }

    private static function metadata_document_client($url)
    {
        $key = 'site_mcp_cimd_' . md5($url);
        $doc = get_transient($key);
        if ($doc === false) {
            $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 65536]);
            $doc = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ? [] : (json_decode(wp_remote_retrieve_body($response), true) ?: []);
            set_transient($key, $doc, $doc ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        }
        if (($doc['client_id'] ?? '') !== $url || empty($doc['redirect_uris'])) {
            return null;
        }
        global $wpdb;
        // Remembered so the admin page can show the app's name.
        $wpdb->replace(self::table('clients'), ['client_id' => $url, 'client_name' => sanitize_text_field((string) ($doc['client_name'] ?? '')), 'redirect_uris' => wp_json_encode($doc['redirect_uris']), 'created_at' => gmdate('Y-m-d H:i:s')]);
        return ['name' => (string) ($doc['client_name'] ?? ''), 'redirect_uris' => (array) $doc['redirect_uris']];
    }

    /** Exact match, except that loopback redirects may use any port (RFC 8252). */
    private static function redirect_allowed($uri, array $registered)
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        $strip = function ($u) {
            $p = wp_parse_url($u);
            if (!$p || ($p['scheme'] ?? '') !== 'http' || !in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)) {
                return null;
            }
            return $p['host'] . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
        };
        $want = $strip($uri);
        foreach ($registered as $r) {
            if ($want !== null && $want === $strip($r)) {
                return true;
            }
        }
        return false;
    }

    public static function client_label($client_id, $name = '')
    {
        if ($name) {
            return $name;
        }
        return str_starts_with($client_id, 'https://') ? wp_parse_url($client_id, PHP_URL_HOST) : 'MCP client';
    }

    /* ---------- Authorization (consent screen) ---------- */

    private static function authorize()
    {
        $p = wp_unslash($_REQUEST);
        $client = self::client($p['client_id'] ?? '');
        $redirect = (string) ($p['redirect_uri'] ?? '');

        // Errors before the redirect URI is trusted are shown here, never redirected.
        if (!$client) {
            self::page('Unknown app', '<p>This app isn\'t registered with ' . esc_html(get_bloginfo('name')) . '. Try adding the connector again.</p>');
        }
        if (!self::redirect_allowed($redirect, $client['redirect_uris'])) {
            self::page('Invalid request', '<p>The app sent a return address that doesn\'t match its registration.</p>');
        }
        $back = function ($params) use ($redirect, $p) {
            $params += ['state' => $p['state'] ?? null, 'iss' => self::issuer()];
            wp_redirect(add_query_arg(array_map('rawurlencode', array_filter($params, 'is_string')), $redirect));
            exit;
        };
        if (($p['response_type'] ?? '') !== 'code') {
            $back(['error' => 'unsupported_response_type']);
        }
        if (empty($p['code_challenge']) || ($p['code_challenge_method'] ?? '') !== 'S256') {
            $back(['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.']);
        }
        if (!empty($p['resource']) && untrailingslashit($p['resource']) !== untrailingslashit(Site_MCP_Server::endpoint())) {
            $back(['error' => 'invalid_target', 'error_description' => 'Unknown resource.']);
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg(array_map('rawurlencode', array_filter($p, 'is_string')), self::url('authorize'))));
            exit;
        }

        $user = wp_get_current_user();
        $is_owner = current_user_can('manage_options');
        $has_access = Site_MCP_Server::user_has_access($user->ID);
        $app = self::client_label($p['client_id'], $client['name']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('site_mcp_authorize');
            if (($p['decision'] ?? '') !== 'approve') {
                $back(['error' => 'access_denied']);
            }
            if (!$has_access && !$is_owner) {
                $back(['error' => 'access_denied', 'error_description' => 'This account has not been given access.']);
            }
            if (!$has_access) {
                update_user_meta($user->ID, 'site_mcp_access', 1); // Owners can approve themselves.
            }
            self::cleanup();
            $code = self::new_token('code', $user->ID, $p['client_id'], 0, self::CODE_TTL, [
                'redirect_uri'   => $redirect,
                'code_challenge' => $p['code_challenge'],
                'resource'       => $p['resource'] ?? '',
            ]);
            Site_MCP_Log::add('oauth_authorize', ['app' => $app, 'redirect' => wp_parse_url($redirect, PHP_URL_HOST)], 'ok', 'Connected ' . $app);
            $back(['code' => $code]);
        }

        $host = wp_parse_url($redirect, PHP_URL_HOST);
        $switch = wp_logout_url(add_query_arg(array_map('rawurlencode', array_filter($p, 'is_string')), self::url('authorize')));
        $avatar = get_avatar_url($user->ID, ['size' => 64]);

        if (!$has_access && !$is_owner) {
            self::page('Access needed', '<div class="who"><img src="' . esc_url($avatar) . '" alt=""><div><strong>' . esc_html($user->display_name) . '</strong><span>' . esc_html($user->user_email) . '</span></div></div><p>Your account doesn\'t have Claude access to ' . esc_html(get_bloginfo('name')) . ' yet. Ask the site owner to add you on <em>Settings → Site MCP</em>, then try again.</p><p class="small"><a href="' . esc_url($switch) . '">Use a different account</a></p>');
        }

        $role = $is_owner ? 'what your administrator account can do' : 'what your ' . esc_html(implode(', ', $user->roles)) . ' role allows';
        $code = array_filter(Site_MCP_Code::all(), function ($code) use ($user) {
            return in_array($code->key, Site_MCP_Tools::enabled_groups(), true) && user_can($user, $code->cap) && !$code->unavailable();
        });
        $direct = (bool) $code;
        $what = implode(' and ', array_map(function ($code) {
            return $code->key === 'theme' ? "the theme's code" : 'custom features (the Site Features plugin)';
        }, $code));
        ob_start();
        ?>
        <p class="lead"><strong><?php echo esc_html($app); ?></strong> wants to work on <strong><?php echo esc_html(get_bloginfo('name')); ?></strong> as you.</p>
        <div class="who"><img src="<?php echo esc_url($avatar); ?>" alt=""><div><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($user->user_email); ?> · <a href="<?php echo esc_url($switch); ?>">Not you?</a></span></div></div>
        <ul class="can">
            <li>Read and edit content, products, media, forms and design, limited to <?php echo $role; ?></li>
            <li>Every change is logged, and the site owner can disconnect it at any time</li>
            <?php if ($direct) : ?>
                <li>Edit <?php echo esc_html($what); ?>, with version history and automatic rollback if a change breaks the site</li>
            <?php endif; ?>
            <li>No access to the server<?php echo $direct ? '' : ', its files'; ?> or your password</li>
        </ul>
        <form method="post">
            <?php wp_nonce_field('site_mcp_authorize'); ?>
            <?php foreach (['response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource'] as $key) : ?>
                <?php if (isset($p[$key]) && is_string($p[$key])) : ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($p[$key]); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="actions">
                <button name="decision" value="deny" class="btn">Cancel</button>
                <button name="decision" value="approve" class="btn primary">Approve</button>
            </div>
        </form>
        <p class="small">You'll be sent back to <?php echo esc_html($host); ?>.</p>
        <?php
        self::page('Connect to ' . get_bloginfo('name'), ob_get_clean());
    }

    private static function page($title, $body)
    {
        nocache_headers();
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');
        $icon = get_site_icon_url(96);
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html($title); ?></title>
<style>
:root { --ink: #101828; --ink-2: #475467; --ink-3: #98a2b3; --line: #e4e7ec; --accent: #3858e9; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: radial-gradient(120% 80% at 50% 0%, #eef1fd, #f6f7f9 60%); color: var(--ink); font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
.card { width: 100%; max-width: 440px; background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 32px; box-shadow: 0 24px 48px -24px rgba(16, 24, 40, .25); }
.mark { display: grid; place-items: center; width: 52px; height: 52px; margin-bottom: 18px; border-radius: 14px; background: linear-gradient(135deg, #3858e9, #7a5af8); color: #fff; font-weight: 700; font-size: 22px; overflow: hidden; }
.mark img { width: 100%; height: 100%; object-fit: cover; }
h1 { margin: 0 0 8px; font-size: 21px; letter-spacing: -.02em; }
p { margin: 0 0 16px; color: var(--ink-2); }
.lead { color: var(--ink); }
.who { display: flex; align-items: center; gap: 12px; padding: 12px; margin: 0 0 16px; border: 1px solid var(--line); border-radius: 12px; }
.who img { width: 40px; height: 40px; border-radius: 50%; }
.who div { display: flex; flex-direction: column; min-width: 0; }
.who span { color: var(--ink-3); font-size: 13px; }
.can { margin: 0 0 24px; padding: 0; list-style: none; }
.can li { position: relative; padding: 0 0 10px 28px; color: var(--ink-2); font-size: 14px; }
.can li::before { content: ""; position: absolute; left: 2px; top: 4px; width: 16px; height: 16px; border-radius: 50%; background: #ecfdf3 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4.5 8.2l2.2 2.2 4.8-4.8' fill='none' stroke='%23079455' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/16px no-repeat; }
.actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.btn { min-height: 44px; border: 1px solid #d0d5dd; border-radius: 10px; background: #fff; color: var(--ink); font: inherit; font-weight: 600; cursor: pointer; }
.btn:hover { background: #f9fafb; }
.btn.primary { border-color: var(--accent); background: var(--accent); color: #fff; }
.btn.primary:hover { background: #2a47c9; }
.btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.small { margin: 16px 0 0; font-size: 13px; color: var(--ink-3); text-align: center; }
a { color: var(--accent); }
</style>
</head>
<body>
<main class="card">
    <div class="mark"><?php echo $icon ? '<img src="' . esc_url($icon) . '" alt="">' : esc_html(mb_substr(get_bloginfo('name'), 0, 1)); ?></div>
    <h1><?php echo esc_html($title); ?></h1>
    <?php echo $body; ?>
</main>
</body>
</html>
        <?php
        exit;
    }

    /* ---------- Token endpoint ---------- */

    private static function token()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::json(null);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::oauth_error('invalid_request', 'Use POST.', 405);
        }
        $req = self::body();
        $client_id = (string) ($req['client_id'] ?? '');
        if ($client_id === '' && !empty($_SERVER['PHP_AUTH_USER'])) {
            $client_id = (string) $_SERVER['PHP_AUTH_USER'];
        }
        self::cleanup();

        switch ($req['grant_type'] ?? '') {
            case 'authorization_code':
                $code = (string) ($req['code'] ?? '');
                $row = self::find_token($code, 'code');
                if (!$row) {
                    self::oauth_error('invalid_grant', 'The code is invalid or expired.');
                }
                self::delete_token($code); // single use
                if ($row['client_id'] !== $client_id || $row['data']['redirect_uri'] !== ($req['redirect_uri'] ?? '')) {
                    self::oauth_error('invalid_grant', 'client_id or redirect_uri does not match the authorization request.');
                }
                $verifier = (string) ($req['code_verifier'] ?? '');
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                if ($verifier === '' || !hash_equals($row['data']['code_challenge'], $challenge)) {
                    self::oauth_error('invalid_grant', 'PKCE verification failed.');
                }
                global $wpdb;
                $wpdb->insert(self::table('grants'), ['client_id' => $client_id, 'user_id' => $row['user_id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
                self::issue((int) $row['user_id'], $client_id, (int) $wpdb->insert_id);
                break;

            case 'refresh_token':
                $refresh = (string) ($req['refresh_token'] ?? '');
                $row = self::find_token($refresh, 'refresh');
                if (!$row || ($client_id !== '' && $row['client_id'] !== $client_id)) {
                    self::oauth_error('invalid_grant', 'The refresh token is invalid, expired or revoked.');
                }
                if (!Site_MCP_Server::user_has_access($row['user_id'])) {
                    self::oauth_error('invalid_grant', 'This account no longer has access.');
                }
                self::delete_token($refresh); // rotation
                self::issue((int) $row['user_id'], $row['client_id'], (int) $row['grant_id']);
                break;

            default:
                self::oauth_error('unsupported_grant_type', 'Use authorization_code or refresh_token.');
        }
    }

    private static function issue($user_id, $client_id, $grant_id)
    {
        global $wpdb;
        // One live access token per grant.
        $wpdb->delete(self::table('tokens'), ['grant_id' => $grant_id, 'type' => 'access']);
        self::json([
            'access_token'  => self::new_token('access', $user_id, $client_id, $grant_id, self::ACCESS_TTL),
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => self::new_token('refresh', $user_id, $client_id, $grant_id, self::REFRESH_TTL),
            'scope'         => self::SCOPE,
        ]);
    }
}
