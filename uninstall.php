<?php
/**
 * Removes Site MCP's tables, settings and access grants when the plugin is
 * deleted. The Site Features plugin and the version history of it and the
 * theme are kept: they belong to the site, and reinstalling Site MCP picks
 * the history up again.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
foreach (['site_mcp_log', 'site_mcp_oauth_clients', 'site_mcp_oauth_grants', 'site_mcp_oauth_tokens', 'site_mcp_heavy'] as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$table");
}
foreach (['site_mcp_db_version', 'site_mcp_oauth_db', 'site_mcp_groups', 'site_mcp_instructions', 'site_mcp_check', 'site_mcp_known_groups', 'site_mcp_previews', 'site_mcp_preview_plans', 'site_mcp_switch', 'site_mcp_health', 'site_mcp_health_db', 'site_mcp_alert_email'] as $option) {
    delete_option($option);
}
delete_transient('site_mcp_git_check');
wp_clear_scheduled_hook('site_mcp_health_check');
@unlink(WPMU_PLUGIN_DIR . '/site-mcp-preview.php');
delete_metadata('user', 0, 'site_mcp_access', '', true);
foreach (get_users(['fields' => 'ID']) as $user_id) {
    foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
        if ($item['name'] === 'Site MCP (Claude)') {
            WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
        }
    }
}
