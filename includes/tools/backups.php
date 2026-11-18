<?php
defined('ABSPATH') || exit;

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('site_backup', [
        'group'       => 'site',
        'cap'         => 'manage_options',
        'description' => 'Back up the site on its own server: the whole database, plus the themes, plugins and must-use plugins folders (not uploads). theme_activate does this automatically. Takes from seconds to a few minutes. The newest ' . Site_MCP_Backup::KEEP . ' backups are kept; administrators can download them from Settings → Site MCP.',
        'properties'  => ['note' => ['type' => 'string', 'description' => 'Why, e.g. "Before removing Elementor".']],
        'run'         => function ($args) {
            $m = Site_MCP_Backup::create(trim((string) ($args['note'] ?? '')) ?: 'Made on request');
            return ['message' => "Backed up as {$m['id']}: {$m['tables']} database tables (" . size_format($m['database_bytes']) . ')' . ($m['files_bytes'] ? ', themes and plugins (' . size_format($m['files_bytes']) . ')' : '') . " in {$m['seconds']} s."] + ($m['notes'] ? ['warnings' => $m['notes']] : []);
        },
    ]);

    Site_MCP_Tools::add('backups_list', [
        'group'       => 'site',
        'readonly'    => true,
        'cap'         => 'manage_options',
        'description' => 'List the backups kept on the server, newest first. Restoring one is done by a person (each backup folder has RESTORE.txt), because it replaces everything saved since.',
        'run'         => function () {
            return ['folder' => Site_MCP_Backup::dir(), 'backups' => array_map(function ($m) {
                return ['id' => $m['id'], 'when' => wp_date('Y-m-d H:i', $m['created']), 'by' => $m['by'], 'reason' => $m['reason'], 'theme' => $m['theme'], 'database' => size_format($m['database_bytes']), 'files' => $m['files_bytes'] ? size_format($m['files_bytes']) : 'not included'] + ($m['notes'] ? ['notes' => $m['notes']] : []);
            }, Site_MCP_Backup::all())];
        },
    ]);
});

add_action('site_mcp_register_tools', function () {
    Site_MCP_Tools::add('site_health', [
        'group'       => 'site',
        'readonly'    => true,
        'cap'         => 'manage_options',
        'description' => 'How hard the site is working its server: load, memory, disk, database size, scheduled events, and the heavy requests (over ' . Site_MCP_Health::SLOW_SECONDS . ' s, near the PHP memory limit, over ' . Site_MCP_Health::QUERIES . ' queries, or stopped by a limit) of the last day, grouped by URL. Check it after building or changing a feature, especially one with scheduled jobs, imports or emails, and fix anything it lists. Problems found by the hourly check are also emailed to whoever looks after the server.',
        'properties'  => ['hours' => ['type' => 'integer', 'description' => 'How far back to list heavy requests. Default 24, at most ' . Site_MCP_Health::KEEP_DAYS * 24 . '.']],
        'run'         => function ($args) {
            $hours = max(1, min(Site_MCP_Health::KEEP_DAYS * 24, (int) ($args['hours'] ?? 24)));
            $state = Site_MCP_Health::check();
            return [
                'problems'       => array_values($state['issues']),
                'server'         => $state['metrics'],
                'heavy_requests' => Site_MCP_Health::heavy(time() - $hours * HOUR_IN_SECONDS, 30),
            ];
        },
    ]);
});
