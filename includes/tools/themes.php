<?php
defined('ABSPATH') || exit;

/**
 * Building a new theme beside the live one and switching to it: create,
 * preview links, switch (with plugins turned on and off in the same step)
 * and switch back. Every switch is checked, undone automatically if the site
 * breaks, and recorded so it can be reversed later from Claude or from
 * Settings → Site MCP.
 */
class Site_MCP_Switch
{
    const OPTION = 'site_mcp_switch';

    /** Resolves a plugin given by folder name ("elementor") or file ("elementor/elementor.php"). */
    public static function plugin_file($plugin)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin = trim((string) $plugin);
        $all = get_plugins();
        if (isset($all[$plugin])) {
            return $plugin;
        }
        foreach (array_keys($all) as $file) {
            if (dirname($file) === $plugin || $file === "$plugin.php") {
                return $file;
            }
        }
        throw new Site_MCP_Error("No installed plugin called $plugin. plugins_list shows them.");
    }

    /**
     * Does $do, then loads the site. If pages break that loaded before, $undo
     * puts things back and the error is thrown. Pages that fail either way
     * are reported as a warning. Returns [checked paths, warnings].
     */
    public static function guarded($what, callable $do, callable $undo)
    {
        $started = time();
        $do();
        self::purge_caches();
        $code = Site_MCP_Code::theme();
        $paths = $code->check_paths();
        $broken = $code->health_check($paths);
        if (!$broken) {
            return [$paths, []];
        }
        $errors = self::error_lines($started);
        $undo();
        self::purge_caches();
        $caused = array_diff_key($broken, $code->health_check(array_keys($broken)));
        if ($caused) {
            throw new Site_MCP_Error("$what broke " . implode(', ', array_map(function ($path, $status) {
                return "$path ($status)";
            }, array_keys($caused), $caused)) . ', so it was undone automatically.' . ($errors ? ' PHP errors: ' . implode(' | ', $errors) : ''));
        }
        $do();
        self::purge_caches();
        return [$paths, ['These pages fail with or without this change, so it was kept: ' . implode(', ', array_keys($broken)) . '.']];
    }

    private static function error_lines($since)
    {
        $log = ini_get('error_log');
        if (!$log || !is_readable($log)) {
            return [];
        }
        return array_values(array_filter(array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -20), function ($line) use ($since) {
            return preg_match('/^\[([^\]]+)\]/', $line, $m) && strtotime($m[1]) >= $since - 2 && stripos($line, 'fatal') !== false;
        }));
    }

    /**
     * Clears page caches so visitors see a switch straight away: the common
     * caching plugins, and LiteSpeed's server cache (used by Hostinger and
     * others), which obeys a purge header sent by the site itself.
     */
    public static function purge_caches()
    {
        do_action('litespeed_purge_all');
        foreach (['rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache'] as $fn) {
            if (function_exists($fn)) {
                $fn();
            }
        }
        if (!headers_sent() && stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'litespeed') !== false) {
            header('X-LiteSpeed-Purge: *');
        }
    }

    /** Turns plugins on and off; returns the ones that actually changed. */
    private static function plugins(array $activate, array $deactivate)
    {
        $active = (array) get_option('active_plugins', []);
        $on = array_values(array_diff($activate, $active));
        $off = array_values(array_intersect($deactivate, $active));
        foreach ($on as $file) {
            $result = activate_plugin($file);
            if (is_wp_error($result)) {
                throw new Site_MCP_Error("Couldn't turn on $file: " . $result->get_error_message());
            }
        }
        deactivate_plugins($off);
        return [$on, $off];
    }

    /** Switches theme and plugins together, checks the site, and records how to switch back. */
    public static function switch_to($stylesheet, array $activate, array $deactivate, $note = '', $backup = true)
    {
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists() || $theme->errors()) {
            throw new Site_MCP_Error("$stylesheet isn't a usable theme" . ($theme->errors() ? ': ' . $theme->errors()->get_error_message() : '.'));
        }
        $activate = array_map([__CLASS__, 'plugin_file'], $activate);
        $deactivate = array_map([__CLASS__, 'plugin_file'], $deactivate);
        if (in_array(plugin_basename(SITE_MCP_FILE), $deactivate, true)) {
            throw new Site_MCP_Error("Site MCP can't turn itself off.");
        }
        $from = get_stylesheet();
        if ($from === $stylesheet && !array_diff($activate, (array) get_option('active_plugins', [])) && !array_intersect($deactivate, (array) get_option('active_plugins', []))) {
            throw new Site_MCP_Error("$stylesheet is already the active theme.");
        }
        // A full backup first, so the site as it was can always be restored, whatever happens next.
        $saved = $backup ? Site_MCP_Backup::create("Before switching the theme from $from to $stylesheet") : null;
        $changed = [[], []];
        [$checked, $warnings] = self::guarded("Switching to $stylesheet", function () use ($stylesheet, $activate, $deactivate, &$changed) {
            $changed = self::plugins($activate, $deactivate);
            switch_theme($stylesheet);
        }, function () use ($from, &$changed) {
            switch_theme($from);
            self::plugins($changed[1], $changed[0]);
        });
        $record = ['time' => time(), 'user' => get_current_user_id(), 'from' => $from, 'to' => $stylesheet, 'activated' => $changed[0], 'deactivated' => $changed[1], 'note' => $note, 'backup' => $saved['id'] ?? null];
        update_option(self::OPTION, $record, false);
        Site_MCP_Preview::revoke($stylesheet);
        Site_MCP_Preview::set_plan($stylesheet, []);
        if ($saved && $saved['notes']) {
            $warnings = array_merge($warnings, $saved['notes']);
        }
        return ['record' => $record, 'checked' => $checked] + ($saved ? ['backup' => array_intersect_key($saved, array_flip(['id', 'database_bytes', 'files_bytes', 'seconds']))] : []) + ($warnings ? ['warnings' => $warnings] : []);
    }

    /** Reverses the last switch. The reversal is recorded too, so switching back twice switches forward again. */
    public static function switch_back()
    {
        $last = get_option(self::OPTION);
        if (!$last) {
            throw new Site_MCP_Error('There is no theme switch to undo.');
        }
        if (get_stylesheet() !== $last['to']) {
            throw new Site_MCP_Error("The active theme is no longer {$last['to']}, so the switch from {$last['from']} can't be undone automatically.");
        }
        // No backup: switching back changes no content, and a backup here could push the
        // one made before the original switch out of the few that are kept.
        return self::switch_to($last['from'], $last['deactivated'], $last['activated'], 'Switched back', false);
    }

    public static function last()
    {
        $last = get_option(self::OPTION);
        return is_array($last) ? $last : null;
    }
}

