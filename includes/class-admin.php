<?php
defined('ABSPATH') || exit;

/** Settings → Site MCP: who can connect, which tool groups are on, the theme's and Site Features' version history and the activity log. */
class Site_MCP_Admin
{
    const APP_NAME = 'Site MCP (Claude)';
    const PAGE = 'site-mcp';

    private static $hook = '';

    public static function boot()
    {
        add_action('admin_menu', function () {
            self::$hook = add_options_page('Site MCP', 'Site MCP', 'manage_options', self::PAGE, [__CLASS__, 'render']);
        });
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_site_mcp_connect', [__CLASS__, 'connect']);
        add_action('admin_post_site_mcp_revoke', [__CLASS__, 'revoke']);
        add_action('admin_post_site_mcp_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_site_mcp_revert', [__CLASS__, 'revert']);
        add_action('admin_post_site_mcp_features_on', [__CLASS__, 'features_on']);
        add_action('admin_post_site_mcp_switch_back', [__CLASS__, 'switch_back']);
        add_action('admin_post_site_mcp_preview', [__CLASS__, 'preview']);
        add_action('admin_post_site_mcp_backup', [__CLASS__, 'backup']);
        add_action('admin_post_site_mcp_backup_download', [__CLASS__, 'backup_download']);
        add_action('admin_post_site_mcp_check', [__CLASS__, 'check']);
        add_action('admin_post_site_mcp_health', [__CLASS__, 'health']);
        add_action('admin_post_site_mcp_health_test', [__CLASS__, 'health_test']);
        add_action('admin_post_site_mcp_alert_email', [__CLASS__, 'alert_email']);
        add_action('admin_post_site_mcp_fluent_cart', [__CLASS__, 'fluent_cart']);
        add_action('admin_notices', [__CLASS__, 'quiet_notices'], 1);
    }

    public static function assets($hook)
    {
        if ($hook !== self::$hook) {
            return;
        }
        $url = plugins_url('assets/', dirname(__DIR__) . '/site-mcp.php');
        $dir = dirname(__DIR__) . '/assets/';
        wp_enqueue_style('site-mcp-admin', $url . 'admin.css', [], filemtime($dir . 'admin.css'));
        wp_enqueue_script('site-mcp-admin', $url . 'admin.js', [], filemtime($dir . 'admin.js'), ['in_footer' => true]);
    }

