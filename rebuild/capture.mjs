#!/usr/bin/env node
// Measures pages on the live site and on a theme preview: every text element, image and button with
// its box and computed style, plus a full-page screenshot. diff.mjs compares the two.
//
// Usage:
//   node capture.mjs <siteUrl> --preview <link|token> --out shots/r1 [--widths 1920,1440,1024,768,390] /path1 /path2 ...
//   node capture.mjs <siteUrl> --out shots/before /path1 ...        (live only: a baseline, or the site after the switch)
//
// Writes <page>__<width>__live.json/.png and __preview.json/.png into --out.
import fs from 'node:fs';
import path from 'node:path';
import { launch, openPage, pageUrl, parseArgs, previewToken, sitePath, slug } from './harness.mjs';

const { opts, rest } = parseArgs(process.argv.slice(2), { preview: null, out: 'shots', widths: '1920,1440,1024,768,390', parallel: '4' });
const [site, ...rawPaths] = rest;
if (!site || !rawPaths.length) {
  console.error('usage: node capture.mjs <siteUrl> [--preview link|token] [--out dir] [--widths 1920,1440,1024,768,390] /path ...');
  process.exit(2);
}
const token = previewToken(opts.preview);
const sides = token ? ['live', 'preview'] : ['live'];
const widths = opts.widths.split(',').map(Number);
const paths = rawPaths.map(sitePath);
fs.mkdirSync(opts.out, { recursive: true });

/** Runs in the page. Keys are content (text, image file, button label), because the two DOMs share nothing. */
function extract() {
  const norm = (s) => s.replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
  const sy = window.scrollY, sx = window.scrollX;
  const R = (r) => ({ x: Math.round(r.left + sx), y: Math.round(r.top + sy), w: Math.round(r.width), h: Math.round(r.height) });
  const hiddenUp = (el) => {
    for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
      const cs = getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0 || n.hidden) return true;
    }
    return false;
  };
  const effBg = (el) => {
    for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage.includes('url(')) return 'image';
      const c = cs.backgroundColor;
      if (c && c !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(c)) return c;
    }
    return getComputedStyle(document.body).backgroundColor;
  };
  const skip = (el) => el.closest('script,style,noscript,svg,select,option,template,iframe,#wpadminbar,.screen-reader-text,.elementor-screen-only,.sr-only,.skip-link,.elementor-location-popup,[data-elementor-type=popup],.mobile-menu,#mobile-menu,[role=dialog][aria-hidden=true],[data-measure-skip]');
  // Slides move with autoplay, so their x positions are not compared.
  const carousel = (el) => !!el.closest('.swiper,.swiper-container,.slick-slider,.owl-carousel,.flickity-enabled,.splide,.glide,[data-carousel]');
  const border = (cs) => `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`;
  const font = (cs) => cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();

  const seen = new Set();
  const texts = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
    if (!node.nodeValue.trim()) continue;
    const el = node.parentElement;
    if (!el || seen.has(el) || skip(el)) continue;
    seen.add(el);
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1 || hiddenUp(el)) continue;
    const t = norm(el.textContent);
    if (t.length < 2) continue;
    const range = document.createRange();
    range.selectNodeContents(el);
    const lines = new Set([...range.getClientRects()].filter((q) => q.width > 1).map((q) => Math.round(q.top))).size;
    const cs = getComputedStyle(el);
    texts.push({
      key: t.slice(0, 70), tag: el.tagName, box: R(r), tbox: R(range.getBoundingClientRect()), lines, carousel: carousel(el),
      ff: font(cs), fs: parseFloat(cs.fontSize), fw: cs.fontWeight, lh: cs.lineHeight, ls: cs.letterSpacing, color: cs.color,
      tt: cs.textTransform, ta: cs.textAlign, fst: cs.fontStyle, deco: cs.textDecorationLine, bg: effBg(el),
    });
  }

  // Images are keyed by file name without WordPress size suffixes, so -1024x683 and -scaled copies match.
  const base = (u) => {
    try {
      return new URL(u, location.href).pathname.split('/').pop().replace(/-(\d+x\d+|scaled)(?=\.)/g, '').replace(/\.(webp|jpe?g|png|avif|gif|svg)$/i, '').replace(/-(\d+x\d+|scaled)$/, '').toLowerCase();
    } catch {
      return u;
    }
  };
  const imgs = [];
  document.querySelectorAll('img').forEach((img) => {
    if (skip(img)) return;
    const r = img.getBoundingClientRect();
    if (r.width < 4 || r.height < 4 || hiddenUp(img)) return;
    const cs = getComputedStyle(img);
    imgs.push({ key: base(img.currentSrc || img.src), kind: 'img', box: R(r), carousel: carousel(img), br: cs.borderRadius, bs: cs.boxShadow, fit: cs.objectFit, op: cs.opacity, filter: cs.filter, border: border(cs) });
  });
  document.querySelectorAll('body *').forEach((el) => {
    if (skip(el)) return;
    const cs = getComputedStyle(el);
    const m = cs.backgroundImage.match(/url\(["']?([^"')]+)["']?\)/);
    if (!m || m[1].startsWith('data:')) return;
    const r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4 || hiddenUp(el)) return;
    imgs.push({ key: base(m[1]), kind: 'bg', box: R(r), carousel: carousel(el), br: cs.borderRadius, size: cs.backgroundSize, pos: cs.backgroundPosition, att: cs.backgroundAttachment });
  });

  const btns = [];
  document.querySelectorAll('a,button,input[type=submit]').forEach((a) => {
    if (skip(a)) return;
    const r = a.getBoundingClientRect();
    if (r.width < 20 || r.height < 20 || hiddenUp(a)) return;
    const cs = getComputedStyle(a);
    if (cs.backgroundColor === 'rgba(0, 0, 0, 0)' && !(parseFloat(cs.borderTopWidth) > 0)) return;
    const t = norm(a.value || a.textContent);
    if (!t) return;
    btns.push({ key: t.slice(0, 40), box: R(r), carousel: carousel(a), bg: cs.backgroundColor, color: cs.color, border: border(cs), br: cs.borderTopLeftRadius, pad: cs.padding, ff: font(cs), fs: cs.fontSize, fw: cs.fontWeight });
  });

  return { url: location.href, vw: window.innerWidth, docH: document.documentElement.scrollHeight, bodyBg: getComputedStyle(document.body).backgroundColor, texts, imgs, btns };
}

