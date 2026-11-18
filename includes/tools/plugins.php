<?php
defined('ABSPATH') || exit;

/**
 * Plugins: list, install from WordPress.org, turn on and off. Turning one on
 * or off is checked like a code change and undone if the site breaks.
 * Deleting plugins is left to wp-admin.
 */
add_action('site_mcp_register_tools', function () {
    $plugin = ['type' => 'string', 'description' => 'Folder name, e.g. "fluentform", or plugin file, e.g. "fluentform/fluentform.php".'];

    Site_MCP_Tools::add('plugins_list', [
        'group'       => 'plugins',
        'readonly'    => true,
        'cap'         => 'activate_plugins',
        'description' => 'List installed plugins with their folder name, version, whether each is on, and what it does.',
        'run'         => function () {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $out = [];
            foreach (get_plugins() as $file => $data) {
                $out[] = ['plugin' => dirname($file) === '.' ? basename($file, '.php') : dirname($file), 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active($file), 'description' => wp_html_excerpt(wp_strip_all_tags($data['Description']), 160, '…')];
            }
            $mu = array_keys(get_mu_plugins());
            return ['plugins' => $out] + ($mu ? ['must_use' => $mu] : []);
        },
    ]);

    Site_MCP_Tools::add('plugin_install', [
        'group'       => 'plugins',
        'cap'         => 'install_plugins',
        'description' => 'Install a plugin from the WordPress.org directory by its slug (the part after wordpress.org/plugins/). It is installed switched off; turn it on with plugin_activate.',
        'properties'  => ['slug' => ['type' => 'string', 'description' => 'e.g. "fluentform" or "litespeed-cache".']],
        'required'    => ['slug'],
        'run'         => function ($args) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $slug = sanitize_key($args['slug']);
            try {
                $file = Site_MCP_Switch::plugin_file($slug);
                return ['message' => "$slug is already installed.", 'file' => $file, 'active' => is_plugin_active($file)];
            } catch (Site_MCP_Error $e) {
                // Not installed yet.
            }
            if (get_filesystem_method() !== 'direct') {
                throw new Site_MCP_Error("WordPress can't write to the plugins folder directly on this server, so plugins have to be installed in wp-admin.");
            }
            $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
            if (is_wp_error($info)) {
                throw new Site_MCP_Error("No plugin called $slug on WordPress.org: " . $info->get_error_message());
            }
            $skin = new WP_Ajax_Upgrader_Skin();
            $result = (new Plugin_Upgrader($skin))->install($info->download_link);
            if (is_wp_error($result) || !$result) {
                $error = is_wp_error($result) ? $result : $skin->get_errors();
                throw new Site_MCP_Error("Couldn't install $slug: " . (is_wp_error($error) && $error->has_errors() ? $error->get_error_message() : 'unknown error'));
            }
            wp_clean_plugins_cache(false);
            return ['message' => "Installed {$info->name} {$info->version}. It's switched off; turn it on with plugin_activate.", 'file' => Site_MCP_Switch::plugin_file($slug)];
        },
    ]);

    Site_MCP_Tools::add('plugin_activate', [
        'group'       => 'plugins',
        'cap'         => 'activate_plugins',
        'description' => 'Turn a plugin on. The site is checked straight away, and the plugin is turned off again if a page breaks.',
        'properties'  => ['plugin' => $plugin],
        'required'    => ['plugin'],
        'run'         => function ($args) {
            $file = Site_MCP_Switch::plugin_file($args['plugin']);
            if (is_plugin_active($file)) {
                return ['message' => "$file is already on."];
            }
            [$checked, $warnings] = Site_MCP_Switch::guarded("Turning on $file", function () use ($file) {
                $result = activate_plugin($file);
                if (is_wp_error($result)) {
                    throw new Site_MCP_Error($result->get_error_message());
                }
            }, function () use ($file) {
                deactivate_plugins([$file]);
            });
            return ['message' => "$file is on; the site still loads.", 'checked' => $checked] + ($warnings ? ['warnings' => $warnings] : []);
        },
    ]);

    Site_MCP_Tools::add('plugin_deactivate', [
        'group'       => 'plugins',
        'destructive' => true,
        'cap'         => 'activate_plugins',
        'description' => 'Turn a plugin off (it stays installed). The site is checked straight away, and the plugin is turned back on if a page breaks. To switch away from a page builder together with its theme, use theme_activate instead.',
        'properties'  => ['plugin' => $plugin],
        'required'    => ['plugin'],
        'run'         => function ($args) {
            $file = Site_MCP_Switch::plugin_file($args['plugin']);
            if ($file === plugin_basename(SITE_MCP_FILE)) {
                throw new Site_MCP_Error("Site MCP can't turn itself off.");
            }
            if (!is_plugin_active($file)) {
                return ['message' => "$file is already off."];
            }
            [$checked, $warnings] = Site_MCP_Switch::guarded("Turning off $file", function () use ($file) {
                deactivate_plugins([$file]);
            }, function () use ($file) {
                activate_plugin($file);
            });
            return ['message' => "$file is off; the site still loads.", 'checked' => $checked] + ($warnings ? ['warnings' => $warnings] : []);
        },
    ]);
});
