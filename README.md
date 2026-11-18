# Site MCP

A WordPress plugin that lets the people you choose connect their own Claude to a site. It works with the Claude app as a custom connector, and with Claude Code. Each person signs in with their own WordPress account over OAuth. Claude can then manage:

- content and media;
- templates and styles;
- Fluent Cart and Fluent Forms;
- theme code, with version history;
- custom features (post types, a CRM, automations), built in a separate Site Features plugin with the same version history;
- a full rebuild in a new theme (for example away from Elementor), previewed privately and switched over with one-click switch back. See [rebuild/](rebuild/) for the screenshot and Lighthouse kit used alongside it.

Every action runs under that person's WordPress permissions, and nobody gets server or shell access.

The WordPress-facing documentation is in [readme.txt](readme.txt).

## Installing on a site

1. Download `site-mcp.zip` from the [latest release](../../releases/latest).
2. Install it with Plugins → Add New → Upload Plugin, then activate it.
3. Open Settings → Site MCP, run the connection check, and give people access.

Installed sites check this repository's releases and update like any other plugin. While the repository is private, each site needs `define('SITE_MCP_GITHUB_TOKEN', '...');` in wp-config.php: a fine-grained token with read-only Contents access to this repository.

## Releasing an update

1. Bump the version in three places:
   - `Version:` in `site-mcp.php`;
   - `SITE_MCP_VERSION` in `site-mcp.php`;
   - `Stable tag:` in `readme.txt`.
2. Add a changelog entry to `readme.txt`.
3. Commit, then run `./release.sh`. It checks the three versions match and the PHP syntax, builds `site-mcp.zip`, tags the commit and publishes a GitHub release with the changelog entry as its notes.

Sites see the update within about 12 hours, or straight away with "Check for updates" on the Plugins page.

## Layout

- `site-mcp.php`: bootstrap and the update checker.
- `includes/`:
  - `class-server.php`: the MCP endpoint, at `/wp-json/site-mcp/v1/mcp`.
  - `class-oauth.php`: OAuth 2.1, covering discovery, dynamic client registration, PKCE and the consent screen.
  - `class-tools.php`: the tool registry.
  - `class-admin.php`: the settings page.
  - `class-preview.php`: private preview links for inactive themes.
  - `rebuild.md`: the rebuild playbook, served by the `rebuild_guide` tool and the `rebuild_site` prompt.
  - `class-code.php`: code folders with version history: the theme, and the Site Features plugin it creates.
  - `class-log.php`: the activity log.
  - `tools/*.php`: the tools, one file per group.
- `rebuild/`: the rebuild kit (not in the zip).
- `vendor/plugin-update-checker`: [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) v5.7, MIT licensed.
