#!/usr/bin/env node
// Lighthouse performance scores for pages of a site, or of a theme preview.
//
// Usage:
//   node lighthouse.mjs <siteUrl> [--preview <preview link or token>] [--forms mobile,desktop] [--runs 3] [--out file.json] /path1 /path2 ...
//
//   Each page is run --runs times per form factor (default 3 on mobile, 1 on desktop) and the median kept.
//   Prints one line per result and, with --out, writes the details as JSON.
//
// Env: CHROME_PATH (defaults to the newest Playwright Chromium, else an installed Google Chrome; see chrome.mjs).
import fs from 'node:fs';
import path from 'node:path';
import { chromePath } from './chrome.mjs';
import lighthouse, { desktopConfig } from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';

const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i > -1 ? args.splice(i, 2)[1] : fallback;
};
const preview = opt('--preview', null);
const forms = opt('--forms', 'mobile,desktop').split(',');
const runs = Number(opt('--runs', 3));
const outFile = opt('--out', null);
const [site, ...paths] = args;
if (!site) {
  console.error('usage: node lighthouse.mjs <siteUrl> [--preview link|token] [--forms mobile,desktop] [--runs 3] [--out file.json] /path ...');
  process.exit(2);
}
const base = site.replace(/\/$/, '');
const token = preview ? (preview.includes('site_mcp_preview=') ? new URL(preview).searchParams.get('site_mcp_preview') : preview) : null;


function summarize(lhr) {
  const au = lhr.audits;
  const by = Object.fromEntries((au['resource-summary']?.details?.items ?? []).map((i) => [i.resourceType, i]));
  return {
    performance: lhr.categories.performance.score == null ? null : Math.round(lhr.categories.performance.score * 100),
    lcpMs: Math.round(au['largest-contentful-paint']?.numericValue ?? NaN),
    cls: Number((au['cumulative-layout-shift']?.numericValue ?? NaN).toFixed(3)),
    tbtMs: Math.round(au['total-blocking-time']?.numericValue ?? NaN),
    totalKB: Math.round((by.total?.transferSize ?? 0) / 1024),
    cssKB: Math.round((by.stylesheet?.transferSize ?? 0) / 1024),
    jsKB: Math.round((by.script?.transferSize ?? 0) / 1024),
    fontKB: Math.round((by.font?.transferSize ?? 0) / 1024),
    requests: by.total?.requestCount ?? null,
    error: lhr.runtimeError?.message ?? null,
  };
}

const chrome = await chromeLauncher.launch({ chromePath: chromePath(), chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu'] });
const results = { site: base, preview: Boolean(token), date: new Date().toISOString(), pages: {} };
try {
  for (const p of paths.length ? paths : ['/']) {
    results.pages[p] = {};
    for (const form of forms) {
      const u = new URL(base + p);
      if (token) {
        u.searchParams.set('site_mcp_preview', token);
        u.searchParams.set('site_mcp_clean', '1');
      }
      const n = form === 'mobile' ? runs : 1;
      const all = [];
      for (let r = 0; r < n; r++) {
        const rr = await lighthouse(u.href, { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: ['performance'] }, form === 'desktop' ? desktopConfig : undefined);
        all.push(summarize(rr.lhr));
      }
      const median = [...all].sort((a, b) => a.performance - b.performance)[Math.floor(all.length / 2)];
      results.pages[p][form] = median;
      const m = median;
      console.log(`${p} ${form}: performance ${m.performance}, LCP ${(m.lcpMs / 1000).toFixed(1)} s, CLS ${m.cls}, TBT ${m.tbtMs} ms, CSS ${m.cssKB} KB, JS ${m.jsKB} KB, total ${m.totalKB} KB, ${m.requests} requests${n > 1 ? ` (median of ${n})` : ''}${m.error ? ' ERROR ' + m.error : ''}`);
    }
  }
} finally {
  await chrome.kill();
}
if (outFile) {
  fs.mkdirSync(path.dirname(path.resolve(outFile)), { recursive: true });
  fs.writeFileSync(outFile, JSON.stringify(results, null, 2));
}
