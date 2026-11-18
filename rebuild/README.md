# Rebuild kit

Screenshots and speed scores for rebuilding a site with Site MCP's rebuild playbook (the `rebuild_guide` tool, also offered as the `rebuild_site` prompt). Site MCP builds the new theme on the client's server; these scripts run here, because comparing designs needs screenshots, and proving the speed gain needs Lighthouse.

Not part of the plugin zip.

## Setup

```sh
cd rebuild && npm install
```

Works on Windows, macOS and Linux with Node 18 or later. It uses the newest Playwright Chromium if there is one (`npx playwright install chromium`), else an installed Google Chrome, or whatever `CHROME_PATH` points to.

## Running a rebuild

1. Install Site MCP on the client's site, give yourself access, and connect Claude Code to it. The command is on Settings → Site MCP.
2. In Claude Code, started in this repository so the scripts are at hand, ask Claude to rebuild the site, with any notes from the owner. It fetches the playbook with `rebuild_guide`. Picking the `rebuild_site` prompt from the `/` menu does the same, where the app shows it.
3. Claude uses the scripts below as it goes.

## compare.mjs

```sh
node compare.mjs https://example.com --out shots/baseline / /about/ /blog/
node compare.mjs https://example.com --preview '<preview link or token>' --out shots/round-1 / /about/
```

Without `--preview`, it captures the live site only. With it, each page gets a `-compare.png` with live and preview side by side, at 1440 and 390 pixels wide (`--widths` to change).

## lighthouse.mjs

```sh
node lighthouse.mjs https://example.com / /a-post/
node lighthouse.mjs https://example.com --preview '<token>' --out lh-preview.json / /a-post/
```

Mobile scores are the median of 3 runs (`--runs`). Targets: mobile 90 or above, LCP under 2.5 s, CSS and JS under 100 KB.
