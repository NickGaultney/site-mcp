# Rebuild kit

Measurement and speed tools for rebuilding a site with Site MCP's rebuild playbook (the `rebuild_guide` tool, also offered as the `rebuild_site` prompt). Site MCP builds the new theme on the client's server. These scripts run here, because a faithful copy needs a real browser measuring both sites, and proving the speed gain needs Lighthouse.

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

## Why measure instead of looking

On a pilot site, the first copy was built from screenshots and Elementor's settings at one width. It came out 3,600 px too long on the phone home page and visibly off in a dozen places. The copy that matched came from a loop of capture, diff, dump and fix, repeated until the diff was clean at five widths. It ended within 11 px of the live page height everywhere, with fonts, colours, line breaks, images and buttons matching. Screenshots alone miss differences of 10 to 40 px, a 1 px border, a font weight or a colour. Elementor's settings are layered (kit, widget, responsive overrides, custom CSS, the base theme's reset), so only the computed style in a browser is reliable.

## capture.mjs

```sh
node capture.mjs https://example.com --preview '<preview link or token>' --out shots/r1 / /about/ /contact/
node capture.mjs https://example.com --out shots/before / /about/      # live only
```

Loads each page on the live site and on the preview at 1920, 1440, 1024, 768 and 390 px (`--widths`), four at a time (`--parallel`). Before measuring, it gets each page into the state a visitor sees:

- **Simulates an interaction** (mouse move, wheel, key press). LiteSpeed, WP Rocket and Perfmatters can hold back all JavaScript until the visitor interacts. Until then, live carousels show one full-width slide, galleries are 0 px tall and counters read 0. Capture reports when the live site does this.
- **Turns animations off** and removes Elementor's entrance-animation hiding.
- **Scrolls the whole page** in steps so lazy images, galleries and sticky elements start, then loads remaining lazy images.

It then records every text element (box, line count, font, size, weight, line height, spacing, colour, transform, background), every image and background image (box, radius, shadow, fit, border) and every button. It also takes a full-page screenshot. Each side loads in a fresh browser context, so the preview cookie never leaks into the live side.

## diff.mjs

```sh
node diff.mjs shots/r1
node diff.mjs shots/before --against shots/after       # the site after the switch against before it
```

Pairs elements by content (text, image file name, button label), not by position, because the two pages share no markup. It prints a page × width table of page height differences, then every remaining issue. Fonts, colours, line counts, text position and width, image and button boxes and styles are compared within small tolerances. The full report goes to `report.txt`.

- **vshift** reports only where the vertical offset *changes* from the element before. One wrong padding shows as one line at the section that has it, not as every element below it.
- **MISSING / EXTRA** list content on one side only. That catches content the live site hides that leaked into the rebuild, and content that was left out.
- **sbs/** holds side-by-side images cut at the same heading on both sides: live left, preview right, magenta gutter. Each slice compares the same section even when everything above it has shifted. Look at them; they show what the numbers don't (an overlay, a crop, an icon).
- Slides inside carousels are not compared on x, because autoplay moves them.
- `--ignore file` takes regexes, one per line, for findings the owner chose, such as a deliberate change. `--max-height` sets the allowed height difference (default 15 px).

It exits 1 until every page is clean at every width, so it can be looped on.

## probe.mjs and probes/

```sh
node probe.mjs https://example.com live /services/ probes/dump.js '.elementor-element-3f2a9c1'
node probe.mjs https://example.com --preview '<token>' preview /services/ probes/dump.js '.services-hero'
node probe.mjs https://example.com --widths 1440 live / probes/fonts.js
```

Loads a page exactly as capture does, at 1440, 1024 and 390 px by default, and runs a script in it. Extra arguments reach the script as `ARGS`; `--shot file.png` also saves a screenshot per width.

- `probes/dump.js <selector>...`: the computed style of every visible element under a selector, one line each, with its box, its own text and every property that differs from the default (including `::before` and `::after` overlays). The diff says where something is off; the dump says what the value should be. Copy fix values from it, never estimate them.
- `probes/base.js`: Elementor's breakpoints, body text, paragraph margins, headings, links, bold text and boxed container widths. This is the base of the new stylesheet.
- `probes/fonts.js`: the font weights that actually load per family, and their `@font-face` rules with absolute file URLs.
- `probes/hidden.js`: elements Elementor hides per device, flagging those hidden on every device.

Write a one-off script for anything else: a carousel's settings (`el.dataset.settings`), a paragraph's `innerHTML` (for `&nbsp;`), the menu after clicking it open.

## lighthouse.mjs

```sh
node lighthouse.mjs https://example.com / /a-post/
node lighthouse.mjs https://example.com --preview '<token>' --out lh-preview.json / /a-post/
```

Mobile scores are the median of 3 runs (`--runs`). Targets: mobile 90 or above, LCP under 2.5 s, CSS and JS under 100 KB.

## Pitfalls

| Pitfall | What you see | Fix |
| --- | --- | --- |
| Delayed JavaScript (LiteSpeed and similar) | Live carousels as one big slide, galleries 0 px tall, counters at 0 | Use capture and probe, which simulate an interaction; don't trust a browser pane or a plain screenshot of the live site |
| The preview cookie | After opening a preview link, plain URLs keep showing the new theme | Add `?site_mcp_preview=off` to go back; the scripts use a fresh context per side |
| Measuring inside an iframe | Theme CSS such as `iframe { max-width: 100% }` turns a 1440 px measurement into a tablet one | Measure in a top-level headless page, which the scripts do |
| Git Bash on Windows | `/about/` arrives as `C:/Program Files/Git/about/` | The scripts undo this. For other tools, `export MSYS_NO_PATHCONV=1` |
| `@font-face` weight ranges | Declaring `font-weight: 500 700` renders 500 bold where live renders it regular | Declare exactly the weights `probes/fonts.js` lists |
| `display` overriding `hidden` | A closed lightbox stays as an invisible full-screen layer that eats clicks | `.x[hidden] { display: none !important }`, `pointer-events: none` when closed, `focus({ preventScroll: true })` |
| `flex: 1` with padding | Columns split 630/680 instead of 673/638, because padding is outside the flex ratio | Explicit widths: `calc((100% - gap) * ratio)` |
| Copying text instead of HTML | Line breaks differ by a word | Copy the paragraph's `innerHTML`; it keeps `&nbsp;` |
| Broken features on the live site | A live gallery renders 0 px tall | Ask the owner whether to copy the bug or fix it |