add_action('site_mcp_register_tools', function () {
    if (Site_MCP_Code::theme()->unavailable()) {
        return;
    }

    Site_MCP_Tools::add('themes_list', [
        'group'       => 'theme',
        'readonly'    => true,
        'cap'         => 'edit_themes',
        'description' => 'List installed themes: which is active, whether each is a block or classic theme, and whether it has version history. Also the last theme switch, which theme_switch_back would undo.',
        'run'         => function () {
            $out = [];
            foreach (wp_get_themes() as $slug => $theme) {
                $out[] = ['theme' => $slug, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'active' => $slug === get_stylesheet(), 'block_theme' => $theme->is_block_theme(), 'parent' => $theme->parent() ? $theme->get_template() : null, 'history' => is_dir(Site_MCP_Code::theme($slug)->git_dir())] + (isset(Site_MCP_Preview::plans()[$slug]) ? ['preview_without' => Site_MCP_Preview::plans()[$slug]] : []);
            }
            return ['themes' => $out, 'last_switch' => Site_MCP_Switch::last()];
        },
    ]);

    Site_MCP_Tools::add('rebuild_guide', [
        'group'       => 'theme',
        'readonly'    => true,
        'cap'         => 'install_themes',
        'description' => 'The step-by-step playbook for rebuilding this site as a fast hand-written theme (for example to replace Elementor). Call it first whenever the owner asks to rebuild or redesign the site, and follow it. It is the same as the rebuild_site prompt.',
        'run'         => function () {
            return ['guide' => Site_MCP_Server::rebuild_text()];
        },
    ]);

    Site_MCP_Tools::add('theme_create', [
        'group'       => 'theme',
        'cap'         => 'install_themes',
        'description' => 'Create a new classic PHP theme beside the live one, with version history from the start. Visitors keep seeing the active theme; build the new one with the theme_* tools (passing theme) and look at it with theme_preview_link. Starts with style.css, functions.php (theme supports, menus, one stylesheet, everything in inc/ loaded), header.php, footer.php, index.php, assets/css/main.css and README.md.',
        'properties'  => [
            'theme'       => ['type' => 'string', 'description' => 'Folder name, lowercase with hyphens, e.g. "acme-2026".'],
            'name'        => ['type' => 'string', 'description' => 'Theme name shown in WordPress, e.g. "Acme".'],
            'description' => ['type' => 'string'],
        ],
        'required'    => ['theme', 'name'],
        'run'         => function ($args) {
            $code = Site_MCP_Code::theme($args['theme']);
            if ($code->exists()) {
                throw new Site_MCP_Error("A theme named {$args['theme']} already exists.");
            }
            $name = sanitize_text_field($args['name']);
            $code->first_message = "Created the $name theme";
            foreach (site_mcp_starter_theme($code->stylesheet, $name, sanitize_text_field($args['description'] ?? '')) as $relative => $content) {
                $code->write($code->path($relative), $content);
            }
            wp_clean_themes_cache();
            $code->history(1); // Starts the history with the starter files.
            $broken = $code->health_check(['/']);
            return ['message' => "Created the $name theme ({$code->stylesheet}). Visitors still see " . wp_get_theme()->get('Name') . '.', 'theme' => $code->stylesheet, 'files' => array_keys(site_mcp_starter_theme('x', 'x', ''))] + ($broken ? ['warning' => 'The preview of the new theme does not load yet: ' . implode(', ', array_keys($broken))] : []);
        },
    ]);

    Site_MCP_Tools::add('theme_preview_link', [
        'group'       => 'theme',
        'cap'         => 'edit_themes',
        'description' => 'A private link that shows the real site with an inactive theme, for checking the new design or sharing it with the owner. Anyone with the link sees the preview (labelled, never cached, never indexed); everyone else sees the live site. Links last ' . Site_MCP_Preview::DAYS . ' days. Add &site_mcp_clean=1 to hide the label for screenshots. Pass revoke=true to turn off every link for the theme. Set without_plugins to the plugins the switch will turn off (such as Elementor and its add-ons): previews and version checks of this theme then run without them, so they show and measure the site as it will be.',
        'properties'  => [
            'theme'           => ['type' => 'string', 'description' => 'The inactive theme to preview.'],
            'without_plugins' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Plugins previews of this theme run without, by folder name. Replaces the earlier list; [] clears it. theme_activate turns these off by default.'],
            'path'   => ['type' => 'string', 'description' => 'Page to open, e.g. "/about/". Defaults to the home page.'],
            'new'    => ['type' => 'boolean', 'description' => 'Make a new link instead of reusing the current one.'],
            'revoke' => ['type' => 'boolean'],
        ],
        'required'    => ['theme'],
        'run'         => function ($args) {
            $code = Site_MCP_Code::theme($args['theme']);
            if (!empty($args['revoke'])) {
                return ['message' => 'Revoked ' . Site_MCP_Preview::revoke($code->stylesheet) . " preview link(s) for {$code->stylesheet}."];
            }
            if (!$code->exists() || $code->active()) {
                throw new Site_MCP_Error($code->active() ? "{$code->stylesheet} is already the live theme." : "There is no theme named {$code->stylesheet}.");
            }
            $warning = null;
            if (isset($args['without_plugins'])) {
                $warning = Site_MCP_Preview::set_plan($code->stylesheet, array_map([Site_MCP_Switch::class, 'plugin_file'], (array) $args['without_plugins']));
            }
            $url = Site_MCP_Preview::link($code->stylesheet, !empty($args['new']));
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
            return ['url' => Site_MCP_Preview::url($query[Site_MCP_Preview::PARAM], (string) ($args['path'] ?? '/')), 'expires_in_days' => Site_MCP_Preview::DAYS, 'runs_without' => Site_MCP_Preview::plans()[$code->stylesheet] ?? []] + ($warning ? ['warning' => $warning] : []);
        },
    ]);

    Site_MCP_Tools::add('theme_activate', [
        'group'       => 'theme',
        'destructive' => true,
        'cap'         => function () {
            return current_user_can('switch_themes') && current_user_can('activate_plugins');
        },
        'description' => 'Make a theme the live theme, turning plugins on or off in the same step (for example turning off Elementor when the new theme replaces it). The site is checked straight away and everything is switched back automatically if a page breaks. Page caches are cleared. First it makes a full backup on the server (database, themes and plugins; see backups_list), and stops if that fails. The switch is recorded: theme_switch_back, or the button on Settings → Site MCP, reverses it exactly. Nothing is deleted: the old theme and plugins stay installed. Only do this when the owner has asked for the switch.',
        'properties'  => [
            'theme'              => ['type' => 'string'],
            'deactivate_plugins' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Plugins to turn off, by folder name, e.g. ["elementor-pro", "elementor"]. Defaults to the ones its previews run without (theme_preview_link without_plugins).'],
            'activate_plugins'   => ['type' => 'array', 'items' => ['type' => 'string']],
            'note'               => ['type' => 'string', 'description' => 'Why, for the activity log.'],
            'skip_backup'        => ['type' => 'boolean', 'description' => 'Switch without the backup. Only if the backup fails and the owner says to go ahead.'],
        ],
        'required'    => ['theme'],
        'run'         => function ($args) {
            $theme = (string) $args['theme'];
            $deactivate = $args['deactivate_plugins'] ?? (Site_MCP_Preview::plans()[$theme] ?? []);
            $result = Site_MCP_Switch::switch_to($theme, (array) ($args['activate_plugins'] ?? []), (array) $deactivate, (string) ($args['note'] ?? ''), empty($args['skip_backup']));
            $r = $result['record'];
            return ['message' => "{$r['to']} is now the live theme (was {$r['from']}). The site loads. Switch back with theme_switch_back." . (stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'litespeed') === false ? ' If the host caches pages, purge its cache.' : '')] + $result;
        },
    ]);

    Site_MCP_Tools::add('theme_switch_back', [
        'group'       => 'theme',
        'destructive' => true,
        'cap'         => function () {
            return current_user_can('switch_themes') && current_user_can('activate_plugins');
        },
        'description' => 'Undo the last theme switch: the previous theme comes back and the plugins it turned on or off are put back. Checked like theme_activate. Calling it again switches forward again.',
        'run'         => function () {
            $result = Site_MCP_Switch::switch_back();
            return ['message' => "Switched back: {$result['record']['to']} is the live theme again. The site loads."] + $result;
        },
    ]);
});

