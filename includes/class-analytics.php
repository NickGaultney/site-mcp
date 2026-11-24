<?php
defined('ABSPATH') || exit;

/**
 * Built-in, cookie-free analytics, replacing Site Kit / Google Analytics.
 *
 * A small inline script sends one beacon per page view to a REST route, so pages served from a page
 * cache are counted too. Nothing is stored in the browser, and no IP address is kept: a visitor is
 * a hash of IP, browser and site under a salt that is replaced every day, so the same person counts
 * once a day and can't be followed from one day to the next. Signed-in staff and bots aren't counted.
 *
 * Conversions are recorded in the buyer's own request, so they can be credited to the source the
 * visitor arrived from that day: Fluent Forms submissions, WooCommerce orders (thank-you page) and
 * Fluent Cart orders (receipt page).
 *
 * Data: one table, {prefix}site_mcp_hits, kept for 25 months. Reports read it directly.
 */
class Site_MCP_Analytics
{
    const OPTION = 'site_mcp_analytics';
    const DB_VERSION = '1';
    const KEEP_MONTHS = 25;
    const BOTS = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|pingdom|uptime|monitor|preview|scan|facebookexternalhit|embedly|curl|wget|python|java\/|httpclient|axios|node-fetch|go-http|okhttp|phantom|selenium|puppeteer|playwright/i';
    /** Referrer hosts named for people, checked in order (the first match wins). */
    const SOURCES = [
        'gemini.google.' => 'Gemini', 'google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'search.yahoo.' => 'Yahoo',
        'search.brave.com' => 'Brave Search', 'ecosia.org' => 'Ecosia', 'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude', 'copilot.microsoft.com' => 'Copilot',
        'facebook.com' => 'Facebook', 'fb.me' => 'Facebook', 'instagram.com' => 'Instagram', 'threads.net' => 'Threads',
        't.co' => 'X', 'x.com' => 'X', 'twitter.com' => 'X', 'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn',
        'pinterest.' => 'Pinterest', 'youtube.com' => 'YouTube', 'reddit.com' => 'Reddit', 'tiktok.com' => 'TikTok',
        'nextdoor.com' => 'Nextdoor', 'yelp.com' => 'Yelp', 'etsy.com' => 'Etsy',
    ];

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'site_mcp_hits';
    }

    public static function enabled()
    {
        return get_option(self::OPTION, '1') === '1';
    }

    public static function boot()
    {
        self::maybe_install();
        if (self::enabled()) {
            add_action('wp_footer', [__CLASS__, 'tracker'], 99);
            add_action('rest_api_init', [__CLASS__, 'routes']);
            add_action('fluentform/submission_inserted', [__CLASS__, 'on_form'], 10, 3);
            add_action('woocommerce_thankyou', [__CLASS__, 'on_woo_order']);
            add_action('fluent_cart/order/receipt_viewed', [__CLASS__, 'on_fluent_cart_order']);
        }
        // Reports stay readable after analytics is turned off.
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_dashboard_setup', [__CLASS__, 'dashboard_widget']);
        add_action(Site_MCP_Health::HOOK, [__CLASS__, 'prune']);
    }

    public static function maybe_install()
    {
        if (get_option('site_mcp_analytics_db') === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created datetime NOT NULL,
            day date NOT NULL,
            visitor char(16) NOT NULL,
            type varchar(16) NOT NULL DEFAULT 'pageview',
            path varchar(255) NOT NULL DEFAULT '',
            referrer varchar(191) NOT NULL DEFAULT '',
            source varchar(100) NOT NULL DEFAULT '',
            medium varchar(100) NOT NULL DEFAULT '',
            campaign varchar(150) NOT NULL DEFAULT '',
            device varchar(8) NOT NULL DEFAULT '',
            browser varchar(24) NOT NULL DEFAULT '',
            os varchar(24) NOT NULL DEFAULT '',
            tz varchar(48) NOT NULL DEFAULT '',
            label varchar(191) NOT NULL DEFAULT '',
            value decimal(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY day_type (day, type),
            KEY day_visitor (day, visitor),
            KEY type_label (type, label(40))
        ) " . $wpdb->get_charset_collate());
        update_option('site_mcp_analytics_db', self::DB_VERSION);
    }

    /** Deletes data older than KEEP_MONTHS, once a day. */
    public static function prune()
    {
        if (get_transient('site_mcp_analytics_pruned')) {
            return;
        }
        set_transient('site_mcp_analytics_pruned', 1, DAY_IN_SECONDS);
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s', wp_date('Y-m-d', strtotime('-' . self::KEEP_MONTHS . ' months'))));
    }

    /* ---------- Collecting ---------- */

    /** Signed-in staff, bots, previews and admin screens aren't counted. */
    private static function countable()
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua !== '' && !preg_match(self::BOTS, $ua) && !current_user_can('edit_posts') && !is_preview() && !is_customize_preview();
    }

    public static function tracker()
    {
        if (!self::countable() || is_admin() || is_feed() || is_robots() || is_embed()) {
            return;
        }
        $url = esc_url_raw(rest_url('site-mcp/v1/hit'));
        $nf = is_404() ? ',n:1' : '';
        // Excluded from delay-JS and combining by LiteSpeed, WP Rocket, Perfmatters and Cloudflare, so a visit is
        // counted even if the visitor never scrolls or clicks.
        echo "<script data-no-optimize=\"1\" data-no-defer=\"1\" data-no-minify=\"1\" data-cfasync=\"false\" nowprocket>(()=>{"
            . "const go=()=>{if(navigator.webdriver)return;const u=new URL(location.href),q=u.searchParams,d={p:u.pathname,r:document.referrer,w:innerWidth{$nf}};"
            . "try{d.z=Intl.DateTimeFormat().resolvedOptions().timeZone||''}catch(e){}"
            . "['source','medium','campaign'].forEach(k=>{const v=q.get('utm_'+k);if(v)d[k]=v});"
            . "const b=JSON.stringify(d),t=" . wp_json_encode($url) . ";"
            . "navigator.sendBeacon?navigator.sendBeacon(t,b):fetch(t,{method:'POST',body:b,keepalive:true})};"
            . "document.prerendering?document.addEventListener('prerenderingchange',go,{once:true}):go()})();</script>\n";
    }

    public static function routes()
    {
        register_rest_route('site-mcp/v1', '/hit', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'hit'],
        ]);
    }

    /** The visitor's IP, from a proxy header when the site is behind one. Used for the hash only, never stored. */
    private static function ip()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', (string) $_SERVER[$key])[0]);
            }
        }
        return '';
    }

    /** Today's anonymous visitor id: changes every day, and the salt that made yesterday's is gone. */
    private static function visitor()
    {
        $today = wp_date('Y-m-d');
        $salt = (array) get_option('site_mcp_analytics_salt', []);
        if (($salt['day'] ?? '') !== $today) {
            $salt = ['day' => $today, 'salt' => wp_generate_password(64, true, true)];
            update_option('site_mcp_analytics_salt', $salt, true);
        }
        return substr(hash_hmac('sha256', self::ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . home_url(), $salt['salt']), 0, 16);
    }

    private static function clip($value, $length)
    {
        $value = trim(wp_strip_all_tags((string) $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /** Referrer host → the name people know it by (Google, Facebook…), else the host without www. */
    public static function source_of($host)
    {
        foreach (self::SOURCES as $match => $name) {
            if (str_starts_with($host, $match) || str_contains($host, '.' . $match) || ($match[strlen($match) - 1] !== '.' && str_ends_with($host, $match))) {
                return $name;
            }
        }
        return preg_replace('/^(www|m|l|lm)\./', '', $host);
    }

    private static function client()
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $browser = 'Other';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                break;
            }
        }
        $os = 'Other';
        foreach (['iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android', 'Windows' => 'Windows', 'CrOS' => 'ChromeOS', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (str_contains($ua, $needle)) {
                $os = $name;
                break;
            }
        }
        return [$browser, $os];
    }

    private static function insert(array $row)
    {
        global $wpdb;
        [$browser, $os] = self::client();
        $wpdb->insert(self::table(), $row + [
            'created' => current_time('mysql', true),
            'day'     => wp_date('Y-m-d'),
            'visitor' => self::visitor(),
            'browser' => $browser,
            'os'      => $os,
        ]);
    }

    public static function hit(WP_REST_Request $request)
    {
        $none = new WP_REST_Response(null, 204);
        if (!self::countable()) {
            return $none;
        }
        $home = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $origin = (string) wp_parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST);
        if ($origin !== '' && $origin !== $home) {
            return $none;
        }
        $d = json_decode($request->get_body(), true);
        if (!is_array($d) || !isset($d['p']) || !is_string($d['p'])) {
            return $none;
        }
        global $wpdb;
        $visitor = self::visitor();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE day = %s AND visitor = %s', wp_date('Y-m-d'), $visitor)) >= 500) {
            return $none; // runaway or abusive client
        }
        $ref = (string) wp_parse_url((string) ($d['r'] ?? ''), PHP_URL_HOST);
        $ref = preg_replace('/^www\./', '', strtolower($ref));
        if ($ref === preg_replace('/^www\./', '', strtolower($home))) {
            $ref = ''; // moving around the site
        }
        $width = (int) ($d['w'] ?? 0);
        self::insert([
            'type'     => 'pageview',
            'path'     => self::clip('/' . ltrim(rawurldecode($d['p']), '/'), 255),
            'referrer' => self::clip($ref, 191),
            'source'   => self::clip(!empty($d['source']) ? $d['source'] : ($ref !== '' ? self::source_of($ref) : ''), 100),
            'medium'   => self::clip($d['medium'] ?? '', 100),
            'campaign' => self::clip($d['campaign'] ?? '', 150),
            'device'   => $width === 0 ? '' : ($width < 768 ? 'Mobile' : ($width < 1024 ? 'Tablet' : 'Desktop')),
            'tz'       => self::clip($d['z'] ?? '', 48),
            'label'    => !empty($d['n']) ? '404' : '',
        ]);
        return $none;
    }

    /* ---------- Conversions, in the visitor's own request ---------- */

    private static function conversion($type, $label, $value, $once_key)
    {
        if (!self::countable()) {
            return;
        }
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE type = %s AND label = %s LIMIT 1', $type, $once_key))) {
            return;
        }
        self::insert(['type' => $type, 'label' => self::clip($once_key, 191), 'path' => self::clip($label, 255), 'value' => (float) $value]);
    }

    public static function on_form($entry_id, $data, $form)
    {
        // Every submission counts; the label just keeps each one distinct.
        self::conversion('form', $form->title ?? 'Form', 0, 'form:' . (int) $entry_id);
    }

    public static function on_woo_order($order_id)
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if ($order && !$order->has_status('failed')) {
            self::conversion('order', 'WooCommerce order', (float) $order->get_total(), 'woo:' . (int) $order_id);
        }
    }

    public static function on_fluent_cart_order($data)
    {
        $order = $data['order'] ?? null;
        if ($order && isset($order->id)) {
            self::conversion('order', 'Fluent Cart order', ((float) $order->total_amount) / 100, 'fluent-cart:' . (int) $order->id);
        }
    }

    /* ---------- Reports ---------- */

    const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => 365];

    /** [from, to] local dates for a range key, and the same length just before it. */
    public static function dates($range)
    {
        $days = self::RANGES[$range] ?? 30;
        $to = wp_date('Y-m-d');
        $from = wp_date('Y-m-d', strtotime("-" . ($days - 1) . ' days'));
        return [$from, $to, wp_date('Y-m-d', strtotime("-" . (2 * $days - 1) . ' days')), wp_date('Y-m-d', strtotime("-$days days"))];
    }

    private static function totals($from, $to)
    {
        global $wpdb;
        $t = self::table();
        $w = $wpdb->prepare('day BETWEEN %s AND %s', $from, $to);
        $row = $wpdb->get_row("SELECT COUNT(*) views, COUNT(DISTINCT day, visitor) visitors FROM $t WHERE $w AND type = 'pageview'", ARRAY_A);
        $bounces = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT 1 FROM $t WHERE $w AND type = 'pageview' GROUP BY day, visitor HAVING COUNT(*) = 1) b");
        $conv = $wpdb->get_row("SELECT SUM(type = 'form') forms, SUM(type = 'order') orders, COALESCE(SUM(value), 0) revenue FROM $t WHERE $w AND type IN ('form', 'order')", ARRAY_A);
        $visitors = (int) $row['visitors'];
        return [
            'visitors'       => $visitors,
            'pageviews'      => (int) $row['views'],
            'pages_per_visit' => $visitors ? round($row['views'] / $visitors, 1) : 0,
            'bounce_rate'    => $visitors ? round(100 * $bounces / $visitors) : 0,
            'forms'          => (int) $conv['forms'],
            'orders'         => (int) $conv['orders'],
            'revenue'        => round((float) $conv['revenue'], 2),
        ];
    }

    /** Everything the dashboard and the analytics_report tool show, for one range. */
    public static function report($range = '30d', $limit = 10)
    {
        global $wpdb;
        [$from, $to, $prev_from, $prev_to] = self::dates($range);
        $t = self::table();
        $w = $wpdb->prepare('day BETWEEN %s AND %s', $from, $to);
        $limit = max(1, min(100, (int) $limit));
        // Each visit's first page view: where they came in and from where.
        $entries = "SELECT e.* FROM $t e INNER JOIN (SELECT MIN(id) id FROM $t WHERE $w AND type = 'pageview' GROUP BY day, visitor) f ON f.id = e.id";
        $by = function ($column, $where = '') use ($wpdb, $entries, $limit) {
            return array_map(function ($r) {
                return ['name' => $r['name'], 'visitors' => (int) $r['visitors']];
            }, $wpdb->get_results("SELECT $column name, COUNT(*) visitors FROM ($entries) x " . ($where ? "WHERE $where " : '') . "GROUP BY name ORDER BY visitors DESC LIMIT $limit", ARRAY_A));
        };

        $daily = [];
        foreach ($wpdb->get_results("SELECT day, COUNT(*) views, COUNT(DISTINCT visitor) visitors FROM $t WHERE $w AND type = 'pageview' GROUP BY day", ARRAY_A) as $r) {
            $daily[$r['day']] = ['visitors' => (int) $r['visitors'], 'pageviews' => (int) $r['views']];
        }
        $series = [];
        // Noon UTC stays on the same local date in every time zone.
        for ($d = strtotime("$from 12:00 UTC"); wp_date('Y-m-d', $d, new DateTimeZone('UTC')) <= $to; $d += DAY_IN_SECONDS) {
            $key = wp_date('Y-m-d', $d, new DateTimeZone('UTC'));
            $series[] = ['day' => $key] + ($daily[$key] ?? ['visitors' => 0, 'pageviews' => 0]);
        }

        $pages = array_map(function ($r) {
            return ['name' => $r['path'], 'pageviews' => (int) $r['views'], 'visitors' => (int) $r['visitors']];
        }, $wpdb->get_results("SELECT path, COUNT(*) views, COUNT(DISTINCT day, visitor) visitors FROM $t WHERE $w AND type = 'pageview' AND label <> '404' GROUP BY path ORDER BY views DESC LIMIT $limit", ARRAY_A));
        $not_found = array_map(function ($r) {
            return ['name' => $r['path'], 'pageviews' => (int) $r['views']];
        }, $wpdb->get_results("SELECT path, COUNT(*) views FROM $t WHERE $w AND type = 'pageview' AND label = '404' GROUP BY path ORDER BY views DESC LIMIT $limit", ARRAY_A));
        $campaigns = array_map(function ($r) {
            return ['name' => $r['campaign'], 'source' => $r['source'], 'medium' => $r['medium'], 'visitors' => (int) $r['visitors']];
        }, $wpdb->get_results("SELECT campaign, source, medium, COUNT(*) visitors FROM ($entries) x WHERE campaign <> '' GROUP BY campaign, source, medium ORDER BY visitors DESC LIMIT $limit", ARRAY_A));
        // Conversions, credited to the source of the visit they happened in (same visitor, same day).
        $conversions = array_map(function ($r) {
            return ['type' => $r['type'], 'name' => $r['path'], 'count' => (int) $r['n'], 'value' => round((float) $r['value'], 2)];
        }, $wpdb->get_results("SELECT type, path, COUNT(*) n, SUM(value) value FROM $t WHERE $w AND type IN ('form', 'order') GROUP BY type, path ORDER BY n DESC LIMIT $limit", ARRAY_A));
        $conversion_sources = array_map(function ($r) {
            return ['name' => $r['src'], 'conversions' => (int) $r['n'], 'value' => round((float) $r['value'], 2)];
        }, $wpdb->get_results("SELECT COALESCE(x.source, '') src, COUNT(*) n, SUM(c.value) value FROM $t c LEFT JOIN ($entries) x ON x.day = c.day AND x.visitor = c.visitor WHERE c." . $w . " AND c.type IN ('form', 'order') GROUP BY src ORDER BY n DESC LIMIT $limit", ARRAY_A));

        return [
            'range'              => $range,
            'from'               => $from,
            'to'                 => $to,
            'totals'             => self::totals($from, $to),
            'previous'           => self::totals($prev_from, $prev_to),
            'series'             => $series,
            'pages'              => $pages,
            'entry_pages'        => $by('path'),
            'sources'            => $by('source'),
            'referrers'          => $by('referrer', "referrer <> ''"),
            'campaigns'          => $campaigns,
            'devices'            => $by('device', "device <> ''"),
            'browsers'           => $by('browser'),
            'operating_systems'  => $by('os'),
            'time_zones'         => $by('tz', "tz <> ''"),
            'conversions'        => $conversions,
            'conversion_sources' => $conversion_sources,
            'not_found'          => $not_found,
        ];
    }

    /* ---------- Dashboard ---------- */

    const CAP = 'edit_others_posts';

    public static function menu()
    {
        $hook = add_menu_page('Analytics', 'Analytics', self::CAP, 'site-mcp-analytics', [__CLASS__, 'render'], 'dashicons-chart-area', 3);
        add_action("admin_print_styles-$hook", function () {
            $dir = dirname(SITE_MCP_FILE) . '/assets/';
            wp_enqueue_style('site-mcp-analytics', plugins_url('assets/analytics.css', SITE_MCP_FILE), [], filemtime($dir . 'analytics.css'));
            wp_enqueue_script('site-mcp-analytics', plugins_url('assets/analytics.js', SITE_MCP_FILE), [], filemtime($dir . 'analytics.js'), true);
        });
    }

    public static function dashboard_widget()
    {
        if (current_user_can(self::CAP)) {
            wp_add_dashboard_widget('site_mcp_analytics', 'Visitors', [__CLASS__, 'widget']);
        }
    }

    public static function widget()
    {
        $r = self::report('7d', 5);
        $change = self::change($r['totals']['visitors'], $r['previous']['visitors']);
        printf('<p style="font-size:14px;margin:0 0 8px"><strong style="font-size:28px;line-height:1.2;display:block">%s</strong>visitors in the last 7 days%s</p>', esc_html(number_format_i18n($r['totals']['visitors'])), $change === null ? '' : esc_html(sprintf(', %s%d%% on the week before', $change >= 0 ? '+' : '', $change)));
        if ($r['pages']) {
            echo '<p style="margin:12px 0 4px;color:#646970">Top pages</p><ol style="margin:0 0 0 18px">';
            foreach (array_slice($r['pages'], 0, 5) as $p) {
                printf('<li>%s <span style="color:#646970">%s</span></li>', esc_html($p['name']), esc_html(number_format_i18n($p['pageviews'])));
            }
            echo '</ol>';
        }
        printf('<p style="margin:12px 0 0"><a href="%s">All analytics →</a></p>', esc_url(admin_url('admin.php?page=site-mcp-analytics')));
    }

    /** Percentage change, or null when there is nothing to compare with. */
    private static function change($now, $before)
    {
        return $before > 0 ? (int) round(100 * ($now - $before) / $before) : null;
    }

    private static function list_card($title, array $rows, $metric = 'visitors', $empty = 'Nothing yet.', $note = '')
    {
        $max = $rows ? max(1, max(array_column($rows, $metric))) : 1;
        echo '<section class="smcpa-card"><h2>' . esc_html($title) . '</h2>';
        if (!$rows) {
            echo '<p class="smcpa-empty">' . esc_html($empty) . '</p>';
        } else {
            echo '<ol class="smcpa-list">';
            foreach ($rows as $r) {
                $name = $r['name'] === '' ? 'Direct or unknown' : $r['name'];
                printf(
                    '<li><span class="smcpa-bar" style="width:%1$.1f%%"></span><span class="smcpa-name" title="%2$s">%2$s</span><span class="smcpa-num">%3$s</span></li>',
                    100 * $r[$metric] / $max,
                    esc_attr($name),
                    esc_html(number_format_i18n($r[$metric]))
                );
            }
            echo '</ol>';
        }
        if ($note) {
            echo '<p class="smcpa-note">' . esc_html($note) . '</p>';
        }
        echo '</section>';
    }

    public static function render()
    {
        if (!current_user_can(self::CAP)) {
            wp_die('Sorry, you are not allowed to see this page.');
        }
        $range = isset($_GET['range'], self::RANGES[$_GET['range']]) ? sanitize_key($_GET['range']) : '30d';
        $r = self::report($range);
        $t = $r['totals'];
        $p = $r['previous'];
        $tiles = [
            ['Visitors', number_format_i18n($t['visitors']), self::change($t['visitors'], $p['visitors']), true],
            ['Page views', number_format_i18n($t['pageviews']), self::change($t['pageviews'], $p['pageviews']), true],
            ['Pages per visit', number_format_i18n($t['pages_per_visit'], 1), null, true],
            ['Bounce rate', $t['bounce_rate'] . '%', null, false],
            ['Form submissions', number_format_i18n($t['forms']), self::change($t['forms'], $p['forms']), true],
        ];
        if ($t['orders'] || $p['orders']) {
            $tiles[] = ['Orders', number_format_i18n($t['orders']), self::change($t['orders'], $p['orders']), true];
            $tiles[] = ['Order value', number_format_i18n($t['revenue'], 2), self::change($t['revenue'], $p['revenue']), true];
        }
        $labels = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months'];
        ?>
        <div class="wrap smcpa">
            <div class="smcpa-head">
                <h1>Analytics</h1>
                <nav class="smcpa-ranges" aria-label="Date range">
                    <?php foreach ($labels as $key => $label) : ?>
                        <a href="<?php echo esc_url(add_query_arg('range', $key)); ?>" <?php echo $key === $range ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <hr class="wp-header-end">
            <?php if (!self::enabled()) : ?>
                <div class="notice notice-warning inline"><p>Analytics is turned off on Settings → Site MCP, so nothing new is being counted.</p></div>
            <?php endif; ?>

            <section class="smcpa-tiles">
                <?php foreach ($tiles as [$label, $value, $change, $up_is_good]) : ?>
                    <div class="smcpa-tile">
                        <span class="smcpa-tile__label"><?php echo esc_html($label); ?></span>
                        <strong class="smcpa-tile__value"><?php echo esc_html($value); ?></strong>
                        <?php if ($change !== null) : ?>
                            <span class="smcpa-tile__change <?php echo ($change >= 0) === $up_is_good ? 'is-good' : 'is-bad'; ?>"><?php echo esc_html(($change >= 0 ? '▲ +' : '▼ ') . $change . '%'); ?> <span>vs previous <?php echo esc_html(strtolower(str_replace('Last ', '', $labels[$range]))); ?></span></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="smcpa-card smcpa-chart-card">
                <div class="smcpa-chart-head">
                    <h2 id="smcpa-chart-title">Visitors per day</h2>
                    <div class="smcpa-switch" role="group" aria-label="Measure">
                        <button type="button" data-metric="visitors" aria-pressed="true">Visitors</button>
                        <button type="button" data-metric="pageviews" aria-pressed="false">Page views</button>
                    </div>
                </div>
                <div class="smcpa-chart" data-series="<?php echo esc_attr(wp_json_encode($r['series'])); ?>"></div>
                <details class="smcpa-table">
                    <summary>Show as a table</summary>
                    <table class="widefat striped">
                        <thead><tr><th>Day</th><th>Visitors</th><th>Page views</th></tr></thead>
                        <tbody>
                            <?php foreach (array_reverse($r['series']) as $d) : ?>
                                <tr><td><?php echo esc_html(wp_date(get_option('date_format'), strtotime($d['day'] . ' 12:00'))); ?></td><td><?php echo (int) $d['visitors']; ?></td><td><?php echo (int) $d['pageviews']; ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </section>

            <div class="smcpa-grid">
                <?php
                self::list_card('Top pages', $r['pages'], 'pageviews', 'No page views yet.');
                self::list_card('Sources', $r['sources'], 'visitors', 'No visits yet.', 'Where each visit came from: a campaign\'s utm_source, else the referring site.');
                self::list_card('Entry pages', $r['entry_pages']);
                self::list_card('Referring sites', $r['referrers'], 'visitors', 'No referrals yet.');
                self::list_card('Campaigns', array_map(function ($c) {
                    return ['name' => $c['name'] . ' · ' . trim($c['source'] . ' / ' . $c['medium'], ' /'), 'visitors' => $c['visitors']];
                }, $r['campaigns']), 'visitors', 'No campaign links visited. Add utm_source, utm_medium and utm_campaign to links in emails and ads to see them here.');
                self::list_card('Conversions', array_map(function ($c) {
                    return ['name' => $c['name'] . ($c['value'] > 0 ? ' · ' . number_format_i18n($c['value'], 2) : ''), 'count' => $c['count']];
                }, $r['conversions']), 'count', 'No form submissions or orders yet.');
                self::list_card('Conversions by source', $r['conversion_sources'], 'conversions', 'No conversions yet.', 'Credited to the source of the visit the form or order happened in.');
                self::list_card('Devices', $r['devices']);
                self::list_card('Browsers', $r['browsers']);
                self::list_card('Operating systems', $r['operating_systems']);
                self::list_card('Visitor time zones', $r['time_zones'], 'visitors', 'No visits yet.', 'Roughly where visitors are, from their device\'s time zone. No IP addresses are looked up or kept.');
                self::list_card('Pages not found', $r['not_found'], 'pageviews', 'No visits to missing pages.');
                ?>
            </div>
            <p class="smcpa-foot">Cookie-free: nothing is stored in visitors' browsers and no IP addresses are kept. Each visitor counts once a day. Signed-in staff and bots aren't counted. Data is kept for <?php echo (int) self::KEEP_MONTHS; ?> months.</p>
        </div>
        <?php
    }
}

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('analytics_report', [
        'group'       => 'site',
        'readonly'    => true,
        'cap'         => Site_MCP_Analytics::CAP,
        'description' => 'The site\'s visitor analytics (built in, cookie-free) for a date range: visitors, page views, pages per visit, bounce rate, form submissions, orders and order value, each with the previous period for comparison; visitors and page views per day; top pages, entry pages, sources (Google, Facebook, campaigns…), referring sites, utm campaigns, devices, browsers, operating systems, visitor time zones, conversions with the sources they came from, and pages not found. Visitors count once a day.',
        'properties'  => [
            'range' => ['type' => 'string', 'enum' => array_keys(Site_MCP_Analytics::RANGES), 'default' => '30d'],
            'limit' => ['type' => 'integer', 'description' => 'Rows per list. Default 10, at most 100.'],
        ],
        'run'         => function ($args) {
            $report = Site_MCP_Analytics::report($args['range'] ?? '30d', Site_MCP_Tools::int_arg($args, 'limit', 10, 1, 100));
            if (!Site_MCP_Analytics::enabled()) {
                $report['note'] = 'Analytics is turned off on Settings → Site MCP, so nothing new is being counted.';
            }
            return $report;
        },
    ]);
});
