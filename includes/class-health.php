<?php
defined('ABSPATH') || exit;

/**
 * Server health: notices when the site is straining its server (usually a
 * custom feature doing too much) and emails whoever looks after it.
 *
 * Each request is timed at shutdown, and only heavy ones (slow, near the
 * memory limit, very many queries, or killed by a memory or time limit) are
 * saved, so normal traffic costs no database writes. An hourly check adds
 * the server's load, memory and disk, the database's growth and the number
 * of scheduled events, and sends one email per problem a day.
 */
class Site_MCP_Health
{
    const DB_VERSION = '1';
    const OPTION = 'site_mcp_health';
    const HOOK = 'site_mcp_health_check';
    const KEEP_DAYS = 14;

    // A request is heavy above any of these.
    const SLOW_SECONDS = 5;
    const MEMORY_SHARE = 0.8;
    const QUERIES = 1000;

    // The hourly check reports a problem above any of these.
    const HEAVY_PER_HOUR = 10;
    const LOAD_PER_CPU = 1.5;     // 15-minute load average, on two checks in a row
    const MEMORY_FREE = 0.1;      // share of RAM available, on two checks in a row
    const DISK_FREE = 0.1;        // share of the disk free
    const DB_GROWTH_MB = 200;     // database growth in a day
    const CRON_EVENTS = 1000;     // scheduled events: more usually means a runaway scheduler

    // Who to email: set on Settings → Site MCP, else the site's admin email. SITE_MCP_ALERT_EMAIL in wp-config.php overrides both.
    const EMAIL_OPTION = 'site_mcp_alert_email';

    /** Set while Site MCP itself does something known to be slow, such as a backup. */
    public static $exempt = false;