    /**
     * Keeps this page focused: other plugins' notices are hidden here. Fluent
     * Cart's "no MCP adapter found" warning is hidden everywhere, because this
     * plugin serves its abilities and the warning's button would disable them.
     */
    public static function quiet_notices()
    {
        global $wp_filter;
        $screen = get_current_screen();
        if ($screen && $screen->id === self::$hook) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            return;
        }
        if (empty($wp_filter['admin_notices'])) {
            return;
        }
        foreach ($wp_filter['admin_notices']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_array($fn) && is_object($fn[0]) && $fn[0] instanceof \FluentCart\App\Modules\MCP\MCPInit && $fn[1] === 'maybeShowAdapterNotice') {
                    remove_action('admin_notices', $fn, $priority);
                }
            }
        }
    }

    private static function back($args = [])
    {
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=' . self::PAGE)));
        exit;
    }

    private static function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer($action);
    }

    public static function connect()
    {
        self::guard('site_mcp_connect');
        $user_id = (int) ($_POST['user_id'] ?? 0);
        if (!$user_id || !user_can($user_id, 'edit_posts')) {
            self::back(['site_mcp_error' => 'user']);
        }
        update_user_meta($user_id, 'site_mcp_access', 1);
        self::back(['site_mcp_granted' => $user_id]);
    }

    public static function revoke()
    {
        self::guard('site_mcp_revoke');
        $user_id = (int) ($_POST['user_id'] ?? 0);
        foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
            if ($item['name'] === self::APP_NAME) {
                WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
            }
        }
        Site_MCP_OAuth::revoke_user($user_id);
        delete_user_meta($user_id, 'site_mcp_access');
        self::back(['site_mcp_revoked' => 1]);
    }

    public static function save_settings()
    {
        self::guard('site_mcp_settings');
        $groups = array_values(array_intersect(array_keys(Site_MCP_Tools::GROUPS), (array) ($_POST['groups'] ?? [])));
        update_option('site_mcp_groups', $groups);
        update_option('site_mcp_instructions', sanitize_textarea_field(wp_unslash($_POST['instructions'] ?? '')));
        self::back(['site_mcp_saved' => 1]);
    }

    private static function notice($type, $text)
    {
        set_transient('site_mcp_notice_' . get_current_user_id(), [$type, $text], MINUTE_IN_SECONDS);
    }

    /** The Undo buttons in the history cards. The outcome is shown once, from a short-lived transient. */
    public static function revert()
    {
        self::guard('site_mcp_revert');
        $code = ($_POST['target'] ?? '') === 'features' ? Site_MCP_Code::features() : Site_MCP_Code::theme();
        if (!current_user_can($code->cap)) {
            wp_die('Not allowed.');
        }
        $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));
        $tool = $code->prefix . '_revert';
        try {
            $result = $code->revert($version);
            Site_MCP_Log::add($tool, ['version' => $version, 'from' => 'Settings → Site MCP'], 'ok', 'Undid ' . $result['undid']);
            self::notice('ok', 'Undid ' . $result['undid'] . '. The site still loads.');
        } catch (Throwable $e) {
            Site_MCP_Log::add($tool, ['version' => $version, 'from' => 'Settings → Site MCP'], 'error', $e->getMessage());
            self::notice('error', $e->getMessage());
        }
        self::back();
    }

    /** Reverses the last theme switch, exactly as theme_switch_back does. */
    public static function switch_back()
    {
        self::guard('site_mcp_switch_back');
        if (!current_user_can('switch_themes') || !current_user_can('activate_plugins')) {
            wp_die('Not allowed.');
        }
        try {
            $result = Site_MCP_Switch::switch_back();
            $r = $result['record'];
            Site_MCP_Log::add('theme_switch_back', ['from' => 'Settings → Site MCP'], 'ok', "Switched back: {$r['to']} is the live theme again");
            self::notice('ok', wp_get_theme($r['to'])->get('Name') . ' is the live theme again. The site loads.');
        } catch (Throwable $e) {
            Site_MCP_Log::add('theme_switch_back', ['from' => 'Settings → Site MCP'], 'error', $e->getMessage());
            self::notice('error', $e->getMessage());
        }
        self::back();
    }

    /** Opens a theme's preview (making a share link if needed), or revokes its links. */
    public static function preview()
    {
        self::guard('site_mcp_preview');
        if (!current_user_can('edit_themes')) {
            wp_die('Not allowed.');
        }
        $theme = sanitize_key(wp_unslash($_POST['theme'] ?? ''));
        if (!empty($_POST['revoke'])) {
            Site_MCP_Preview::revoke($theme);
            self::notice('ok', 'Preview links revoked. Anyone who had one now sees the live site.');
            self::back();
        }
        if (!wp_get_theme($theme)->exists() || $theme === get_stylesheet()) {
            wp_die('No such inactive theme.');
        }
        wp_redirect(Site_MCP_Preview::link($theme));
        exit;
    }

    public static function backup()
    {
        self::guard('site_mcp_backup');
        try {
            $m = Site_MCP_Backup::create('Made from Settings → Site MCP');
            Site_MCP_Log::add('site_backup', ['from' => 'Settings → Site MCP'], 'ok', "Backed up as {$m['id']}");
            self::notice($m['notes'] ? 'error' : 'ok', $m['notes'] ? implode(' ', $m['notes']) : 'Backed up the database, themes and plugins.');
        } catch (Throwable $e) {
            Site_MCP_Log::add('site_backup', ['from' => 'Settings → Site MCP'], 'error', $e->getMessage());
            self::notice('error', $e->getMessage());
        }
        self::back();
    }

    /** Streams one file of a backup to an administrator. */
    public static function backup_download()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('site_mcp_backup_download');
        $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
        $path = Site_MCP_Backup::file($id, sanitize_key($_GET['file'] ?? ''));
        if (!$path) {
            wp_die('That backup file no longer exists.');
        }
        Site_MCP_Log::add('backup_download', ['id' => $id, 'file' => basename($path)], 'ok', 'Downloaded ' . basename($path));
        $name = sanitize_title(wp_parse_url(home_url(), PHP_URL_HOST)) . "-$id-" . basename($path);
        nocache_headers();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /** Backups kept on the server, with downloads and a button to make one now. */
    private static function backups_card($form)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $backups = Site_MCP_Backup::all();
        $link = function ($id, $file) {
            return wp_nonce_url(admin_url("admin-post.php?action=site_mcp_backup_download&id=$id&file=$file"), 'site_mcp_backup_download');
        };
        ?>
        <section class="smcp-card">
            <div class="smcp-card__head smcp-card__head--row">
                <div>
                    <h2>Backups</h2>
                    <p>The database, themes and plugins (not uploads), saved on this server. One is made automatically before every theme switch; the newest <?php echo (int) Site_MCP_Backup::KEEP; ?> are kept.</p>
                </div>
                <form method="post" action="<?php echo $form; ?>">
                    <?php wp_nonce_field('site_mcp_backup'); ?>
                    <input type="hidden" name="action" value="site_mcp_backup">
                    <button class="smcp-btn smcp-btn--ghost">Back up now</button>
                </form>
            </div>
            <?php if (!$backups) : ?>
                <p class="smcp-empty">No backups yet.</p>
            <?php else : ?>
                <ul class="smcp-features">
                    <?php foreach ($backups as $b) : ?>
                        <li>
                            <strong><?php echo esc_html($b['reason']); ?></strong>
                            <span><?php echo esc_html($b['by'] . ' · ' . self::ago($b['created']) . ' · ' . ($b['notes'] ? implode(' ', $b['notes']) : 'complete')); ?></span>
                            <span class="smcp-theme-actions">
                                <a class="smcp-btn smcp-btn--ghost" href="<?php echo esc_url($link($b['id'], 'database')); ?>">Database <?php echo esc_html(size_format($b['database_bytes'])); ?></a>
                                <?php if ($b['files_bytes']) : ?><a class="smcp-btn smcp-btn--ghost" href="<?php echo esc_url($link($b['id'], 'files')); ?>">Files <?php echo esc_html(size_format($b['files_bytes'])); ?></a><?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="smcp-hint">Stored in <code><?php echo esc_html(Site_MCP_Backup::dir()); ?></code>. Each backup has a RESTORE.txt explaining how to put it back.</p>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function health()
    {
        self::guard('site_mcp_health');
        $state = Site_MCP_Health::check();
        self::notice('ok', $state['issues'] ? 'Checked: ' . count($state['issues']) . ' problem' . (count($state['issues']) === 1 ? '' : 's') . ' found.' : 'Checked: no problems.');
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '#smcp-health'));
        exit;
    }

    public static function health_test()
    {
        self::guard('site_mcp_health_test');
        $sent = Site_MCP_Health::test_email();
        self::notice($sent ? 'ok' : 'error', $sent ? 'Test alert sent to ' . implode(', ', Site_MCP_Health::recipients()) . '.' : "The test alert couldn't be sent. Check that this site can send email (for example with an SMTP plugin).");
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '#smcp-health'));
        exit;
    }

    public static function alert_email()
    {
        self::guard('site_mcp_alert_email');
        if (Site_MCP_Health::locked()) {
            wp_die('The alert email is set in wp-config.php.');
        }
        $invalid = Site_MCP_Health::set_recipients(sanitize_text_field(wp_unslash($_POST['alert_email'] ?? '')));
        self::notice($invalid ? 'error' : 'ok', $invalid ? 'Not valid email addresses, so left out: ' . implode(', ', $invalid) : 'Alerts go to ' . (implode(', ', Site_MCP_Health::recipients()) ?: 'nobody') . '.');
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '#smcp-health'));
        exit;
    }

    private static function health_card($form)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = Site_MCP_Health::state();
        $m = $state['metrics'];
        $to = Site_MCP_Health::recipients();
        $rows = Site_MCP_Health::heavy(time() - DAY_IN_SECONDS, 10);
        $stats = [];
        if (isset($m['load'])) {
            $stats[] = sprintf('Load %.2f on %d CPU%s', $m['load'], $m['cpus'], $m['cpus'] === 1 ? '' : 's');
        }
        if (isset($m['memory_free'])) {
            $stats[] = sprintf('%d%% of %s memory free', $m['memory_free'] * 100, size_format($m['memory_total']));
        }
        if (isset($m['disk_free'])) {
            $stats[] = size_format($m['disk_free_bytes']) . ' disk free';
        }
        if (isset($m['database'])) {
            $stats[] = 'Database ' . size_format($m['database']);
            $stats[] = $m['cron_events'] . ' scheduled events';
        }
        ?>
        <section class="smcp-card" id="smcp-health">
            <div class="smcp-card__head smcp-card__head--row">
                <div>
                    <h2>Server health <?php if ($state['checked']) : ?><span class="smcp-pill smcp-pill--<?php echo $state['issues'] ? 'bad' : 'ok'; ?>"><span class="smcp-dot"></span><?php echo $state['issues'] ? 'Straining' : 'Normal'; ?></span><?php endif; ?></h2>
                    <p>Checked every hour<?php echo $state['checked'] ? ', last ' . esc_html(self::ago($state['checked'])) : ''; ?>. <?php echo $to ? 'Problems are emailed to ' . esc_html(implode(', ', $to)) . ', at most once a day each.' : 'Email alerts are off.'; ?><?php echo Site_MCP_Health::locked() ? ' Set in wp-config.php (SITE_MCP_ALERT_EMAIL).' : ''; ?></p>
                </div>
                <span class="smcp-theme-actions">
                    <?php if ($to) : ?>
                        <form method="post" action="<?php echo $form; ?>">
                            <?php wp_nonce_field('site_mcp_health_test'); ?>
                            <input type="hidden" name="action" value="site_mcp_health_test">
                            <button class="smcp-btn smcp-btn--ghost">Send test alert</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo $form; ?>">
                        <?php wp_nonce_field('site_mcp_health'); ?>
                        <input type="hidden" name="action" value="site_mcp_health">
                        <button class="smcp-btn smcp-btn--ghost">Check now</button>
                    </form>
                </span>
            </div>
            <?php if (!Site_MCP_Health::locked()) : ?>
                <form class="smcp-add" method="post" action="<?php echo $form; ?>">
                    <?php wp_nonce_field('site_mcp_alert_email'); ?>
                    <input type="hidden" name="action" value="site_mcp_alert_email">
                    <label for="smcp-alert-email">Alert email</label>
                    <div class="smcp-add__row">
                        <input id="smcp-alert-email" type="text" name="alert_email" value="<?php echo esc_attr((string) get_option(Site_MCP_Health::EMAIL_OPTION, '')); ?>" placeholder="<?php echo esc_attr(get_option('admin_email') . ' (the site\'s admin email)'); ?>">
                        <button class="smcp-btn smcp-btn--primary">Save</button>
                    </div>
                </form>
            <?php endif; ?>
            <?php if ($state['issues']) : ?>
                <div class="smcp-alert smcp-alert--error"><?php echo implode('<br>', array_map('esc_html', $state['issues'])); ?></div>
            <?php endif; ?>
            <?php if ($stats) : ?><p class="smcp-hint"><?php echo esc_html(implode(' · ', $stats)); ?></p><?php endif; ?>
            <?php if (!$rows) : ?>
                <p class="smcp-empty">No heavy requests in the last 24 hours.</p>
            <?php else : ?>
                <ul class="smcp-features">
                    <?php foreach ($rows as $r) : ?>
                        <li>
                            <strong><?php echo esc_html($r['path']); ?></strong>
                            <code><?php echo esc_html($r['kind'] . ' · ' . $r['times'] . '×'); ?></code>
                            <span><?php echo esc_html(sprintf('Worst %.1f s, %d MB, %d queries. Last %s: %s%s', $r['seconds'], $r['memory_mb'], $r['queries'], self::ago(strtotime($r['last'] . ' UTC')), $r['problem'], $r['detail'] !== '' ? '. ' . $r['detail'] : '')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="smcp-hint">Requests slower than <?php echo (int) Site_MCP_Health::SLOW_SECONDS; ?> s, near the PHP memory limit, over <?php echo (int) Site_MCP_Health::QUERIES; ?> database queries, or stopped by a limit, in the last 24 hours.</p>
            <?php endif; ?>
        </section>
        <?php
    }

    /** Turns the Site Features plugin back on after someone switched it off. */
    public static function features_on()
    {
        self::guard('site_mcp_features_on');
        if (!current_user_can('activate_plugins')) {
            wp_die('Not allowed.');
        }
        $result = activate_plugin(Site_MCP_Features::BASENAME);
        self::notice(is_wp_error($result) ? 'error' : 'ok', is_wp_error($result) ? "Couldn't turn on Site Features: " . $result->get_error_message() : 'Site Features is on again.');
        self::back();
    }

    /** Connection check: the things a host can get in the way of. Results are kept until the next run. */
    public static function check()
    {
        self::guard('site_mcp_check');
        $results = [];
        $add = function ($label, $ok, $problem) use (&$results) {
            $results[] = ['label' => $label, 'ok' => (bool) $ok, 'detail' => $ok ? '' : $problem];
        };
        $code = function ($response) {
            return is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
        };
        $json = function ($response) {
            return is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        };
        $args = ['timeout' => 15, 'redirection' => 0];

        $home = wp_remote_get(home_url('/'), $args + ['redirection' => 3]);
        if (is_wp_error($home)) {
            $add('The site can load its own pages', false, "WordPress couldn't reach its own address ({$home->get_error_message()}). The other checks can't run, and theme changes can't be tested before they're kept. Ask your host to allow loopback requests.");
            update_option('site_mcp_check', ['time' => time(), 'results' => $results], false);
            self::back();
        }

        $mcp = wp_remote_post(Site_MCP_Server::endpoint(), $args + ['headers' => ['Content-Type' => 'application/json'], 'body' => '{}']);
        $add('MCP endpoint answers and points to sign-in', !is_wp_error($mcp) && wp_remote_retrieve_response_code($mcp) === 401 && str_contains((string) wp_remote_retrieve_header($mcp, 'www-authenticate'), 'resource_metadata'), 'Expected a 401 with a sign-in pointer, got ' . $code($mcp) . '. A security plugin or firewall may be blocking the WordPress REST API.');

        $resource = wp_remote_get(Site_MCP_OAuth::resource_metadata_url(), $args);
        $add('Sign-in discovery (/.well-known/oauth-protected-resource)', ($json($resource)['resource'] ?? '') === Site_MCP_Server::endpoint(), 'Got ' . $code($resource) . '. The web server is answering /.well-known/ itself instead of passing it to WordPress. Ask your host to send /.well-known/oauth-* requests to index.php.');

        $server = wp_remote_get(Site_MCP_OAuth::issuer() . '/.well-known/oauth-authorization-server', $args);
        $add('Sign-in server (/.well-known/oauth-authorization-server)', ($json($server)['issuer'] ?? '') === Site_MCP_OAuth::issuer(), 'Got ' . $code($server) . '. Same fix as above.');

        $auth = wp_remote_get(rest_url('site-mcp/v1/check'), $args + ['headers' => ['Authorization' => 'Bearer site-mcp-check']]);
        $add('Authorization header reaches WordPress', !empty($json($auth)['authorization']), 'The web server drops the Authorization header, so Claude stays signed out. On Apache, add this line to .htaccess above the WordPress block: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1');

        $theme = Site_MCP_Code::theme()->unavailable();
        $add('Theme editing with version history', !$theme, (string) $theme . ' The other tools work without it.');
        $features = Site_MCP_Code::features()->unavailable();
        $add('Site Features with version history', !$features, (string) $features . ' The other tools work without it.');

        update_option('site_mcp_check', ['time' => time(), 'results' => $results], false);
        self::back();
    }

    /** Fluent Cart ships its MCP abilities switched off; this is the same switch as its Features page. */
    public static function fluent_cart()
    {
        self::guard('site_mcp_fluent_cart');
        if (class_exists('FluentCart\App\Modules\MCP\Support\PermissionGate')) {
            \FluentCart\App\Modules\MCP\Support\PermissionGate::setEnabled(true);
        }
        self::back(['site_mcp_saved' => 1]);
    }

    private static function fluent_cart_off()
    {
        return class_exists('FluentCart\App\Modules\MCP\Support\PermissionGate') && !\FluentCart\App\Modules\MCP\Support\PermissionGate::isEnabled();
    }

    private static function last_used($user_id)
    {
        $last = 0;
        foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
            if ($item['name'] === self::APP_NAME) {
                $last = max($last, (int) $item['last_used']);
            }
        }
        foreach (Site_MCP_OAuth::grants_for($user_id) as $grant) {
            $last = max($last, $grant['last_used'] ? strtotime($grant['last_used'] . ' UTC') : 0);
        }
        return $last;
    }

    private static function ago($ts)
    {
        return $ts ? human_time_diff($ts) . ' ago' : 'Never';
    }

    private static function icon($name)
    {
        $paths = [
            'people'   => '<path d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19"/><circle cx="10" cy="7.5" r="3.5"/><path d="M20 19v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.15a3.5 3.5 0 0 1 0 6.7"/>',
            'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
            'tools'    => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
            'history'  => '<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6"/><path d="M3 4v4h4"/><path d="M12 7.5V12l3 2"/>',
            'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
            'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
            'notes'    => '<path d="M5 4h10l4 4v12H5z"/><path d="M9 12h6M9 16h4"/>',
        ];
        return '<svg class="smcp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
    }

    /** Runs $fn, returning null if version history fails (the card then shows the error). */
    private static function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Site_MCP_Error $e) {
            return null;
        }
    }

    /** The latest versions of a code folder, each with an Undo button. */
    private static function history_card(Site_MCP_Code $code, $title, $intro, $form)
    {
        $error = null;
        $history = [];
        $versions = 0;
        if (!current_user_can($code->cap)) {
            $error = 'Only administrators can see this history.';
        } elseif ($reason = $code->unavailable()) {
            $error = $reason . ' Claude can still edit content, design, templates and styles.';
        } else {
            try {
                $history = $code->history(8);
                $versions = $code->count();
            } catch (Site_MCP_Error $e) {
                $error = $e->getMessage();
            }
        }
        ?>
        <section class="smcp-card">
            <div class="smcp-card__head">
                <h2><?php echo esc_html($title); ?></h2>
                <p><?php echo esc_html($intro); ?></p>
            </div>
            <?php if ($error) : ?>
                <p class="smcp-empty"><?php echo esc_html($error); ?></p>
            <?php elseif (!$history) : ?>
                <p class="smcp-empty">No versions yet.</p>
            <?php else : ?>
                <ol class="smcp-versions">
                    <?php foreach ($history as $v) : $count = count($v['files']); ?>
                        <li class="smcp-version">
                            <img class="smcp-avatar smcp-avatar--sm" src="<?php echo esc_url(get_avatar_url($v['email'], ['size' => 56])); ?>" alt="">
                            <div class="smcp-version__main">
                                <strong><?php echo esc_html($v['message']); ?></strong>
                                <span><?php echo esc_html($v['who'] . ' · ' . self::ago($v['time']) . ' · ' . ($count === 1 ? key($v['files']) : $count . ' files')); ?> · <code><?php echo esc_html($v['version']); ?></code></span>
                            </div>
                            <?php if (!$v['first']) : ?>
                                <form method="post" action="<?php echo $form; ?>" data-confirm="Undo “<?php echo esc_attr($v['message']); ?>”? Its changes are reversed and saved as a new version; later changes are kept.">
                                    <?php wp_nonce_field('site_mcp_revert'); ?>
                                    <input type="hidden" name="action" value="site_mcp_revert">
                                    <input type="hidden" name="target" value="<?php echo esc_attr($code->key); ?>">
                                    <input type="hidden" name="version" value="<?php echo esc_attr($v['hash']); ?>">
                                    <button class="smcp-btn smcp-btn--ghost">Undo</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($versions > count($history)) : ?>
                    <p class="smcp-hint">Showing the latest <?php echo count($history); ?> of <?php echo (int) $versions; ?>. Ask Claude for older versions (<?php echo esc_html($code->prefix); ?>_history).</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Themes being built beside the live one, their preview links, and the
     * last switch with a button to reverse it. Shown only when there is one.
     */
    private static function themes_card($form)
    {
        if (!current_user_can('edit_themes') || Site_MCP_Code::theme()->unavailable()) {
            return;
        }
        $building = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $code = Site_MCP_Code::theme($slug);
            if (!$code->active() && is_dir($code->git_dir())) {
                $last = self::safe(function () use ($code) {
                    return $code->history(1)[0] ?? null;
                });
                $building[] = ['slug' => $slug, 'name' => $theme->get('Name'), 'last' => $last, 'links' => count(array_filter(Site_MCP_Preview::links(), function ($l) use ($slug) {
                    return $l['theme'] === $slug;
                }))];
            }
        }
        $switch = Site_MCP_Switch::last();
        if (!$building && !$switch) {
            return;
        }
        ?>
        <section class="smcp-card">
            <div class="smcp-card__head">
                <h2>Themes</h2>
                <p>Themes other than the live one that have version history: a new one Claude is building, or the old one after a switch. Visitors only see them after a switch, and the last switch can be reversed here.</p>
            </div>
            <?php if ($switch) : $who = get_userdata($switch['user']); ?>
                <form method="post" action="<?php echo $form; ?>" class="smcp-alert smcp-alert--warn smcp-alert--row" data-confirm="Switch the live site back to <?php echo esc_attr(wp_get_theme($switch['from'])->get('Name')); ?>? Plugins the switch turned on or off are put back.">
                    <?php wp_nonce_field('site_mcp_switch_back'); ?>
                    <input type="hidden" name="action" value="site_mcp_switch_back">
                    <span><?php echo esc_html(sprintf('%s switched the live theme from %s to %s %s.%s', $who ? $who->display_name : 'Someone', wp_get_theme($switch['from'])->get('Name') ?: $switch['from'], wp_get_theme($switch['to'])->get('Name') ?: $switch['to'], self::ago($switch['time']), ($switch['deactivated'] || $switch['activated']) ? ' Plugins turned off: ' . (implode(', ', array_map('dirname', $switch['deactivated'])) ?: 'none') . '. Turned on: ' . (implode(', ', array_map('dirname', $switch['activated'])) ?: 'none') . '.' : '')); ?></span>
                    <?php if (current_user_can('switch_themes') && current_user_can('activate_plugins') && get_stylesheet() === $switch['to']) : ?><button class="smcp-btn smcp-btn--primary">Switch back</button><?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if ($building) : ?>
                <ul class="smcp-features">
                    <?php foreach ($building as $b) : ?>
                        <li>
                            <strong><?php echo esc_html($b['name']); ?></strong>
                            <span><?php echo esc_html(($b['last'] ? 'Last change ' . self::ago($b['last']['time']) . ': ' . $b['last']['message'] : 'No changes yet') . ' · ' . ($b['links'] ? $b['links'] . ' preview link' . ($b['links'] === 1 ? '' : 's') . ' shared' : 'not shared')); ?></span>
                            <span class="smcp-theme-actions">
                                <form method="post" action="<?php echo $form; ?>" target="_blank">
                                    <?php wp_nonce_field('site_mcp_preview'); ?>
                                    <input type="hidden" name="action" value="site_mcp_preview">
                                    <input type="hidden" name="theme" value="<?php echo esc_attr($b['slug']); ?>">
                                    <button class="smcp-btn smcp-btn--ghost">Preview</button>
                                </form>
                                <?php if ($b['links']) : ?>
                                    <form method="post" action="<?php echo $form; ?>" data-confirm="Turn off every preview link for <?php echo esc_attr($b['name']); ?>?">
                                        <?php wp_nonce_field('site_mcp_preview'); ?>
                                        <input type="hidden" name="action" value="site_mcp_preview">
                                        <input type="hidden" name="theme" value="<?php echo esc_attr($b['slug']); ?>">
                                        <input type="hidden" name="revoke" value="1">
                                        <button class="smcp-btn smcp-btn--ghost">Revoke links</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }

    /** The features Claude has built, and a switch back on if the plugin was turned off. */
    private static function features_card($form)
    {
        $code = Site_MCP_Code::features();
        $off = $code->exists() && !$code->active();
        $features = $code->exists() ? $code->catalog() : [];
        ?>
        <section class="smcp-card">
            <div class="smcp-card__head">
                <h2>Site Features</h2>
                <p>Custom functionality Claude builds for this site, such as post types, a CRM or email automations. It lives in its own plugin, Site Features, so it keeps working if the theme changes.</p>
            </div>
            <?php if ($off) : ?>
                <form method="post" action="<?php echo $form; ?>" class="smcp-alert smcp-alert--warn smcp-alert--row">
                    <?php wp_nonce_field('site_mcp_features_on'); ?>
                    <input type="hidden" name="action" value="site_mcp_features_on">
                    <span>The Site Features plugin is turned off, so none of these features are running and Claude can't change them.</span>
                    <?php if (current_user_can('activate_plugins')) : ?><button class="smcp-btn smcp-btn--primary">Turn on</button><?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (!$features) : ?>
                <p class="smcp-empty"><?php echo esc_html($code->exists() ? 'No features yet.' : 'No features yet. The plugin is created the first time someone asks Claude to build a feature.'); ?></p>
            <?php else : ?>
                <ul class="smcp-features">
                    <?php foreach ($features as $f) : ?>
                        <li>
                            <strong><?php echo esc_html($f['name']); ?></strong>
                            <span><?php echo esc_html($f['description'] ?: ($f['warning'] ?? '')); ?></span>
                            <code><?php echo esc_html('features/' . $f['slug']); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function render()
    {
        $with_access = get_users(['meta_key' => 'site_mcp_access', 'meta_value' => 1]);
        $access_ids = wp_list_pluck($with_access, 'ID');
        $candidates = array_filter(get_users(['capability' => 'edit_posts']), function ($u) use ($access_ids) {
            return !in_array($u->ID, $access_ids, true);
        });
        $groups = Site_MCP_Tools::enabled_groups();
        $counts = array_count_values(array_column(Site_MCP_Tools::all(), 'group'));
        $requires = ['store' => 'Fluent Cart', 'forms' => 'Fluent Forms'];
        $enabled_tools = array_sum(array_intersect_key($counts, array_flip($groups)));
        $theme = Site_MCP_Code::theme();
        $versions = current_user_can($theme->cap) && !$theme->unavailable() ? self::safe(function () use ($theme) {
            return $theme->count();
        }) : null;
        $notice = get_transient('site_mcp_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('site_mcp_notice_' . get_current_user_id());
        }
        $log = Site_MCP_Log::recent(40);
        $errors = ['user' => 'Pick a user who can edit posts.'];
        $toasts = ['site_mcp_saved' => 'Settings saved', 'site_mcp_revoked' => 'Access revoked. Their Claude has been disconnected.'];
        $granted = isset($_GET['site_mcp_granted']) ? get_userdata((int) $_GET['site_mcp_granted']) : null;
        if ($granted) {
            $toasts['site_mcp_granted'] = $granted->display_name . ' can now connect Claude. Send them the steps below.';
        }
        $endpoint = Site_MCP_Server::endpoint();
        $server_name = sanitize_title(get_bloginfo('name')) ?: 'wordpress';
        $form = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="smcp">
            <header class="smcp-head">
                <div class="smcp-brand">
                    <span class="smcp-logo"><?php echo self::icon('shield'); ?></span>
                    <div>
                        <h1>Site MCP</h1>
                        <p>Let people connect their own Claude to <?php echo esc_html(get_bloginfo('name')); ?>. Claude works with that person's WordPress permissions, never with shell or server access.</p>
                    </div>
                </div>
                <div class="smcp-endpoint">
                    <span class="smcp-pill smcp-pill--ok"><span class="smcp-dot"></span>Server online</span>
                    <button type="button" class="smcp-chip" data-copy="<?php echo esc_attr(Site_MCP_Server::endpoint()); ?>" title="Copy endpoint URL"><code><?php echo esc_html(wp_parse_url(Site_MCP_Server::endpoint(), PHP_URL_PATH)); ?></code><?php echo self::icon('copy'); ?></button>
                </div>
            </header>

            <?php if (isset($_GET['site_mcp_error'], $errors[$_GET['site_mcp_error']])) : ?>
                <div class="smcp-alert smcp-alert--error"><?php echo esc_html($errors[$_GET['site_mcp_error']]); ?></div>
            <?php endif; ?>
            <?php if ($notice && $notice[0] === 'error') : ?>
                <div class="smcp-alert smcp-alert--error"><?php echo esc_html($notice[1]); ?></div>
            <?php elseif ($notice) : ?>
                <div class="smcp-toast" role="status"><?php echo esc_html($notice[1]); ?></div>
            <?php endif; ?>
            <?php foreach ($toasts as $key => $text) : ?>
                <?php if (isset($_GET[$key])) : ?>
                    <div class="smcp-toast" role="status"><?php echo esc_html($text); ?></div>
                <?php endif; ?>
            <?php endforeach; ?>

            <section class="smcp-card smcp-new<?php echo $granted || !$with_access ? '' : ' smcp-new--quiet'; ?>">
                <div class="smcp-new__head">
                    <span class="smcp-badge smcp-badge--accent">How to connect</span>
                    <h2>Add <?php echo esc_html(get_bloginfo('name')); ?> to Claude</h2>
                    <p>Anyone you've given access below can follow these steps. They sign in with their own WordPress account; there's no password to send.</p>
                </div>
                <div class="smcp-code">
                    <code data-endpoint><?php echo esc_html($endpoint); ?></code>
                    <button type="button" class="smcp-btn smcp-btn--light" data-copy-from="[data-endpoint]"><?php echo self::icon('copy'); ?><span>Copy URL</span></button>
                </div>
                <div class="smcp-howto">
                    <div>
                        <h3>Claude app (web, desktop or phone)</h3>
                        <ol>
                            <li>Settings → Connectors → <strong>Add custom connector</strong></li>
                            <li>Name it <strong><?php echo esc_html(get_bloginfo('name')); ?></strong> and paste the URL above</li>
                            <li>Click Continue, log in to WordPress and <strong>Approve</strong></li>
                        </ol>
                    </div>
                    <div>
                        <h3>Claude Code</h3>
                        <ol>
                            <li>Run <code data-cc>claude mcp add --transport http --scope user <?php echo esc_html($server_name . ' ' . $endpoint); ?></code> <button type="button" class="smcp-mini" data-copy-from="[data-cc]" title="Copy command"><?php echo self::icon('copy'); ?></button></li>
                            <li>In <code>claude</code>, type <strong>/mcp</strong>, pick <?php echo esc_html($server_name); ?> and choose Authenticate</li>
                            <li>Log in to WordPress and <strong>Approve</strong></li>
                        </ol>
                    </div>
                </div>
                <?php $check = get_option('site_mcp_check'); $failed = $check ? array_filter($check['results'], function ($r) { return !$r['ok']; }) : []; ?>
                <div class="smcp-check">
                    <div class="smcp-check__head">
                        <strong>Connection check</strong>
                        <span><?php echo esc_html(!$check ? 'Checks that this server lets Claude connect. Run it once after installing.' : ($failed ? count($failed) . ' problem' . (count($failed) === 1 ? '' : 's') . ' found ' : 'Everything works · ') . ($check ? 'checked ' . self::ago($check['time']) : '')); ?></span>
                        <form method="post" action="<?php echo $form; ?>">
                            <?php wp_nonce_field('site_mcp_check'); ?>
                            <input type="hidden" name="action" value="site_mcp_check">
                            <button class="smcp-btn smcp-btn--light"><?php echo $check ? 'Check again' : 'Run check'; ?></button>
                        </form>
                    </div>
                    <?php if ($check) : ?>
                        <ul>
                            <?php foreach ($check['results'] as $r) : ?>
                                <li class="<?php echo $r['ok'] ? 'is-ok' : 'is-bad'; ?>"><span><?php echo esc_html($r['label']); ?></span><?php if ($r['detail']) : ?><small><?php echo esc_html($r['detail']); ?></small><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <section class="smcp-stats">
                <div class="smcp-stat">
                    <span class="smcp-stat__icon"><?php echo self::icon('people'); ?></span>
                    <span class="smcp-stat__label">People connected</span>
                    <strong class="smcp-stat__value"><?php echo count($with_access); ?></strong>
                </div>
                <div class="smcp-stat">
                    <span class="smcp-stat__icon"><?php echo self::icon('activity'); ?></span>
                    <span class="smcp-stat__label">Changes, last 7 days</span>
                    <strong class="smcp-stat__value"><?php echo Site_MCP_Log::count_since(7); ?></strong>
                </div>
                <div class="smcp-stat">
                    <span class="smcp-stat__icon"><?php echo self::icon('tools'); ?></span>
                    <span class="smcp-stat__label">Tools enabled</span>
                    <strong class="smcp-stat__value"><?php echo (int) $enabled_tools; ?><small> / <?php echo count(Site_MCP_Tools::all()); ?></small></strong>
                </div>
                <div class="smcp-stat">
                    <span class="smcp-stat__icon"><?php echo self::icon('history'); ?></span>
                    <span class="smcp-stat__label">Theme versions</span>
                    <strong class="smcp-stat__value"><?php echo $versions === null ? '—' : (int) $versions; ?></strong>
                </div>
            </section>

            <div class="smcp-grid">
                <section class="smcp-card">
                    <div class="smcp-card__head">
                        <h2>People with access</h2>
                        <p>Claude acts with each person's role. Editors manage content; administrators can also change templates, styles and settings.</p>
                    </div>
                    <ul class="smcp-people">
                        <?php if (!$with_access) : ?>
                            <li class="smcp-empty">Nobody is connected yet. Add someone below.</li>
                        <?php endif; ?>
                        <?php foreach ($with_access as $user) : $last = self::last_used($user->ID); ?>
                            <li class="smcp-person">
                                <img class="smcp-avatar" src="<?php echo esc_url(get_avatar_url($user->ID, ['size' => 80])); ?>" alt="">
                                <div class="smcp-person__main">
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                    <span><?php echo esc_html($user->user_email); ?></span>
                                    <?php $apps = array_unique(array_column(Site_MCP_OAuth::grants_for($user->ID), 'client')); ?>
                                    <?php if ($apps) : ?><span class="smcp-apps"><?php echo esc_html('Connected: ' . implode(', ', $apps)); ?></span><?php endif; ?>
                                </div>
                                <span class="smcp-badge"><?php echo esc_html(ucfirst(implode(', ', $user->roles))); ?></span>
                                <span class="smcp-person__seen <?php echo $last && time() - $last < DAY_IN_SECONDS ? 'is-recent' : ''; ?>"><?php echo esc_html($last ? 'Active ' . self::ago($last) : 'Not connected yet'); ?></span>
                                <form method="post" action="<?php echo $form; ?>" data-confirm="Revoke Claude access for <?php echo esc_attr($user->display_name); ?>? Every app they connected is disconnected immediately.">
                                    <?php wp_nonce_field('site_mcp_revoke'); ?>
                                    <input type="hidden" name="action" value="site_mcp_revoke">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user->ID; ?>">
                                    <button class="smcp-btn smcp-btn--ghost-danger">Revoke</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="smcp-add" method="post" action="<?php echo $form; ?>">
                        <?php wp_nonce_field('site_mcp_connect'); ?>
                        <input type="hidden" name="action" value="site_mcp_connect">
                        <label for="smcp-user">Give someone access</label>
                        <div class="smcp-add__row">
                            <select id="smcp-user" name="user_id" <?php disabled(!$candidates); ?>>
                                <?php if (!$candidates) : ?>
                                    <option>Everyone who can edit already has access</option>
                                <?php endif; ?>
                                <?php foreach ($candidates as $user) : ?>
                                    <option value="<?php echo (int) $user->ID; ?>"><?php echo esc_html($user->display_name . ' · ' . ucfirst(implode(', ', $user->roles))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="smcp-btn smcp-btn--primary" <?php disabled(!$candidates); ?>>Give access</button>
                        </div>
                    </form>
                </section>

                <?php self::history_card($theme, 'Theme history', 'Every change to the theme\'s code is saved as a version. Undo reverses one change and keeps everything made since.', $form); ?>
            </div>

            <?php self::themes_card($form); ?>
            <?php self::backups_card($form); ?>
            <?php self::health_card($form); ?>

            <div class="smcp-grid">
                <?php self::features_card($form); ?>
                <?php self::history_card(Site_MCP_Code::features(), 'Feature history', 'Every change to Site Features is saved as a version, the same way as the theme.', $form); ?>
            </div>

            <?php if (self::fluent_cart_off()) : ?>
                <form method="post" action="<?php echo $form; ?>" class="smcp-alert smcp-alert--warn smcp-alert--row">
                    <?php wp_nonce_field('site_mcp_fluent_cart'); ?>
                    <input type="hidden" name="action" value="site_mcp_fluent_cart">
                    <span>Fluent Cart's own tools (orders, customers, coupons, reports) are switched off in Fluent Cart. Turn them on so Claude can use them.</span>
                    <button class="smcp-btn smcp-btn--primary">Turn on</button>
                </form>
            <?php endif; ?>

            <form method="post" action="<?php echo $form; ?>" class="smcp-card">
                <?php wp_nonce_field('site_mcp_settings'); ?>
                <input type="hidden" name="action" value="site_mcp_settings">
                <div class="smcp-card__head smcp-card__head--row">
                    <div>
                        <h2>What Claude can do</h2>
                        <p>Turn off whole groups of tools. Each tool still checks the person's own permissions.</p>
                    </div>
                    <button class="smcp-btn smcp-btn--primary">Save changes</button>
                </div>
                <div class="smcp-toggles">
                    <?php foreach (Site_MCP_Tools::GROUPS as $key => $label) : ?>
                        <label class="smcp-toggle">
                            <input type="checkbox" name="groups[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $groups, true)); ?>>
                            <span class="smcp-switch" aria-hidden="true"></span>
                            <span class="smcp-toggle__text">
                                <strong><?php echo esc_html(ucfirst($key)); ?> <em><?php echo (int) ($counts[$key] ?? 0); ?> tools</em></strong>
                                <span><?php echo esc_html($label); ?></span>
                                <?php if (in_array($key, ['theme', 'features'], true) && ($reason = ($key === 'theme' ? Site_MCP_Code::theme() : Site_MCP_Code::features())->unavailable())) : ?><span class="smcp-toggle__warn"><?php echo esc_html('Unavailable here: ' . $reason); ?></span><?php endif; ?>
                                <?php if ($key === 'design' && !wp_is_block_theme()) : ?><span class="smcp-toggle__warn">Only for block themes. This site's theme is a classic PHP theme, so its design is changed in the theme's code.</span><?php endif; ?>
                                <?php if (isset($requires[$key]) && empty($counts[$key])) : ?><span class="smcp-toggle__warn"><?php echo esc_html('Needs ' . $requires[$key] . ', which isn\'t active on this site.'); ?></span><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="smcp-field">
                    <label for="smcp-notes"><?php echo self::icon('notes'); ?> Notes for Claude</label>
                    <p>Every connected Claude reads these first: brand voice, how the theme is organised, things never to change.</p>
                    <textarea id="smcp-notes" name="instructions" rows="5"><?php echo esc_textarea(get_option('site_mcp_instructions', '')); ?></textarea>
                </div>

            </form>

            <section class="smcp-card">
                <div class="smcp-card__head">
                    <h2>Recent activity</h2>
                    <p>Every change Claude makes, and every failed attempt.</p>
                </div>
                <?php if (!$log) : ?>
                    <p class="smcp-empty">No changes yet. They'll appear here as soon as someone's Claude edits the site.</p>
                <?php else : ?>
                    <ol class="smcp-feed">
                        <?php foreach ($log as $row) : ?>
                            <li class="smcp-feed__item <?php echo $row['status'] === 'error' ? 'is-error' : ''; ?>">
                                <img class="smcp-avatar smcp-avatar--sm" src="<?php echo esc_url(get_avatar_url($row['user_id'], ['size' => 56])); ?>" alt="">
                                <div class="smcp-feed__body">
                                    <div class="smcp-feed__line">
                                        <strong><?php echo esc_html($row['user']); ?></strong>
                                        <code class="smcp-tool"><?php echo esc_html($row['tool']); ?></code>
                                        <?php if ($row['status'] === 'error') : ?><span class="smcp-badge smcp-badge--bad">Failed</span><?php endif; ?>
                                        <time title="<?php echo esc_attr($row['created_at']); ?>"><?php echo esc_html(self::ago($row['ts'])); ?></time>
                                    </div>
                                    <?php if ($row['message'] !== '') : ?><p><?php echo esc_html($row['message']); ?></p><?php endif; ?>
                                    <details><summary>Details</summary><pre><?php echo esc_html(wp_json_encode(json_decode($row['args']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre></details>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }
}
