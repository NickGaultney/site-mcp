# Standard guides

Proven ways to do common jobs on WordPress sites, collected from suggestions that sites sent with `suggest_standard` and adopted after review. Each Markdown file here is served by the `guide` tool, and the server instructions name the guides that fit a site, so its Claude reads them before working in that area.

A guide is `<topic>.md`, where the topic is a lowercase slug, usually the plugin's folder name:

```markdown
---
title: WooCommerce page styles
plugins: woocommerce
---

When to use this, then the steps and the code, written for Claude working on a
different site. Say what to adapt to that site (colours, fonts, spacing tokens).
```

`plugins` is optional: a comma-separated list of plugin folder names, and the guide fits a site when any of them is active. Without it, the guide fits every site.

Files a guide hands out (templates, stylesheets) go in a folder named after the topic, `<topic>/`, at the path they take in a theme. The `guide` tool lists them with the guide and serves each one with `file`. PHP files there are never loaded by Site MCP, but start each with `defined('ABSPATH') || exit;` since they ship inside the plugin.

This README is not a guide.
