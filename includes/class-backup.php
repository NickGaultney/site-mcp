<?php
defined('ABSPATH') || exit;

/**
 * Backups on the site's own server: the whole database, plus the themes,
 * plugins and must-use plugins folders. Uploads are left out (a theme switch
 * never touches them, and they are often gigabytes). Made automatically
 * before every theme switch, or on request. Kept outside the web root,
 * beside the version history; the newest few are kept.
 */
class Site_MCP_Backup
{
    const KEEP = 5;
    const ID = '/^\d{8}-\d{6}-[a-z0-9]{8}$/';

    /** The backups folder: SITE_MCP_BACKUP_DIR, else beside the web root, else a hidden folder in wp-content. */
    public static function dir()
    {
        if (defined('SITE_MCP_BACKUP_DIR')) {
            $dir = untrailingslashit(SITE_MCP_BACKUP_DIR);
        } else {
            $dir = dirname(untrailingslashit(ABSPATH)) . '/site-backups';
            if (!@is_dir($dir) && !@wp_is_writable(dirname($dir))) {
                $dir = WP_CONTENT_DIR . '/site-mcp-backups-' . substr(wp_hash('site-mcp-backups'), 0, 16);
            }
        }
        if (!is_dir($dir) && wp_mkdir_p($dir) && str_starts_with($dir, WP_CONTENT_DIR)) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    /** Makes a backup and returns its manifest. Throws if the database couldn't be saved. */
    public static function create($reason)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        Site_MCP_Health::$exempt = true; // Slow by design.
        $id = gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(8, false));
        $folder = self::dir() . '/' . $id;
        if (!wp_mkdir_p($folder)) {
            throw new Site_MCP_Error("Couldn't create a backup folder in " . self::dir() . '.');
        }
        $started = microtime(true);
        $notes = [];
        try {
            $tables = self::dump_database($folder . '/database.sql.gz');
        } catch (Throwable $e) {
            self::remove($folder);
            throw new Site_MCP_Error('The database backup failed: ' . $e->getMessage());
        }
        $files = self::archive_files($folder . '/files.tar.gz');
        if ($files !== true) {
            $notes[] = $files;
        }
        $user = wp_get_current_user();
        $manifest = [
            'id'             => $id,
            'created'        => time(),
            'created_ms'     => (int) (microtime(true) * 1000),
            'reason'         => (string) $reason,
            'by'             => $user->exists() ? $user->display_name : 'Site MCP',
            'site'           => home_url('/'),
            'wordpress'      => get_bloginfo('version'),
            'theme'          => get_stylesheet(),
            'active_plugins' => (array) get_option('active_plugins', []),
            'tables'         => $tables,
            'database_bytes' => filesize($folder . '/database.sql.gz'),
            'files_bytes'    => is_file($folder . '/files.tar.gz') ? filesize($folder . '/files.tar.gz') : 0,
            'seconds'        => round(microtime(true) - $started, 1),
            'notes'          => $notes,
        ];
        file_put_contents($folder . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents($folder . '/RESTORE.txt', self::restore_notes($manifest));
        self::prune();
        return $manifest;
    }

    /** Backups newest first, from their manifests. */
    public static function all()
    {
        $out = [];
        foreach (glob(self::dir() . '/*/manifest.json') ?: [] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            if (is_array($manifest) && preg_match(self::ID, $manifest['id'] ?? '')) {
                $out[] = $manifest;
            }
        }
        usort($out, function ($a, $b) {
            return ($b['created_ms'] ?? $b['created'] * 1000) <=> ($a['created_ms'] ?? $a['created'] * 1000);
        });
        return $out;
    }

    /** The path of one file of a backup, or null. */
    public static function file($id, $which)
    {
        $name = ['database' => 'database.sql.gz', 'files' => 'files.tar.gz'][$which] ?? null;
        if (!$name || !preg_match(self::ID, (string) $id)) {
            return null;
        }
        $path = self::dir() . "/$id/$name";
        return is_file($path) ? $path : null;
    }

    private static function prune()
    {
        foreach (array_slice(self::all(), self::KEEP) as $old) {
            self::remove(self::dir() . '/' . $old['id']);
        }
    }

    private static function remove($folder)
    {
        foreach (array_diff(@scandir($folder) ?: [], ['.', '..']) as $file) {
            @unlink("$folder/$file");
        }
        @rmdir($folder);
    }

    /**
     * Writes every table with this site's prefix as SQL (DROP, CREATE, then
     * INSERTs in batches), gzipped. Pure PHP, so it works on hosts without
     * mysqldump. Returns the number of tables.
     */
    private static function dump_database($file)
    {
        global $wpdb;
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            throw new Site_MCP_Error("can't write $file");
        }
        gzwrite($gz, "-- Site MCP backup of " . home_url('/') . ', ' . gmdate('Y-m-d H:i:s') . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->base_prefix) . '%'));
        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if (!$create) {
                throw new Site_MCP_Error("can't read the structure of $table");
            }
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n{$create[1]};\n");
            for ($offset = 0; ; $offset += 500) {
                $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT 500 OFFSET $offset", ARRAY_N);
                if ($wpdb->last_error) {
                    throw new Site_MCP_Error("can't read $table: {$wpdb->last_error}");
                }
                if (!$rows) {
                    break;
                }
                $values = [];
                foreach ($rows as $row) {
                    $values[] = '(' . implode(',', array_map(function ($v) use ($wpdb) {
                        return $v === null ? 'NULL' : "'" . $wpdb->remove_placeholder_escape($wpdb->_real_escape($v)) . "'";
                    }, $row)) . ')';
                }
                gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $values) . ";\n");
                if (count($rows) < 500) {
                    break;
                }
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        return count($tables);
    }

    /** Archives themes, plugins and mu-plugins with tar. Returns true, or a note saying why not. */
    private static function archive_files($file)
    {
        $dirs = array_values(array_filter(['themes', 'plugins', 'mu-plugins'], function ($dir) {
            return is_dir(WP_CONTENT_DIR . "/$dir");
        }));
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return 'Files were not backed up: this server does not let PHP run tar. The database is complete.';
        }
        $proc = @proc_open(array_merge(['tar', '-czf', $file, '-C', WP_CONTENT_DIR, '--exclude=*/node_modules', '--exclude=*/.git'], $dirs), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, WP_CONTENT_DIR, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($proc)) {
            return 'Files were not backed up: tar could not be started. The database is complete.';
        }
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // tar exits 1 when a file changed while it was read; the archive is still usable.
        if (proc_close($proc) > 1 || !is_file($file)) {
            @unlink($file);
            return 'Files were not backed up (tar: ' . trim(wp_html_excerpt($err, 200, '…')) . '). The database is complete.';
        }
        return true;
    }

    private static function restore_notes(array $m)
    {
        return "Site MCP backup {$m['id']} of {$m['site']}\nMade " . gmdate('Y-m-d H:i', $m['created']) . " UTC by {$m['by']}: {$m['reason']}\n\n"
            . "database.sql.gz  Every table with this site's prefix. It replaces those tables when imported.\n"
            . "files.tar.gz     wp-content/themes, plugins and mu-plugins. Uploads are not included.\n\n"
            . "To restore the database, from the WordPress folder:\n    gunzip < database.sql.gz | wp db import -\n"
            . "or import it in phpMyAdmin. Anything saved after the backup (orders, form entries, comments, posts) is lost, so restore only what you need.\n\n"
            . "To restore files, from wp-content:\n    tar -xzf files.tar.gz themes/<theme>     (one theme)\n    tar -xzf files.tar.gz                    (everything)\n";
    }
}
