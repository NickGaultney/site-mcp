Rebuild this WordPress site as a fast, hand-written classic PHP theme, replacing its page builder (usually Elementor) and its old theme. Visitors must keep seeing the current site, unchanged, until the owner approves the switch.

# Ground rules

- Build in a new theme (theme_create), never in the active one. Pass `theme` to every theme_* tool. Nothing reaches visitors until theme_activate.
- Never delete or rewrite builder data, posts, pages or plugins. Elementor's data stays intact so switching back always works. Deleting old plugins and the old theme is for the owner to do in wp-admin, weeks after the switch.
- No page builder and no block templates. Page layouts and their copy are PHP templates in the theme; the owner changes them by asking Claude. Posts stay as content and are shown with the_content().
- Keep every URL. Pages keep their slugs and are matched by templates (front-page.php, page-about.php, home.php for the posts page, single.php, archive.php, search.php, 404.php).
- Keep REBUILD.md in the new theme as the running record: inventory, decisions, open questions for the owner, progress by phase, and baseline numbers. Read it first when resuming. Save it with every meaningful step.
- Ask the owner before anything they would notice: the design direction, dropping a feature, and the switch itself.

# Measuring

A faithful copy is measured, not judged by eye. Screenshots miss differences of 10 to 40 px, a 1 px border, a font weight or a colour. Elementor's settings are layered (kit, widget, responsive overrides, custom CSS, the base theme's reset), so only the computed style in a real browser says what visitors see. Site MCP can't run a browser; your side has to. In Claude Code with the Site MCP repository, the rebuild kit in `rebuild/` does it (read its README first). If it isn't on this machine, clone https://github.com/NickGaultney/site-mcp and run `npm install` in `rebuild/`; don't write your own.

- `capture.mjs` measures every text element, image and button on the live site and the preview at 1920, 1440, 1024, 768 and 390 px, with full-page screenshots.
- `diff.mjs` compares them element by element and prints what differs, with side-by-side images cut at matching headings. It exits non-zero until every page is clean.
- `probe.mjs` with `probes/dump.js` prints the computed style of a live section, which is where fix values come from. `probes/base.js`, `probes/fonts.js` and `probes/hidden.js` give the base styles, the fonts that load and what is hidden per device.
- `lighthouse.mjs` scores speed.

A browser pane or screenshot tool is not a substitute. It times out at emulated widths and catches animations midway. On sites that delay JavaScript until a visitor interacts (LiteSpeed, WP Rocket, Perfmatters), it shows carousels, galleries and counters uninitialised. The kit simulates that interaction. If you can't run the kit, tell the user before starting that the copy will only be approximate, and suggest running the rebuild from Claude Code with the kit.

# 1. Inventory

- site_overview, plugins_list, themes_list, elementor_overview, posts_list for pages, and the menus and footer as rendered (render_page).
- For every page and theme-builder template (header, footer, single-post, archive, popup): what it contains, which widgets, which forms, which dynamic parts (latest posts, search, newsletter signup).
- What each page hides per device: elementor_page_get marks elements hidden on desktop, tablet or phone. Content hidden on every device is never seen, so don't rebuild it. Content hidden on some devices is hidden at the same breakpoints in the new theme.
- Plugins: which exist only for the builder or old theme (Elementor, Elementor Pro, Ocean Extra and other add-ons) and go at the switch; which do real work and stay (forms, SEO, analytics, security, SMTP, caching). Forms built with the Elementor Pro form widget must be rebuilt in Fluent Forms.
- Post content: posts written in the block editor or classic editor need no conversion. List any posts built with Elementor; they need converting to plain content (post_update, one at a time, keeping their text and images) after the owner agrees.
- Write it all into REBUILD.md, with questions for the owner.

# 2. Baseline

- Capture every page and one of each kind of post page, live only, at all five widths: `node capture.mjs <site> --out shots/baseline / /about/ ...`. Keep this folder: it is the reference for checking the site after the switch.
- Lighthouse mobile and desktop on the home page and one post. Record the scores, LCP, and the CSS and JS weight.
- Capture the brand from the rendered site: `probes/base.js` at 1440, 1024 and 390 (breakpoints, body text, paragraph margins, headings, links, bold, container widths) and `probes/fonts.js` (the exact font weights that load and their files).
- Read elementor_overview's kit and elementor_page_get with detail=full and css=true for the home page, header and footer. Read them for intent: what hides on which device, sticky elements, carousel and gallery settings, form widget styles, anything not shown until clicked. Take numbers from the rendered site, not from settings.

# 3. Faithful copy

Build the new theme to look exactly like the current site at every width, first. It is a checkpoint, not the finished design, so copy, don't improve. Add nothing the live site doesn't show: an extra contact section, a footer column or a tile row that live hides are all bugs. Leave nothing out. Where the live site is broken (a gallery that renders 0 px tall), ask the owner whether to copy it or fix it.

Right after theme_create, call theme_preview_link with without_plugins set to the builder and old-theme add-ons from the inventory. Previews and checks of the new theme then run without them, the way the site will run after the switch, so screenshots and speed scores are honest and nothing in the theme quietly depends on them.

Build in this order, because each step removes differences from every page after it:

