<?php
defined('ABSPATH') || exit;

/** Activity log of every change (and every failed call) made through the server. */
class Site_MCP_Log
{
    const DB_VERSION = '1';
    const KEEP = 5000;

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'site_mcp_log';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tool varchar(100) NOT NULL,
            status varchar(10) NOT NULL,
            args longtext NOT NULL,
            message text NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};");
        update_option('site_mcp_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade()
    {
        if (get_option('site_mcp_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function add($tool, array $args, $status, $message)
    {
        global $wpdb;
        // Long bodies are trimmed: the revision holds the full content.
        array_walk_recursive($args, function (&$value) {
            if (is_string($value) && strlen($value) > 400) {
                $value = substr($value, 0, 400) . '… [' . strlen($value) . ' chars]';
            }
        });
        $wpdb->insert(self::table(), [
            'created_at' => current_time('mysql', true),
            'user_id'    => get_current_user_id(),
            'tool'       => $tool,
            'status'     => $status,
            'args'       => wp_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'message'    => (string) $message,
        ]);
        if ($wpdb->insert_id % 100 === 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE id <= %d', $wpdb->insert_id - self::KEEP));
        }
    }

    public static function count_since($days)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'ok' AND created_at > %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }

    public static function recent($limit = 50, $offset = 0)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset), ARRAY_A);
        foreach ($rows as &$row) {
            $user = get_userdata($row['user_id']);
            $row['user'] = $user ? $user->display_name : '#' . $row['user_id'];
            $row['ts'] = strtotime($row['created_at'] . ' UTC');
            $row['created_at'] = get_date_from_gmt($row['created_at'], 'Y-m-d H:i:s');
        }
        return $rows;
    }
}
