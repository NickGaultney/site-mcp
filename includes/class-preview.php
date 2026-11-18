<?php
defined('ABSPATH') || exit;

/**
 * Private previews of an inactive theme on the real site. A preview link
 * carries a token; whoever opens it sees the site with that theme, and
 * everyone else keeps seeing the live theme. Share links are made by
 * administrators and can be revoked. Version checks use a separate token
 * derived from the site's secret keys, which never leaves the server.
 *
 * Preview pages are never cached, and their links keep the token, because
 * page caches (such as LiteSpeed on Hostinger) ignore cookies.
 *
 * A preview can also run without plugins the switch will turn off (such as
 * Elementor), so it shows, and measures, the site as it will be. Plugins load
 * before Site MCP, so this needs a small must-use loader, which Site MCP adds
 * only while a preview has such a plan and removes afterwards. This file is
 * loaded by that loader too, so nothing runs when it is included.
 */
class Site_MCP_Preview
{
    const PARAM = 'site_mcp_preview';
    const OPTION = 'site_mcp_previews';
    const PLANS = 'site_mcp_preview_plans';
    const LOADER = 'site-mcp-preview.php';
    const DAYS = 30;

    private static $theme = null;
    private static $token = null;

    /**
     * The preview token this request carries. The cookie only carries a preview
     * between front-end pages: in wp-admin, AJAX, REST and login requests the
     * live site stays in charge unless the URL itself asks for a preview (as
     * the version checks do), so someone who opened a preview can still manage
     * the site.
     */
    private static function request_token()
    {
        if (isset($_GET[self::PARAM])) {
            return sanitize_text_field(wp_unslash($_GET[self::PARAM]));
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $front = !is_admin() && !wp_doing_ajax() && !wp_doing_cron() && !str_contains($uri, '/' . rest_get_url_prefix() . '/') && !str_contains($uri, 'rest_route=') && !str_contains($uri, 'wp-login.php') && !str_contains($uri, 'xmlrpc.php');
        return isset($_COOKIE[self::PARAM]) && $front ? sanitize_text_field(wp_unslash($_COOKIE[self::PARAM])) : '';
    }

    /** Called by the must-use loader before plugins load: leaves out the plugins this preview runs without. */
    public static function early()
    {
        $token = self::request_token();
        $theme = $token !== '' && $token !== 'off' ? self::token_theme($token) : null;
        $without = $theme ? (self::plans()[$theme] ?? []) : [];
        if ($without) {
            add_filter('option_active_plugins', function ($plugins) use ($without) {
                return array_values(array_diff((array) $plugins, $without));
            });
        }
    }

    public static function boot()
    {
        $token = self::request_token();
        if ($token === '') {
            return;
        }
        if ($token === 'off' || !($theme = self::resolve($token))) {
            if (isset($_COOKIE[self::PARAM])) {
                setcookie(self::PARAM, '', time() - YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            }
            return;
        }
        self::$theme = $theme;
        self::$token = $token;
        if (isset($_GET[self::PARAM]) && !headers_sent()) {
            setcookie(self::PARAM, $token, 0, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $use = function () use ($theme) {
            return $theme;
        };
        add_filter('stylesheet', $use, 99);
        add_filter('template', function () use ($theme) {
            return wp_get_theme($theme)->get_template();
        }, 99);
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        add_action('send_headers', [__CLASS__, 'headers']);
        add_action('template_redirect', function () {
            ob_start([__CLASS__, 'keep_token']);
        }, 0);
        add_action('wp_footer', [__CLASS__, 'banner'], 999);
    }

    /** The theme being previewed in this request, or null. */
    public static function theme()
    {
        return self::$theme;
    }

    public static function headers()
    {
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
    }

    /**
     * The token that version checks use to load an inactive theme. Signed with
     * the site's secret keys directly, because the must-use loader checks it
     * before WordPress's own hashing functions are loaded.
     */
    public static function check_token($stylesheet)
    {
        $key = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '') . get_option('auth_key', '') . get_option('auth_salt', '');
        return 'check.' . $stylesheet . '.' . substr(hash_hmac('sha256', 'site-mcp-preview-check|' . $stylesheet, $key), 0, 24);
    }

    /** The theme a token is for, or null if the token isn't valid. */
    private static function token_theme($token)
    {
        if (str_starts_with($token, 'check.')) {
            $theme = explode('.', $token, 3)[1] ?? '';
            return hash_equals(self::check_token($theme), $token) ? $theme : null;
        }
        return self::links()[$token]['theme'] ?? null;
    }

    /** Returns the theme a token previews, or null if the token isn't valid or that theme is already live. */
    private static function resolve($token)
    {
        $theme = self::token_theme($token);
        return $theme && wp_get_theme($theme)->exists() && $theme !== get_option('stylesheet') ? $theme : null;
    }

    /** Plugins each theme's previews run without: [theme => [plugin files]]. */
    public static function plans()
    {
        $plans = get_option(self::PLANS, []);
        return is_array($plans) ? $plans : [];
    }

    /**
     * Sets the plugins a theme's previews run without (an empty list clears it),
     * and adds or removes the must-use loader to match. Returns a warning if
     * the loader can't be written, or null.
     */
    public static function set_plan($stylesheet, array $plugins)
    {
        $plans = self::plans();
        $plugins = array_values(array_unique(array_diff($plugins, [plugin_basename(SITE_MCP_FILE)])));
        if ($plugins) {
            $plans[$stylesheet] = $plugins;
        } else {
            unset($plans[$stylesheet]);
        }
        update_option(self::PLANS, $plans, true);
        return self::sync_loader();
    }

    private static function sync_loader()
    {
        $file = WPMU_PLUGIN_DIR . '/' . self::LOADER;
        if (!self::plans()) {
            if (is_file($file)) {
                @unlink($file);
            }
            return null;
        }
        $source = "<?php\n/**\n * Plugin Name: Site MCP preview\n * Description: Lets Site MCP preview a new theme without the plugins switching to it will turn off. Site MCP adds this file while such a preview exists and removes it afterwards.\n */\n\ndefined('ABSPATH') || exit;\n\nif (is_readable(" . var_export(__FILE__, true) . ")) {\n    require_once " . var_export(__FILE__, true) . ";\n    Site_MCP_Preview::early();\n}\n";
        if (is_file($file) && file_get_contents($file) === $source) {
            return null;
        }
        if (!wp_mkdir_p(WPMU_PLUGIN_DIR) || file_put_contents($file, $source) === false) {
            return "Couldn't write " . self::LOADER . ' to the must-use plugins folder, so previews still run with those plugins on.';
        }
        return null;
    }

    /** Share links that haven't expired: [token => {theme, by, created, expires}]. */
    public static function links()
    {
        $links = get_option(self::OPTION, []);
        $live = array_filter(is_array($links) ? $links : [], function ($link) {
            return $link['expires'] > time();
        });
        if (count($live) !== count((array) $links)) {
            update_option(self::OPTION, $live, false);
        }
        return $live;
    }

    /** A share link for a theme, reusing an existing one unless $fresh. */
    public static function link($stylesheet, $fresh = false)
    {
        $links = self::links();
        foreach ($links as $token => $link) {
            if ($link['theme'] === $stylesheet && !$fresh) {
                return self::url($token);
            }
        }
        $token = strtolower(wp_generate_password(20, false));
        $links[$token] = ['theme' => $stylesheet, 'by' => get_current_user_id(), 'created' => time(), 'expires' => time() + self::DAYS * DAY_IN_SECONDS];
        update_option(self::OPTION, $links, false);
        return self::url($token);
    }

    public static function url($token, $path = '/')
    {
        return add_query_arg(self::PARAM, $token, home_url($path));
    }

    /** Revokes every share link for a theme (or all of them). Returns how many. */
    public static function revoke($stylesheet = null)
    {
        $links = self::links();
        $keep = $stylesheet ? array_filter($links, function ($link) use ($stylesheet) {
            return $link['theme'] !== $stylesheet;
        }) : [];
        update_option(self::OPTION, $keep, false);
        return count($links) - count($keep);
    }

    /** Adds the token to links and forms that stay on this site, so caches never serve a preview visitor the live theme. */
    public static function keep_token($html)
    {
        $home = preg_quote(untrailingslashit(home_url()), '#');
        return preg_replace_callback('#\b(href|action)=(["\'])(' . $home . '(?:/[^"\'\#?]*)?)(\?[^"\'\#]*)?(\#[^"\']*)?\2#i', function ($m) {
            if (preg_match('#/wp-(content|includes|admin)/|\.(css|js|png|jpe?g|gif|webp|avif|svg|woff2?|ico|xml|pdf)$#i', $m[3]) || str_contains($m[4] ?? '', self::PARAM . '=')) {
                return $m[0];
            }
            $query = isset($m[4]) && $m[4] !== '' ? $m[4] . '&amp;' : '?';
            return $m[1] . '=' . $m[2] . $m[3] . $query . self::PARAM . '=' . rawurlencode(self::$token) . ($m[5] ?? '') . $m[2];
        }, $html);
    }

    /** A small label on share-link previews, so nobody mistakes the preview for the live site. */
    public static function banner()
    {
        if (str_starts_with((string) self::$token, 'check.') || isset($_GET['site_mcp_clean'])) {
            return;
        }
        $exit = esc_url(add_query_arg(self::PARAM, 'off', home_url('/')));
        echo '<div id="site-mcp-preview" style="position:fixed;left:12px;bottom:12px;z-index:2147483647;display:flex;gap:10px;align-items:center;padding:8px 12px;border-radius:999px;background:#111;color:#fff;font:500 13px/1.2 system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.25)">Preview of the new design <a href="' . $exit . '" style="color:#9ecbff">Exit</a></div>';
    }
}