    private static $cron_due = [];

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'site_mcp_heavy';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            kind varchar(10) NOT NULL,
            path varchar(255) NOT NULL,
            seconds decimal(8,2) NOT NULL,
            memory_mb int unsigned NOT NULL,
            queries int unsigned NOT NULL,
            problem varchar(255) NOT NULL,
            detail text NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};");
        update_option('site_mcp_health_db', self::DB_VERSION);
    }

    public static function boot()
    {
        if (get_option('site_mcp_health_db') !== self::DB_VERSION) {
            self::install();
        }
        add_action(self::HOOK, [__CLASS__, 'check']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        // The events a cron run is about to run, since they're rescheduled by the time it ends.
        if (wp_doing_cron()) {
            foreach (_get_cron_array() ?: [] as $time => $hooks) {
                if ($time <= time()) {
                    self::$cron_due = array_merge(self::$cron_due, array_keys($hooks));
                }
            }
        }
        register_shutdown_function([__CLASS__, 'record']);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Saves this request if it was heavy. Runs at shutdown, including after a fatal error. */
    public static function record()
    {
        global $wpdb;
        if (self::$exempt || empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            return;
        }
        $seconds = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $memory = memory_get_peak_usage(true);
        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        $queries = (int) ($wpdb->num_queries ?? 0);
        $error = error_get_last();
        $problems = [];
        $detail = '';
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (str_contains($error['message'], 'Allowed memory size')) {
                $problems[] = 'Ran out of memory';
            } elseif (str_contains($error['message'], 'Maximum execution time')) {
                $problems[] = 'Hit the time limit';
            }
            if ($problems) {
                $detail = 'In ' . self::short_path($error['file']) . ':' . $error['line'];
            }
        }
        if ($seconds > self::SLOW_SECONDS) {
            $problems[] = sprintf('Took %.1f s', $seconds);
        }
        if ($limit > 0 && $memory > $limit * self::MEMORY_SHARE && !preg_grep('/memory/', $problems)) {
            $problems[] = 'Used ' . size_format($memory) . ' of ' . size_format($limit) . ' memory';
        }
        if ($queries > self::QUERIES) {
            $problems[] = "$queries database queries";
        }
        if (!$problems) {
            return;
        }
        [$kind, $path] = self::describe_request();
        // Updates and installs are expected to be slow.
        if ($kind === 'admin' && preg_match('#/wp-admin/(update|update-core|plugin-install|theme-install)\.php#', $path)) {
            return;
        }
        if ($kind === 'cron' && self::$cron_due) {
            $detail = trim('Due: ' . implode(', ', array_slice(array_unique(self::$cron_due), 0, 15)) . ". $detail");
        }
        $wpdb->insert(self::table(), [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'kind'       => $kind,
            'path'       => substr($path, 0, 255),
            'seconds'    => round($seconds, 2),
            'memory_mb'  => (int) round($memory / MB_IN_BYTES),
            'queries'    => $queries,
            'problem'    => substr(implode(', ', $problems), 0, 255),
            'detail'     => $detail,
        ]);
    }

    /** [kind, path] for this request: the path without its query string, plus the action for admin-ajax. */
    private static function describe_request()
    {
        $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (wp_doing_cron()) {
            return ['cron', $path ?: '/wp-cron.php'];
        }
        if (wp_doing_ajax()) {
            return ['ajax', $path . (isset($_REQUEST['action']) ? '?action=' . sanitize_key($_REQUEST['action']) : '')];
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return ['rest', $path];
        }
        return [is_admin() ? 'admin' : 'page', $path];
    }

    private static function short_path($file)
    {
        return str_starts_with($file, ABSPATH) ? substr($file, strlen(ABSPATH)) : $file;
    }

    /* ---------- The hourly check ---------- */

    public static function state()
    {
        return (array) get_option(self::OPTION, []) + ['checked' => 0, 'metrics' => [], 'issues' => [], 'emailed' => [], 'strained' => [], 'db_sizes' => []];
    }

    /** Measures the server, saves the result, and emails about new problems. Returns the saved state. */
    public static function check()
    {
        global $wpdb;
        $state = self::state();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));

        $m = self::metrics();
        $issues = [];

        $hour = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS heavy, SUM(problem LIKE %s OR problem LIKE %s) AS killed FROM ' . self::table() . ' WHERE created_at > %s', 'Ran out%', 'Hit the%', gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)), ARRAY_A);
        if ((int) $hour['killed'] > 0) {
            $issues['killed'] = "{$hour['killed']} request(s) in the last hour were stopped for running out of memory or time.";
        }
        if ((int) $hour['heavy'] >= self::HEAVY_PER_HOUR) {
            $issues['heavy'] = "{$hour['heavy']} heavy requests in the last hour (slower than " . self::SLOW_SECONDS . ' s, near the memory limit, or over ' . self::QUERIES . ' queries).';
        }

        // Load and memory swing, so they count only when high on two checks in a row.
        $strained = [];
        if (isset($m['load_per_cpu']) && $m['load_per_cpu'] > self::LOAD_PER_CPU) {
            $strained['load'] = sprintf('The server has been busy: load %.2f on %d CPU(s) over 15 minutes, on two checks an hour apart.', $m['load'], $m['cpus']);
        }
        if (isset($m['memory_free']) && $m['memory_free'] < self::MEMORY_FREE) {
            $strained['memory'] = sprintf('The server is short of memory: %d%% of %s available, on two checks an hour apart.', $m['memory_free'] * 100, size_format($m['memory_total']));
        }
        $issues += array_intersect_key($strained, $state['strained']);
        $state['strained'] = array_fill_keys(array_keys($strained), true);

        if (isset($m['disk_free']) && $m['disk_free'] < self::DISK_FREE) {
            $issues['disk'] = sprintf('The disk is nearly full: %s free of %s.', size_format($m['disk_free_bytes']), size_format($m['disk_total']));
        }

        // One database size a day, for a week.
        $today = gmdate('Y-m-d');
        $state['db_sizes'][$today] = $m['database'];
        $state['db_sizes'] = array_slice($state['db_sizes'], -8, null, true);
        $yesterday = $state['db_sizes'][gmdate('Y-m-d', time() - DAY_IN_SECONDS)] ?? null;
        if ($yesterday !== null && $m['database'] - $yesterday > self::DB_GROWTH_MB * MB_IN_BYTES) {
            $issues['database'] = 'The database grew by ' . size_format($m['database'] - $yesterday) . ' since yesterday, to ' . size_format($m['database']) . '.';
        }

        if ($m['cron_events'] > self::CRON_EVENTS) {
            $top = array_map(function ($hook, $n) {
                return "$hook ($n)";
            }, array_keys($m['cron_top']), $m['cron_top']);
            $issues['cron'] = "{$m['cron_events']} scheduled events are queued, which usually means something schedules a new one on every page load. Most common: " . implode(', ', $top) . '.';
        }

        $state['checked'] = time();
        $state['metrics'] = $m;
        $previous = $state['issues'];
        $state['issues'] = $issues;

        // Each problem is emailed at most once a day, and the all-clear once after an alert.
        $state['emailed'] = array_filter($state['emailed'], function ($time) {
            return time() - $time < DAY_IN_SECONDS - 5 * MINUTE_IN_SECONDS;
        });
        if (array_diff_key($issues, $state['emailed']) && self::email(self::problem_email($issues))) {
            $state['emailed'] = array_fill_keys(array_keys($issues), time()) + $state['emailed'];
            $state['alerting'] = true;
        } elseif (!$issues && $previous && !empty($state['alerting'])) {
            self::email(self::recovered_email($previous));
            $state['alerting'] = false;
        }
        update_option(self::OPTION, $state, false);
        return $state;
    }

    /** What the server and site look like now. Values the host hides are left out. */
    public static function metrics()
    {
        global $wpdb;
        $m = [];
        $cpus = self::cpus();
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if ($load && $cpus) {
            $m += ['load' => round($load[2], 2), 'cpus' => $cpus, 'load_per_cpu' => round($load[2] / $cpus, 2)];
        }
        $info = @is_readable('/proc/meminfo') ? (string) @file_get_contents('/proc/meminfo') : '';
        if (preg_match('/^MemTotal:\s+(\d+)/m', $info, $total) && preg_match('/^MemAvailable:\s+(\d+)/m', $info, $available) && $total[1] > 0) {
            $m += ['memory_total' => $total[1] * KB_IN_BYTES, 'memory_free' => round($available[1] / $total[1], 3)];
        }
        $free = @disk_free_space(ABSPATH);
        $size = @disk_total_space(ABSPATH);
        if ($free !== false && $size) {
            $m += ['disk_free_bytes' => $free, 'disk_total' => $size, 'disk_free' => round($free / $size, 3)];
        }
        $m['database'] = (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like($wpdb->prefix) . '%'));
        $hooks = [];
        foreach (_get_cron_array() ?: [] as $events) {
            foreach ($events as $hook => $instances) {
                $hooks[$hook] = ($hooks[$hook] ?? 0) + count($instances);
            }
        }
        arsort($hooks);
        $m['cron_events'] = array_sum($hooks);
        $m['cron_top'] = array_slice($hooks, 0, 5, true);
        $m['php_memory_limit'] = (string) ini_get('memory_limit');
        return $m;
    }

    private static function cpus()
    {
        $info = @is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
        return preg_match_all('/^processor\s*:/m', $info) ?: null;
    }

    /** Heavy requests since $since, grouped by path, worst first. */
    public static function heavy($since, $limit = 15)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT kind, path, COUNT(*) AS times, MAX(seconds) AS seconds, MAX(memory_mb) AS memory_mb, MAX(queries) AS queries, SUBSTRING_INDEX(GROUP_CONCAT(problem ORDER BY id DESC SEPARATOR %s), %s, 1) AS problem, SUBSTRING_INDEX(GROUP_CONCAT(detail ORDER BY id DESC SEPARATOR %s), %s, 1) AS detail, MAX(created_at) AS last '
            . 'FROM ' . self::table() . ' WHERE created_at > %s GROUP BY kind, path ORDER BY times DESC, seconds DESC LIMIT %d',
            "\x1e", "\x1e", "\x1e", "\x1e", gmdate('Y-m-d H:i:s', $since), $limit
        ), ARRAY_A);
    }

    /* ---------- Email ---------- */

    /** The alert addresses: SITE_MCP_ALERT_EMAIL (empty turns alerts off), else the saved setting, else the site's admin email. */
    public static function recipients()
    {
        if (self::locked()) {
            $to = (string) SITE_MCP_ALERT_EMAIL;
        } else {
            $to = (string) get_option(self::EMAIL_OPTION, '') ?: (string) get_option('admin_email');
        }
        return array_values(array_filter(array_map('trim', explode(',', $to)), 'is_email'));
    }

    /** Whether wp-config.php sets the alert addresses, so they can't be changed from the dashboard. */
    public static function locked()
    {
        return defined('SITE_MCP_ALERT_EMAIL');
    }

    /** Saves the alert addresses (comma-separated). Empty means the site's admin email. Returns the invalid ones. */
    public static function set_recipients($input)
    {
        $all = array_filter(array_map('trim', explode(',', (string) $input)));
        $valid = array_filter($all, 'is_email');
        update_option(self::EMAIL_OPTION, implode(', ', array_map('sanitize_email', $valid)), false);
        return array_values(array_diff($all, $valid));
    }

    public static function email(array $mail)
    {
        $to = self::recipients();
        return $to && wp_mail($to, $mail[0], $mail[1]);
    }

    private static function problem_email(array $issues)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $body = 'Site MCP found ' . (count($issues) === 1 ? 'a problem' : count($issues) . ' problems') . ' with ' . home_url('/') . ":\n\n";
        foreach ($issues as $text) {
            $body .= "- $text\n";
        }
        $rows = self::heavy(time() - DAY_IN_SECONDS, 10);
        if ($rows) {
            $body .= "\nHeaviest requests in the last 24 hours:\n\n";
            foreach ($rows as $r) {
                $body .= sprintf("%dx  %s %s\n     worst %.1f s, %d MB, %d queries. Last: %s%s\n", $r['times'], $r['kind'], $r['path'], $r['seconds'], $r['memory_mb'], $r['queries'], $r['problem'], $r['detail'] !== '' ? ". {$r['detail']}" : '');
            }
        }
        $body .= self::metrics_text(self::metrics());
        $features = Site_MCP_Code::features();
        if ($features->exists() && !$features->unavailable()) {
            $recent = self::safe_history($features);
            if ($recent) {
                $body .= "\nRecent Site Features changes:\n\n";
                foreach ($recent as $v) {
                    $body .= "- {$v['when']}, {$v['who']}: {$v['message']}\n";
                }
            }
        }
        $body .= "\nDetails: " . admin_url('options-general.php?page=' . Site_MCP_Admin::PAGE . '#smcp-health') . "\nYou'll hear about each problem at most once a day, and once more when they've all cleared.\n";
        return ["[$site] Site is straining its server", $body];
    }

    private static function recovered_email(array $previous)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return ["[$site] Server back to normal", 'The problems Site MCP emailed about on ' . home_url('/') . " have cleared:\n\n- " . implode("\n- ", $previous) . "\n" . self::metrics_text(self::metrics())];
    }

    private static function metrics_text(array $m)
    {
        $lines = [];
        if (isset($m['load'])) {
            $lines[] = sprintf('Load (15 min): %.2f on %d CPU(s)', $m['load'], $m['cpus']);
        }
        if (isset($m['memory_free'])) {
            $lines[] = sprintf('Memory available: %d%% of %s', $m['memory_free'] * 100, size_format($m['memory_total']));
        }
        if (isset($m['disk_free'])) {
            $lines[] = 'Disk free: ' . size_format($m['disk_free_bytes']) . ' of ' . size_format($m['disk_total']);
        }
        $lines[] = 'Database: ' . size_format($m['database']);
        $lines[] = "Scheduled events: {$m['cron_events']}";
        $lines[] = "PHP memory limit: {$m['php_memory_limit']}";
        return "\nServer now:\n\n" . implode("\n", $lines) . "\n";
    }

    private static function safe_history(Site_MCP_Code $code)
    {
        try {
            return array_slice($code->history(5), 0, 5);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A test email to check that alerts arrive. */
    public static function test_email()
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return self::email(["[$site] Site MCP test alert", 'This is a test of the server health alerts for ' . home_url('/') . ". Real alerts look similar and list what's straining the server.\n" . self::metrics_text(self::metrics())]);
    }
}