/** The files a new classic theme starts with. */
function site_mcp_starter_theme($slug, $name, $description)
{
    $fn = str_replace('-', '_', $slug);
    return [
        'style.css'           => "/*\nTheme Name: $name\nDescription: " . ($description ?: "The $name site theme.") . "\nVersion: 1.0.0\nRequires at least: 6.9\nRequires PHP: 8.1\nText Domain: $slug\n*/\n\n/* Styles live in assets/css/main.css. */\n",
        'functions.php'       => <<<PHP
<?php
defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Main menu', 'footer' => 'Footer menu']);
});

// One stylesheet, cache-busted by its modified time. Enqueue scripts only on the pages that need them.
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('$slug', get_theme_file_uri('assets/css/main.css'), [], filemtime(get_theme_file_path('assets/css/main.css')));
});

// Everything else lives in inc/, one file per concern (site.php, blog.php, …).
foreach (glob(__DIR__ . '/inc/*.php') ?: [] as \${$fn}_file) {
    require \${$fn}_file;
}

PHP,
        'header.php'          => <<<'PHP'
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
    <div class="container site-header__inner">
        <a class="site-header__brand" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
        <?php wp_nav_menu(['theme_location' => 'primary', 'container' => 'nav', 'container_class' => 'site-nav', 'fallback_cb' => false]); ?>
    </div>
