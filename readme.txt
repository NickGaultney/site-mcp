=== Site MCP ===
Contributors: gauws
Tags: mcp, claude, ai, oauth, block theme
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let the people you choose connect their own Claude to this site. Claude can manage content, design, the store and the theme, and build custom features, using each person's WordPress permissions and never shell access.

== Description ==

Site MCP turns WordPress into a remote MCP server. Anyone you give access to can add the site to the Claude app as a custom connector, or to Claude Code. They sign in with their own WordPress account (OAuth). There are no passwords to share and no server access to hand out.

Claude gets tools for:

* Posts, pages, any custom post type, taxonomies, comments and revisions
* The media library
* Block templates, template parts, patterns and global styles
* Fluent Cart products, prices, stock and variants, and Fluent Cart's own order, customer and coupon tools (when Fluent Cart is installed)
* Fluent Forms forms and entries (when Fluent Forms is installed)
* Abilities that other plugins register with the WordPress Abilities API
* Theme code (administrators only), with version history:
  * Every change is syntax-checked, then the site's key pages are loaded. A change that breaks a page is rolled back automatically.
  * Kept changes are saved as versions, with who made them and why.
  * Changes can be undone from Claude or from Settings → Site MCP.
* Rebuilding the site in a new theme (administrators only), for example to replace Elementor with a fast hand-written theme:
  * New themes are built beside the live one, with their own version history. Visitors see nothing until the switch.
  * Private preview links show the real site with the new theme, never cached or indexed, and can run without the plugins the switch will turn off, so they show and measure the site as it will be.
  * Elementor pages, templates and global styles can be read in detail, even after Elementor is switched off.
  * Switching theme turns plugins on and off in the same step, checks the site, undoes itself if a page breaks, and clears page caches. One click on Settings → Site MCP switches back.
  * A rebuild_site prompt walks Claude through the whole process.
* Plugins (administrators only): list, install from WordPress.org, and turn on and off, with the site checked after each change.
* Site Features (administrators only): custom functionality such as post types, a CRM, email automations or integrations. Site MCP creates a separate plugin, Site Features, the first time Claude builds one, so features keep working if the theme changes. Each feature is a folder in that plugin, with the same checks, version history and undo as the theme.

* Sharing improvements (off until the owner turns it on in Settings → Site MCP): when Claude builds something other sites could reuse, such as styles for a plugin's pages or a tuned caching setup, it sends it to Site MCP's maintainer for review. The site's name and domain are replaced and secret-looking settings removed before sending. Adopted improvements come back to every site as standard guides, which Claude reads when they fit the site's plugins.

Each tool checks the signed-in person's own capabilities, and every change is recorded in an activity log.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin, then activate it.
2. Go to Settings → Site MCP and click **Run check** under "How to connect". It confirms that the server lets Claude connect, and explains how to fix anything it doesn't.
3. Give people access under "People with access". Administrators can approve themselves when they first connect.
4. Each person adds the URL shown on that page as a custom connector in Claude (Settings → Connectors), or runs the Claude Code command shown there, then logs in to WordPress and approves.

= Server requirements =

* PHP 8.1 or later and WordPress 6.9 or later. Plugins' abilities need the Abilities API, which is part of WordPress from 6.9.
* Theme editing and Site Features need git installed on the server, PHP's proc_open enabled, and file editing allowed (no DISALLOW_FILE_EDIT). Without them those tools are switched off, and everything else still works.
* The site must be able to load its own pages (loopback requests), so code changes can be tested before they are kept. Each change loads the home page, recent content, the admin dashboard and the REST API.

= wp-config.php options =

* `SITE_MCP_HISTORY_DIR`: where to keep version history for the theme and Site Features. By default it goes beside the WordPress folder, and inside wp-content when that location isn't writable.
* `SITE_MCP_BACKUP_DIR`: where to keep backups. By default beside the WordPress folder, and inside wp-content (with an unguessable name) when that location isn't writable.
* `SITE_MCP_GITHUB_TOKEN`: a read-only GitHub token, needed for updates only while the plugin's repository is private.
* `SITE_MCP_DISABLE_UPDATES`: turns off update checks. Otherwise new releases install themselves.
* `SITE_MCP_SHARE`: `true` or `false` turns sharing improvements on or off and locks the setting on Settings → Site MCP.
* `SITE_MCP_SHARE_URL`: where shared improvements go, instead of https://standards.gauws.com.
* `SITE_MCP_ALERT_EMAIL`: who to email when the site strains its server, overriding the Alert email setting on Settings → Site MCP and locking it so it can't be changed from the dashboard. Several addresses can be separated by commas, and `''` turns the emails off; the checks still show on the settings page.