1. **Base styles**, from `probes/base.js` and `probes/fonts.js`, at the top of assets/css/main.css:
    - Elementor's breakpoints as media queries, usually `max-width: 1024px` (tablet) and `max-width: 767px` (phone).
    - Body text at each breakpoint.
    - The base theme's resets. Hello Elementor gives every paragraph `margin: 0 0 .9rem`, the last one included.
    - Heading, link and bold styles as rendered. Bold text is often not recoloured.
    - `@font-face` for exactly the weights that load, with the files copied in (theme_file_write source_url), italics included. A weight that doesn't load renders as the nearest one that does (500 as 400). Copy that, and never declare a range such as `500 700`.
    - Colour and spacing tokens, and the container width, section padding, column padding and gaps at each breakpoint.
2. **Shared parts:** header.php and footer.php replace the old theme's header and the builder's header and footer templates. Copy the phone menu as it looks opened: click it open in a probe and dump it. Also the contact or newsletter sections that repeat, and form styles. Put the menu in inc/site.php as a plain list of links unless the site's menus need to stay editable.
3. **Pages:** one template per page that the builder made, with its copy and images.
    - Copy each paragraph's HTML, not its text, since `&nbsp;` changes line breaks.
    - Use wp_get_attachment_image() with attachment IDs, so images get sizes and srcset.
    - Forms: do_shortcode('[fluentform id="N"]'). Their styles often live in the Elementor widget (label colours, submit width), so dump the live form.
4. **Posts:** single.php, home.php and archive.php. Style the core blocks the posts use (paragraphs, headings, images, galleries, quotes, embeds, buttons, columns).

Elementor changes almost everything at its breakpoints, so copy each breakpoint, not just the desktop:

- Font sizes step down and paddings shrink.
- Columns reorder (column-reverse, order), and elements hide per device.
- Fixed backgrounds usually turn to scroll on tablet and phone.
- Sticky columns usually become static on phone.
- Overlays are `::before` pseudo-elements with an opacity.
- Carousels, galleries and counters take their per-breakpoint settings (slides per view, gap, row height, autoplay speed) from the element's `data-settings`.

**The loop.** Capture both sides at all five widths and run diff.mjs. Start with the largest page height differences. Look at the side-by-sides of what differs, then dump that live section at 1440, 1024 and 390 (768 too if the layout changes between tablet sizes). Copy its values into the theme and capture again.

- Fix values come from dumps, never from estimates or Elementor's settings.
- A fix is done when the diff stops reporting it at every width, not when a screenshot looks right.
- Record each round's height table in REBUILD.md.
- The copy is done when diff.mjs reports clean. Only findings the owner accepted may remain, listed in an `--ignore` file.

Then share a theme_preview_link with the owner.

**Working with subagents.** Give each the kit, not screenshots, and its own capture folder. Split by page group:

- Each subagent owns its templates and a partial stylesheet, merged into the one stylesheet before the speed step.
- Shared files (base styles, fonts, header, footer, shared sections) stay with you. Subagents report problems in them instead of editing them.
- When you change something global, tell every subagent to capture again.

# 4. Modern design

- Propose a direction to the owner, keeping the brand: the same colours, fonts, logo and voice, with a cleaner layout, better type and better phone layouts. Mock up the home page in the new theme as a preview first; build the rest after they choose.
- Accessibility: real headings in order, alt text, visible focus, 4.5:1 contrast, and tap targets of at least 44px.

# 5. Speed

Targets on the preview: Lighthouse mobile 90 or above, LCP under 2.5 s, under 100 KB of CSS and JS per page (forms and checkout excepted), and no builder or old-theme files loaded.

- Self-host fonts: woff2 only, Latin subset, font-display: swap, and preload the main one. Copy them in with theme_file_write source_url.
- One stylesheet. JavaScript only where a page needs it, deferred. No jQuery unless a plugin on that page needs it. Icons as inline SVG, not icon fonts.
- add_filter('should_load_separate_core_block_assets', '__return_true') so posts load only the block styles they use. Remove the emoji script, and dequeue global-styles and classic-theme-styles if nothing uses them.
- The largest image on each page (the LCP) gets fetchpriority="high" and no lazy loading; every other image is lazy loaded.
- Check the preview's HTML (render_page with format=html, or view source) for leftover elementor, oceanwp or other builder assets.

# 6. Switch

Only when the owner says so.

1. theme_activate makes a full backup on the server first (database, themes and plugins) and stops if it fails. Uploads are not in it; they are untouched by the switch.
2. Check the preview one last time: every page, a post, the blog, an archive, search, a 404, and each form. A form submitted on the preview is real: delete the test entry afterwards.
3. theme_activate with the new theme. It turns off the plugins the previews ran without (or pass deactivate_plugins). It checks the site, switches back automatically if anything breaks, and clears page caches.
4. Check the live site: render_page on every page, then capture it live only into a new folder and run `diff.mjs shots/baseline --against <that folder>`. It should be as clean as the preview was. Run Lighthouse and compare with the baseline.
5. Tell the owner what changed and the speed before and after, and that theme_switch_back (or the button on Settings → Site MCP) reverses the switch exactly. Suggest they delete the old plugins and theme in wp-admin after two weeks without problems.

# 7. Hand-over

- Turn README.md in the theme into the guide for whoever changes the site next: which template shows which page, where the copy, menu, footer, tokens and forms live, and the conventions. Claude reads it before every theme change.
- Mark REBUILD.md done, with the final numbers.
- Suggest anything the owner should add to "Notes for Claude" on Settings → Site MCP.
