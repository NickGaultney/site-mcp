<?php
defined('ABSPATH') || exit;

/**
 * Switches off third-party plugins that Site MCP now does itself: FileBird (replaced by
 * Site_MCP_Media_Folders) and Site Kit by Google (replaced by Site_MCP_Analytics).
 *
 * Each plugin is switched off at most once per site, and only when nothing would break: if the site
 * relies on something the built-in feature doesn't do, the plugin stays on and the reason shows on
 * Settings → Site MCP. If someone turns the plugin back on later, it is left alone and the built-in
 * feature stands aside. Plugins are deactivated, never deleted, so their data stays.
 */
class Site_MCP_Takeover
{
    const OPTION = 'site_mcp_takeover';

    /** Site Kit modules that do something our analytics doesn't: keep Site Kit while any is on. */
    const SITE_KIT_KEEP = [
        'adsense'                => 'shows AdSense ads',
        'ads'                    => 'runs Google Ads conversion tracking',
        'tagmanager'             => 'loads a Google Tag Manager container, which can carry ad and pixel tags',
        'sign-in-with-google'    => 'lets people sign in with Google',
        'reader-revenue-manager' => 'runs Reader Revenue Manager',
    ];

    public static function boot()
    {
        // Admin visits and the hourly health check, at most once an hour either way.
        add_action('admin_init', [__CLASS__, 'maybe_run']);
        add_action(Site_MCP_Health::HOOK, [__CLASS__, 'maybe_run']);
    }

    public static function state()
    {
        return (array) get_option(self::OPTION, []) + ['filebird' => [], 'site-kit' => []];
    }

    private static function save($key, array $entry)
    {
        $state = self::state();
        $state[$key] = $entry + ['checked' => time()];
        update_option(self::OPTION, $state, false);
    }

    public static function maybe_run()
    {
        if (get_transient('site_mcp_takeover_ran')) {
            return;
        }
        set_transient('site_mcp_takeover_ran', 1, HOUR_IN_SECONDS);
        self::run();
    }

    public static function run()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::filebird();
        self::site_kit();
    }

    /** Active plugin files whose folder is one of $slugs. */
    private static function active_files(array $slugs)
    {
        return array_values(array_filter((array) get_option('active_plugins', []), function ($file) use ($slugs) {
            return in_array(dirname($file), $slugs, true);
        }));
    }

    private static function switch_off($key, $name, array $files, $why)
    {
        deactivate_plugins($files); // with their own deactivation hooks, as if switched off in Plugins
        $still = array_intersect($files, (array) get_option('active_plugins', []));
        if ($still) {
            self::save($key, ['kept' => "Couldn't switch $name off."]);
            return;
        }
        self::save($key, ['replaced' => time(), 'plugins' => $files]);
        Site_MCP_Log::add('site_mcp', ['deactivated' => $files], 'ok', "Switched $name off: $why It is deactivated, not deleted, so turning it back on in Plugins restores it.");
    }

    private static function filebird()
    {
        $state = self::state()['filebird'];
        $files = self::active_files(['filebird', 'filebird-pro']);
        if (!$files || !empty($state['replaced']) || !Site_MCP_Media_Folders::enabled()) {
            return;
        }
        global $wpdb;
        $kept = null;
        if (function_exists('bgmf_boot')) {
            $kept = 'This site\'s Site Features has its own media folders feature.';
        } elseif (get_option('njt_fbv_folder_per_user', '0') === '1') {
            $kept = 'FileBird gives each user their own folders here, which the built-in folders don\'t support.';
        } elseif ($n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND (post_content LIKE '%wp:filebird/%' OR post_content LIKE '%[filebird%')")) {
            $kept = "$n page" . ($n === 1 ? ' uses' : 's use') . ' FileBird\'s gallery block, which would stop working.';
        } elseif ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%filebird%'")) {
            $kept = 'Elementor content refers to FileBird.';
        }
        if ($kept) {
            self::save('filebird', ['kept' => $kept]);
            return;
        }
        self::switch_off('filebird', 'FileBird', $files, 'Media → Library has built-in folders now, using the same folders FileBird made.');
    }

    private static function site_kit()
    {
        $state = self::state()['site-kit'];
        $files = self::active_files(['google-site-kit']);
        if (!$files || !empty($state['replaced']) || !Site_MCP_Analytics::enabled()) {
            return;
        }
        $modules = (array) get_option('googlesitekit_active_modules', []);
        $uses = array_values(array_intersect_key(self::SITE_KIT_KEEP, array_flip($modules)));
        if ($uses) {
            self::save('site-kit', ['kept' => 'Site Kit ' . implode(', and ', $uses) . '.']);
            return;
        }
        self::switch_off('site-kit', 'Site Kit by Google', $files, 'Dashboard → Analytics has built-in, cookie-free analytics now. Google Analytics stops collecting; its past data stays in Google Analytics.');
    }

    /** One line per plugin for the settings page. */
    public static function summary()
    {
        $out = [];
        $slugs = ['filebird' => ['filebird', 'filebird-pro'], 'site-kit' => ['google-site-kit']];
        foreach (['filebird' => 'FileBird', 'site-kit' => 'Site Kit by Google'] as $key => $name) {
            $s = self::state()[$key];
            if (!empty($s['replaced']) && self::active_files($slugs[$key])) {
                $out[$key] = "$name was turned back on after Site MCP replaced it" . ($key === 'filebird' ? ', so the built-in folders stand aside.' : '. Both are collecting.');
            } elseif (!empty($s['replaced'])) {
                $out[$key] = "Replaced $name on " . wp_date(get_option('date_format'), $s['replaced']) . '.';
            } elseif (!empty($s['kept'])) {
                $out[$key] = "Kept $name: " . $s['kept'];
            }
        }
        return $out;
    }
}
