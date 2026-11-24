<?php
defined('ABSPATH') || exit;

/**
 * Sharing improvements with Site MCP's maintainer. When the owner turns it on,
 * Claude can send what it built that other sites could reuse (suggest_standard)
 * to the inbox, where it is reviewed and the best becomes part of Site MCP.
 *
 * The site registers itself with the inbox the first time and keeps its key.
 * Before anything is sent, the site's name and domain are replaced with
 * placeholders and settings that look like secrets are removed.
 */
class Site_MCP_Share
{
    const OPTION = 'site_mcp_share';
    const DEFAULT_URL = 'https://standards.gauws.com';
    /** Setting names whose values are left out: passwords, keys, tokens, salts, licences. */
    const SECRET = '/pass(word|wd|phrase)?($|[_-])|secret|token|api[_-]?key|apikey|licen[cs]e[_-]?key|auth[_-]?key|salt|private[_-]?key|credential/i';

    /** The inbox. SITE_MCP_SHARE_URL in wp-config.php points it elsewhere. */
    public static function url()
    {
        return rtrim(defined('SITE_MCP_SHARE_URL') ? (string) SITE_MCP_SHARE_URL : self::DEFAULT_URL, '/');
    }

    /** Whether SITE_MCP_SHARE in wp-config.php decides, so the setting can't be changed here. */
    public static function locked()
    {
        return defined('SITE_MCP_SHARE');
    }

    private static function state()
    {
        return (array) get_option(self::OPTION, []) + ['enabled' => false, 'site_id' => '', 'key' => '', 'sent' => 0, 'last_sent' => 0, 'error' => ''];
    }

    private static function save(array $state)
    {
        update_option(self::OPTION, $state, false);
    }

    public static function enabled()
    {
        return self::locked() ? (bool) SITE_MCP_SHARE : (bool) self::state()['enabled'];
    }

    public static function status()
    {
        $s = self::state();
        return ['enabled' => self::enabled(), 'locked' => self::locked(), 'registered' => $s['site_id'] !== '', 'sent' => (int) $s['sent'], 'last_sent' => (int) $s['last_sent'], 'error' => $s['error']];
    }

    /** Turns sharing on or off. Turning it on registers the site, so problems show straight away. */
    public static function set_enabled($on)
    {
        $s = self::state();
        $s['enabled'] = (bool) $on;
        $s['error'] = '';
        self::save($s);
        if ($on && $s['site_id'] === '') {
            try {
                self::register();
            } catch (Site_MCP_Error $e) {
                $s = self::state();
                $s['error'] = $e->getMessage();
                self::save($s);
            }
        }
    }

    private static function post($path, array $body, $auth = null)
    {
        $args = ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)];
        if ($auth) {
            $args['headers']['Authorization'] = 'Bearer ' . $auth;
        }
        $response = wp_remote_post(self::url() . $path, $args);
        if (is_wp_error($response)) {
            throw new Site_MCP_Error('Could not reach the inbox at ' . self::url() . ': ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return [$code, is_array($data) ? $data : []];
    }

    private static function register()
    {
        global $wp_version;
        [$code, $data] = self::post('/v1/register', [
            'url'      => home_url('/'),
            'name'     => get_bloginfo('name'),
            'versions' => ['site_mcp' => SITE_MCP_VERSION, 'wordpress' => $wp_version, 'php' => PHP_VERSION],
        ]);
        if ($code !== 201 || empty($data['site_id']) || empty($data['key'])) {
            throw new Site_MCP_Error('The inbox did not register this site: ' . ($data['error'] ?? "HTTP $code"));
        }
        $s = self::state();
        $s['site_id'] = (string) $data['site_id'];
        $s['key'] = (string) $data['key'];
        self::save($s);
    }

    /** Sends a suggestion, registering first if needed (or again, if the inbox no longer knows this site). */
    public static function send(array $suggestion)
    {
        if (!self::enabled()) {
            throw new Site_MCP_Error('Sharing is off on this site. The owner can turn it on in Settings → Site MCP.');
        }
        $payload = self::scrub($suggestion) + ['context' => self::context()];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $s = self::state();
            if ($s['site_id'] === '') {
                self::register();
                $s = self::state();
            }
            [$code, $data] = self::post('/v1/suggestions', $payload, $s['site_id'] . '.' . $s['key']);
            if ($code === 401 && $attempt === 0) {
                $s['site_id'] = $s['key'] = '';
                self::save($s);
                continue;
            }
            break;
        }
        $s = self::state();
        if ($code !== 201) {
            $s['error'] = 'The inbox refused a suggestion: ' . ($data['error'] ?? "HTTP $code");
            self::save($s);
            throw new Site_MCP_Error($s['error']);
        }
        $s['sent']++;
        $s['last_sent'] = time();
        $s['error'] = '';
        self::save($s);
        return (string) $data['id'];
    }

    /** What the reviewer needs to know about the site, without naming it. */
    private static function context()
    {
        global $wp_version;
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            if (is_plugin_active($file)) {
                $plugins[dirname($file) === '.' ? basename($file, '.php') : dirname($file)] = $data['Version'];
            }
        }
        $theme = wp_get_theme();
        return [
            'site_mcp'  => SITE_MCP_VERSION,
            'wordpress' => $wp_version,
            'php'       => PHP_VERSION,
            'server'    => self::scrub_text(sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? '')),
            'theme'     => ['parent' => $theme->parent() ? $theme->get_template() : null, 'block_theme' => $theme->is_block_theme(), 'built_by_site_mcp' => is_dir(Site_MCP_Code::theme()->git_dir())],
            'plugins'   => $plugins,
        ];
    }

    /** Patterns for the site's address, domain and name, each with its placeholder. */
    private static function identifiers()
    {
        $host = preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        $out = [];
        if ($host !== '') {
            $out['#https?://(www\.)?' . preg_quote($host, '#') . '#i'] = 'https://example.com';
            $out['#(?<![\w.-])(www\.)?' . preg_quote($host, '#') . '(?![\w-])#i'] = 'example.com';
        }
        // The name as a whole phrase and case-sensitive, so a site called "Shop" leaves "shop" alone.
        $name = trim(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        if (strlen($name) >= 3) {
            $out['#(?<![\w-])' . preg_quote($name, '#') . '(?![\w-])#'] = 'Example Site';
        }
        return $out;
    }

    private static function scrub_text($text)
    {
        foreach (self::identifiers() as $pattern => $placeholder) {
            $text = preg_replace($pattern, $placeholder, $text);
        }
        return $text;
    }

    /** Replaces the site's name and domain everywhere, and removes settings that look like secrets. */
    private static function scrub($value, $key = '')
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? self::scrub_text($k) : $k] = is_string($k) && preg_match(self::SECRET, $k) && !is_array($v)
                    ? '[removed]'
                    : self::scrub($v, $k);
            }
            return $out;
        }
        return is_string($value) ? self::scrub_text($value) : $value;
    }
}
