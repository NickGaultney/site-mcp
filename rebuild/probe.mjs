#!/usr/bin/env node
// Runs a script in a page loaded the way capture.mjs loads it, at one or more widths, and prints what
// it returns. The scripts in probes/ answer the usual questions; extra arguments reach a script as ARGS.
//
// Usage:
//   node probe.mjs <siteUrl> [--preview <link|token>] [--widths 1440,1024,390] [--shot file.png] <live|preview> <path> <script.js> [args...]
//
//   node probe.mjs https://example.com live /services/ probes/dump.js '.elementor-element-3f2a9c1'
//   node probe.mjs https://example.com --widths 1440 live / probes/fonts.js
import fs from 'node:fs';
import { launch, openPage, pageUrl, parseArgs, previewToken, sitePath } from './harness.mjs';

const { opts, rest } = parseArgs(process.argv.slice(2), { preview: null, widths: '1440,1024,390', shot: null });
const [site, side, rawPath, script, ...scriptArgs] = rest;
if (!site || !['live', 'preview'].includes(side) || !rawPath || !script) {
  console.error('usage: node probe.mjs <siteUrl> [--preview link|token] [--widths 1440,1024,390] [--shot file.png] <live|preview> <path> <script.js> [args...]');
  process.exit(2);
}
const token = previewToken(opts.preview);
if (side === 'preview' && !token) {
  console.error('preview needs --preview <link or token> (from theme_preview_link).');
  process.exit(2);
}
const code = fs.readFileSync(script, 'utf8');
const browser = await launch();
for (const w of opts.widths.split(',').map(Number)) {
  const { page, context, status } = await openPage(browser, pageUrl(site, sitePath(rawPath), side, token), w);
  const result = await page.evaluate(`(async (ARGS) => {\n${code}\n})(${JSON.stringify(scriptArgs)})`);
  console.log(`## ${side} ${sitePath(rawPath)} @ ${w} (${status})\n` + (typeof result === 'string' ? result : JSON.stringify(result, null, 1)) + '\n');
  if (opts.shot) await page.screenshot({ path: opts.shot.replace(/(\.png)?$/, `-${w}.png`) });
  await context.close();
}
await browser.close();