</header>
<main id="main" class="site-main">

PHP,
        'footer.php'          => <<<'PHP'
</main>
<footer class="site-footer">
    <div class="container">
        <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></p>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>

PHP,
        'index.php'           => <<<'PHP'
<?php get_header(); ?>
<div class="container">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article <?php post_class('entry'); ?>>
                <?php if (is_singular()) : ?>
                    <h1 class="entry__title"><?php the_title(); ?></h1>
                    <div class="entry__content"><?php the_content(); ?></div>
                <?php else : ?>
                    <h2 class="entry__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div class="entry__excerpt"><?php the_excerpt(); ?></div>
                <?php endif; ?>
            </article>
        <?php endwhile; ?>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>Nothing here yet.</p>
    <?php endif; ?>
</div>
<?php get_footer(); ?>

PHP,
        'assets/css/main.css' => <<<'CSS'
/* Design tokens: every colour, font and size the theme uses. */
:root {
    --color-text: #1a1a1a;
    --color-bg: #ffffff;
    --color-accent: #2b59c3;
    --font-body: system-ui, sans-serif;
    --font-heading: var(--font-body);
    --container: 1140px;
}

*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; color: var(--color-text); background: var(--color-bg); font: 1.0625rem/1.6 var(--font-body); }
img { max-width: 100%; height: auto; }
.container { width: min(100% - 32px, var(--container)); margin-inline: auto; }

CSS,
        'README.md'           => "# $name theme\n\nA classic PHP theme with no page builder. Page layouts and most page copy are PHP templates in this folder; posts are edited as content.\n\n## Layout\n\n- `functions.php`: theme supports, the stylesheet, and loads everything in `inc/`.\n- `inc/`: one file per concern.\n- `header.php`, `footer.php`, `index.php`: the shell and the fallback template.\n- `assets/css/main.css`: all styles; design tokens at the top.\n\n## Conventions\n\nKeep this file up to date: it is the first thing Claude reads before changing the theme.\n",
    ];
}