const browser = await launch();
const jobs = [];
for (const p of paths) for (const w of widths) for (const side of sides) jobs.push([side, p, w]);
const delayed = new Set();
let next = 0;
let failed = 0;

async function run([side, p, w]) {
  const { page, context, status, delayJs } = await openPage(browser, pageUrl(site, p, side, token), w);
  if (side === 'live') delayJs.forEach((d) => delayed.add(d));
  // Measure at the real viewport first: a full-page screenshot resizes it and would distort vh units.
  const data = { path: p, side, status, delayJs, ...(await page.evaluate(extract)) };
  const file = path.join(opts.out, `${slug(p)}__${w}__${side}`);
  fs.writeFileSync(file + '.json', JSON.stringify(data));
  await page.screenshot({ path: file + '.png', fullPage: true }).catch((e) => console.error(`screenshot ${file}: ${e.message}`));
  await context.close();
  console.log(`${file} (${status}, ${data.docH}px tall)`);
  if (status !== 200) failed++;
}

await Promise.all(Array.from({ length: Math.max(1, +opts.parallel) }, async () => {
  while (next < jobs.length) {
    const job = jobs[next++];
    try {
      await run(job);
    } catch (e) {
      failed++;
      console.error(`failed ${job.join(' ')}: ${e.message}`);
    }
  }
}));
await browser.close();
if (delayed.size) console.log(`\nThe live site delays JavaScript until a visitor interacts: ${[...delayed].join(', ')}. Captures simulate an interaction; a browser pane or a plain screenshot shows carousels, galleries and counters uninitialised.`);
if (failed) {
  console.error(`\n${failed} page(s) failed or did not return 200.`);
  process.exit(1);
}
