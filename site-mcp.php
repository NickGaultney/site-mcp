<?php
/**
 * Plugin Name: Site MCP
 * Plugin URI: https://github.com/NickGaultney/site-mcp
 * Description: A remote MCP server that lets approved users connect their own Claude (Claude Code or a claude.ai connector) to edit this site's content, design and store, and build custom features. No shell or server access.
 * Version: 1.7.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Gauws
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/NickGaultney/site-mcp
 */

defined('ABSPATH') || exit;

define('SITE_MCP_VERSION', '1.7.0');
define('SITE_MCP_DIR', __DIR__ . '/includes/');
define('SITE_MCP_FILE', __FILE__);

require SITE_MCP_DIR . 'class-log.php';
require SITE_MCP_DIR . 'class-tools.php';
require SITE_MCP_DIR . 'class-code.php';
require SITE_MCP_DIR . 'class-backup.php';
require SITE_MCP_DIR . 'class-health.php';
require SITE_MCP_DIR . 'class-share.php';
require_once SITE_MCP_DIR . 'class-preview.php'; // The must-use preview loader may have loaded it already.
require SITE_MCP_DIR . 'class-server.php';
require SITE_MCP_DIR . 'class-oauth.php';
require SITE_MCP_DIR . 'class-admin.php';
foreach (glob(SITE_MCP_DIR . 'tools/*.php') as $file) {
    require $file;
}

register_activation_hook(__FILE__, ['Site_MCP_Log', 'install']);
register_deactivation_hook(__FILE__, ['Site_MCP_Health', 'deactivate']);

// Updates come from the GitHub repository's releases. Skipped when WordPress can't
// write to this folder (for example a root-owned install), since it couldn't apply them.
if (!defined('SITE_MCP_DISABLE_UPDATES') && wp_is_writable(__DIR__)) {
    require __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
    $site_mcp_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/NickGaultney/site-mcp/', __FILE__, 'site-mcp');
    $site_mcp_updates->getVcsApi()->enableReleaseAssets('/site-mcp\.zip($|[?&#])/i');
    if (defined('SITE_MCP_GITHUB_TOKEN')) {
        $site_mcp_updates->setAuthentication(SITE_MCP_GITHUB_TOKEN); // Only needed while the repository is private.
    }
    // New releases install themselves, so no one has to click Update on each site.
    add_filter('auto_update_plugin', function ($update, $item) {
        return ($item->plugin ?? '') === plugin_basename(SITE_MCP_FILE) ? true : $update;
    }, 10, 2);
}

add_action('plugins_loaded', function () {
    Site_MCP_Preview::boot();
    Site_MCP_Log::maybe_upgrade();
    Site_MCP_Code::maybe_upgrade();
    Site_MCP_Health::boot();
    Site_MCP_Server::boot();
    Site_MCP_OAuth::boot();
    Site_MCP_Admin::boot();
});
