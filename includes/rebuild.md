Rebuild this WordPress site as a fast, hand-written classic PHP theme, replacing its page builder (usually Elementor) and its old theme. Visitors must keep seeing the current site, unchanged, until the owner approves the switch.

# Ground rules

- Build in a new theme (theme_create), never in the active one. Pass `theme` to every theme_* tool. Nothing reaches visitors until theme_activate.
- Never delete or rewrite builder data, posts, pages or plugins. Elementor's data stays intact so switching back always works. Deleting old plugins and the old theme is for the owner to do in wp-admin, weeks after the switch.
- No page builder and no block templates. Page layouts and their copy are PHP templates in the theme; the owner changes them by asking Claude. Posts stay as content and are shown with the_content().
- Keep every URL. Pages keep their slugs and are matched by templates (front-page.php, page-about.php, home.php for the posts page, single.php, archive.php, search.php, 404.php).
- Keep REBUILD.md in the new theme as the running record: inventory, decisions, open questions for the owner, progress by phase, and baseline numbers. Read it first when resuming. Save it with every meaningful step.
- Ask the owner before anything they would notice: the design direction, dropping a feature, and the switch itself.

# Screenshots and speed

Matching a design needs screenshots, and proving the speed gain needs Lighthouse. Site MCP can't take them; your side has to. In Claude Code with the Site MCP repository, the rebuild kit in `rebuild/` does both (see its README): `compare.mjs` shoots the live site and the preview side by side at desktop and phone widths, and `lighthouse.mjs` scores pages. If you have no way to see pages, say so to the user before starting: without screenshots, run this from Claude Code with the kit.

# 1. Inventory

- site_overview, plugins_list, themes_list, elementor_overview, posts_list for pages, and the menus and footer as rendered (render_page).
- For every page and theme-builder template (header, footer, single-post, archive, popup): what it contains, which widgets, which forms, which dynamic parts (latest posts, search, newsletter signup).
- Plugins: which exist only for the builder or old theme (Elementor, Elementor Pro, Ocean Extra and other add-ons) and go at the switch; which do real work and stay (forms, SEO, analytics, security, SMTP, caching). Forms built with the Elementor Pro form widget must be rebuilt in Fluent Forms.
- Post content: posts written in the block editor or classic editor need no conversion. List any posts built with Elementor; they need converting to plain content (post_update, one at a time, keeping their text and images) after the owner agrees.
- Write it all into REBUILD.md, with questions for the owner.

# 2. Baseline

- Screenshot every page and one of each kind of post page at desktop (1440) and phone (390) widths.
- Lighthouse mobile and desktop on the home page and one post. Record the scores, LCP, and the CSS and JS weight.
- Capture the brand: elementor_overview's kit (colours, fonts, sizes, buttons), and elementor_page_get with detail=full and css=true for the home page, header and footer.

# 3. Faithful copy

Build the new theme to look like the current site, first. It is a checkpoint, not the finished design.

- Right after theme_create, call theme_preview_link with without_plugins set to the builder and old-theme add-ons from the inventory. Previews and checks of the new theme then run without them, the way the site will run after the switch, so screenshots and speed scores are honest and nothing in the theme quietly depends on them.

- Design tokens (colours, fonts, sizes, spacing) at the top of assets/css/main.css, from the kit.
- header.php and footer.php replace the old theme's header and the builder's header and footer templates. Put the menu in inc/site.php as a plain list of links unless the site's menus need to stay editable.
- One template per page that the builder made, with its copy and images. Use wp_get_attachment_image() with attachment IDs, so images get sizes and srcset.
- Forms: do_shortcode('[fluentform id="N"]').
- Posts: single.php, home.php and archive.php. Style the core blocks the posts use (paragraphs, headings, images, galleries, quotes, embeds, buttons, columns).
- Compare screenshots page by page against the baseline and fix differences until they're close. Then share a theme_preview_link with the owner.

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
4. Check the live site: render_page on every page, screenshots, and Lighthouse. Compare with the baseline.
5. Tell the owner what changed and the speed before and after, and that theme_switch_back (or the button on Settings → Site MCP) reverses the switch exactly. Suggest they delete the old plugins and theme in wp-admin after two weeks without problems.

# 7. Hand-over

- Turn README.md in the theme into the guide for whoever changes the site next: which template shows which page, where the copy, menu, footer, tokens and forms live, and the conventions. Claude reads it before every theme change.
- Mark REBUILD.md done, with the final numbers.
- Suggest anything the owner should add to "Notes for Claude" on Settings → Site MCP.
