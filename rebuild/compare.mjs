#!/usr/bin/env node
// Screenshots of the live site and a theme preview, side by side, at desktop and phone widths.
//
// Usage:
//   node compare.mjs <siteUrl> [--preview <preview link or token>] [--out dir] [--widths 1440,390] /path1 /path2 ...
//
//   Without --preview it only shoots the live site (a baseline).
//   With it, each page gets <name>-<width>-live.png, -preview.png and -compare.png (both, side by side).
//
// Env: CHROME_PATH (defaults to the newest Playwright Chromium, else an installed Google Chrome; see chrome.mjs).
import fs from 'node:fs';
import path from 'node:path';
import { chromePath } from './chrome.mjs';
import { chromium } from 'playwright-core';

const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i > -1 ? args.splice(i, 2)[1] : fallback;
};
const preview = opt('--preview', null);
const out = opt('--out', 'shots');
const widths = opt('--widths', '1440,390').split(',').map(Number);
const [site, ...paths] = args;
if (!site || !paths.length) {
  console.error('usage: node compare.mjs <siteUrl> [--preview link|token] [--out dir] [--widths 1440,390] /path ...');
  process.exit(2);
}
const base = site.replace(/\/$/, '');
const token = preview ? (preview.includes('site_mcp_preview=') ? new URL(preview).searchParams.get('site_mcp_preview') : preview) : null;


function url(p, withPreview) {
  const u = new URL(base + p);
  u.searchParams.set('nocache', Date.now());
  if (withPreview) {
    u.searchParams.set('site_mcp_preview', token);
    u.searchParams.set('site_mcp_clean', '1');
  }
  return u.href;
}

async function shoot(page, target, file) {
  const response = await page.goto(target, { waitUntil: 'networkidle', timeout: 60000 });
  // Scroll through so lazy images load, then back to the top.
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 400) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
    scrollTo(0, 0);
  });
  await page.waitForTimeout(600);
  await page.screenshot({ path: file, fullPage: true });
  return response?.status();
}

fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ executablePath: chromePath() });
for (const width of widths) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  for (const p of paths) {
    const name = (p.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home') + `-${width}`;
    const live = path.join(out, `${name}-live.png`);
    const status = await shoot(page, url(p, false), live);
    if (!token) {
      console.log(`${live} (${status})`);
      continue;
    }
    const prev = path.join(out, `${name}-preview.png`);
    const pstatus = await shoot(page, url(p, true), prev);
    // Both screenshots side by side, labelled, in one image.
    const img = (f) => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64');
    const sheet = await ctx.newPage();
    await sheet.setViewportSize({ width: width * 2 + 48, height: 900 });
    await sheet.setContent(`<body style="margin:0;display:flex;gap:16px;padding:16px;background:#ddd;font:600 16px system-ui;align-items:flex-start">
      <figure style="margin:0;width:${width}px"><figcaption style="padding:0 0 8px">Live (${status})</figcaption><img src="${img(live)}" style="width:${width}px;display:block"></figure>
      <figure style="margin:0;width:${width}px"><figcaption style="padding:0 0 8px">Preview (${pstatus})</figcaption><img src="${img(prev)}" style="width:${width}px;display:block"></figure></body>`);
    const compare = path.join(out, `${name}-compare.png`);
    await sheet.screenshot({ path: compare, fullPage: true });
    await sheet.close();
    console.log(`${compare} (live ${status}, preview ${pstatus})`);
  }
  await ctx.close();
}
await browser.close();