== Frequently Asked Questions ==

= Does anyone get shell or file access? =

No shell or server login. Claude works only through this plugin's tools, as the person who signed in. Code editing is limited to text files inside the active theme and the Site Features plugin, and only administrators can use it. Note that PHP code written there runs as the site's server user, so give each site its own server user.

= How do I disconnect someone? =

Click Revoke next to their name on Settings → Site MCP. Every app they connected stops working immediately.

= What happens to the data if I delete the plugin? =

Its tables, settings and access grants are removed. The Site Features plugin and its features keep running, backups stay in their folder, and the version history of the theme and Site Features is kept, so reinstalling Site MCP picks it up again.

== Changelog ==

= 1.7.0 =
* Share improvements: an opt-in setting on Settings → Site MCP. When it's on, Claude can send reusable work (styles for a plugin's pages, tuned plugin settings, fixes) to Site MCP's maintainer with the new suggest_standard tool. The site's name and domain are replaced and secret-looking settings removed before sending.
* guide: Site MCP's standard guides, adopted from shared improvements. Claude is told which guides fit the site's active plugins.
* elementor_page_get marks elements hidden on some devices, and those hidden on every device so a rebuild leaves them out. elementor_overview counts the hidden ones per page.
* The rebuild playbook builds the faithful copy around measuring: computed styles and an element-by-element diff of the live site and the preview at five widths, with the new capture, diff and probe scripts in the rebuild kit.
* theme_preview_link explains how to leave a preview (?site_mcp_preview=off).

= 1.6.2 =
* Alert email setting under Server health on Settings → Site MCP. Alerts go there, or to the site's admin email until it's set, instead of a fixed address. SITE_MCP_ALERT_EMAIL still overrides it.

= 1.6.1 =
* rebuild_guide: the site rebuild playbook as a tool, so Claude can fetch it when asked to rebuild a site. Before, it was only a prompt, which Claude can't open itself and some apps don't show.

= 1.6.0 =
* Server health: heavy requests (slow, near the memory limit, over 1,000 queries, or stopped by a limit) are recorded, and an hourly check adds server load, memory, disk, database growth and runaway scheduled events. Problems are emailed to the Alert email set on Settings → Site MCP (the site's admin email until it's set) at most once a day each, with the heaviest URLs and recent Site Features changes. See it under Server health on Settings → Site MCP, or with the site_health tool.
* Updates install automatically, so new releases reach every site without clicking Update.

= 1.5.1 =
* Backups: the database, themes and plugins, saved on the server automatically before every theme switch, or on request (site_backup, or Back up now on Settings → Site MCP). The newest five are kept and can be downloaded.

= 1.5.0 =
* Rebuild a site in a new theme: theme_create, theme tools that work on any installed theme, private preview links (optionally without the plugins the switch will turn off), theme_activate and theme_switch_back.
* Elementor tools: elementor_overview and elementor_page_get read pages, templates, global colours and fonts, and generated CSS.
* Plugin tools: list, install from WordPress.org, turn on and off with automatic undo.
* The rebuild_site prompt: the step-by-step rebuild playbook.
* Theme files can include fonts and images copied from a URL.
* Settings → Site MCP shows themes in progress, preview links and the last switch, with a Switch back button.
* On classic themes, the block template and global style tools are hidden, and Claude is told how the theme is organised.

= 1.4.1 =
* Fix: creating Site Features failed on sites where WordPress had already read the plugin list during the same request.

= 1.4.0 =
* Site Features: Claude builds custom functionality in its own plugin, separate from the theme, with version history, undo and automatic rollback.
* Code changes are now also checked against the admin dashboard and the REST API, signed in as the person making them, and any admin pages or routes Claude names.
* Settings → Site MCP lists the site's features and their history, and can turn Site Features back on.
* Theme editing and Site Features explain when file editing is turned off in wp-config.php.

= 1.3.0 =
* Packaged as a standalone plugin with automatic updates from GitHub releases.
* Connection check on the settings page.
* Fluent Cart's tools can be switched on from the settings page.
* Theme editing explains why it is unavailable on servers without git.
* The health check no longer rolls back a change when a page also fails without it.

= 1.2.0 =
* Theme editing with on-server version history: diff, undo and restore.

= 1.1.0 =
* OAuth sign-in for Claude connectors.
