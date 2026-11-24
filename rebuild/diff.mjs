#!/usr/bin/env node
// Compares two captures from capture.mjs element by element and cuts section-aligned side-by-side images.
//
// Usage:
//   node diff.mjs shots/r1                           live against preview, both in shots/r1
//   node diff.mjs shots/before --against shots/after live before against live after (after the switch)
// Options: --pages <slug prefix>  --no-images  --ignore <file of regexes, one per line>  --max-height 15
//
// Prints a page x width table of document height differences and every remaining issue, writes the
// full report to report.txt and the side-by-sides to sbs/ (old site left, new right, magenta gutter).
// Exits 1 until every page is clean: heights within --max-height px and no unexplained issue.
import fs from 'node:fs';
import path from 'node:path';
import { PNG } from 'pngjs';
import { parseArgs } from './harness.mjs';

const { opts, rest } = parseArgs(process.argv.slice(2), { against: null, pages: '', 'no-images': false, ignore: null, 'max-height': '15' });
const [dir] = rest;
if (!dir) {
  console.error('usage: node diff.mjs <captureDir> [--against <captureDir>] [--pages prefix] [--no-images] [--ignore file] [--max-height 15]');
  process.exit(2);
}
const outDir = opts.against || dir;
const [A, B] = opts.against ? ['before', 'after'] : ['live', 'preview'];
const fileB = (stem) => (opts.against ? path.join(opts.against, `${stem}__live`) : path.join(dir, `${stem}__preview`));
const maxHeight = Number(opts['max-height']);
// Site-specific findings to accept, such as changes the owner asked for. One regex per line, # for comments.
const ignore = opts.ignore ? fs.readFileSync(opts.ignore, 'utf8').split('\n').map((l) => l.trim()).filter((l) => l && !l.startsWith('#')).map((l) => new RegExp(l)) : [];

const rgb = (c) => (String(c).match(/[\d.]+/g) || []).map(Number);
const invisible = (c) => c === 'transparent' || /rgba\([^)]*,\s*0\)$/.test(c);
function colDist(a, b) {
  if (a === b || (invisible(a) && invisible(b))) return 0;
  const x = rgb(a), y = rgb(b);
  if (x.length < 3 || y.length < 3) return 999;
  return Math.abs(x[0] - y[0]) + Math.abs(x[1] - y[1]) + Math.abs(x[2] - y[2]) + Math.abs((x[3] ?? 1) - (y[3] ?? 1)) * 255;
}
const px = (v) => (v === 'normal' ? null : parseFloat(v));
// "1px solid rgb(...)": equal when both draw nothing, or same width and style and a close colour.
function borderEq(a, b) {
  const parse = (s) => { const [w, style, ...c] = String(s).split(' '); return { w: parseFloat(w) || 0, style, color: c.join(' ') }; };
  const x = parse(a), y = parse(b);
  const none = (q) => q.w === 0 || q.style === 'none' || q.style === 'hidden' || invisible(q.color);
  if (none(x) && none(y)) return true;
  return x.w === y.w && x.style === y.style && colDist(x.color, y.color) <= 20;
}
const radius = (r) => String(r).replace(/\b0%/g, '0px');
// Alias font family names that differ only in how they were declared, e.g. 'Brand Typeface' -> 'Brand'.
const family = (f) => f.replace(/\s+(typeface|webfont|web)$/i, '');

function matchBy(a, b, key) {
  const pool = new Map();
  b.forEach((m, i) => { const k = key(m); if (!pool.has(k)) pool.set(k, []); pool.get(k).push(i); });
  const pairs = [], missing = [], used = new Set();
  a.forEach((l) => { const list = pool.get(key(l)); if (list?.length) { const i = list.shift(); used.add(i); pairs.push([l, b[i]]); } else missing.push(l); });
  return { pairs, missing, extra: b.filter((_, i) => !used.has(i)) };
}

function report(L, M) {
  const issues = [], notes = [];
  if (L.status !== M.status) issues.push(`  [status] ${L.status}->${M.status}`);

  const text = matchBy(L.texts, M.texts, (t) => t.key);
  text.pairs.sort((a, b) => a[0].tbox.y - b[0].tbox.y || a[0].tbox.x - b[0].tbox.x);
  let prevDy = 0;
  for (const [l, m] of text.pairs) {
    const d = [];
    if (family(l.ff) !== family(m.ff)) d.push(`font ${l.ff}->${m.ff}`);
    if (Math.abs(l.fs - m.fs) > 0.6) d.push(`size ${l.fs}->${m.fs}`);
    if (l.fw !== m.fw) d.push(`weight ${l.fw}->${m.fw}`);
    const llh = px(l.lh), mlh = px(m.lh);
    if ((llh === null) !== (mlh === null) || (llh !== null && Math.abs(llh - mlh) > 1.5)) d.push(`lh ${l.lh}->${m.lh}`);
    if (Math.abs((px(l.ls) || 0) - (px(m.ls) || 0)) > 0.35) d.push(`ls ${l.ls}->${m.ls}`);
    if (colDist(l.color, m.color) > 20) d.push(`color ${l.color}->${m.color}`);
    if (l.tt !== m.tt) d.push(`tt ${l.tt}->${m.tt}`);
    if (l.fst !== m.fst) d.push(`style ${l.fst}->${m.fst}`);
    if (l.deco !== m.deco) d.push(`decoration ${l.deco}->${m.deco}`);
    if (l.bg !== m.bg && (l.bg === 'image' || m.bg === 'image' || colDist(l.bg, m.bg) > 20)) d.push(`bg ${l.bg}->${m.bg}`);
    if (l.lines !== m.lines) d.push(`lines ${l.lines}->${m.lines}`);
    if (!l.carousel && !m.carousel) {
      if (Math.abs(l.tbox.x - m.tbox.x) > 5) d.push(`textX ${l.tbox.x}->${m.tbox.x}`);
      if (Math.abs(l.tbox.w - m.tbox.w) > Math.max(6, l.tbox.w * 0.03)) d.push(`textW ${l.tbox.w}->${m.tbox.w}`);
    }
    // A raw y difference accumulates down the page; report only where the offset changes, which
    // points at the one gap, padding or margin that differs.
    const dy = m.tbox.y - l.tbox.y;
    if (Math.abs(dy - prevDy) > 8) d.push(`vshift ${dy - prevDy >= 0 ? '+' : ''}${dy - prevDy} (now ${dy})`);
    prevDy = dy;
    if (d.length) issues.push(`  [y${l.tbox.y}] "${l.key.slice(0, 42)}" (${l.tag}/${m.tag}): ${d.join('; ')}`);
  }
  const list = (items, f) => items.slice(0, 25).map(f).join(' | ') + (items.length > 25 ? ` | +${items.length - 25} more` : '');
  if (text.missing.length) issues.push(`  MISSING text (${A} only): ` + list(text.missing, (t) => `"${t.key.slice(0, 40)}"@${t.tbox.y}`));
  if (text.extra.length) issues.push(`  EXTRA text (${B} only): ` + list(text.extra, (t) => `"${t.key.slice(0, 40)}"@${t.tbox.y}`));
  notes.push(`text matched ${text.pairs.length}, missing ${text.missing.length}, extra ${text.extra.length}`);

  const im = matchBy(L.imgs, M.imgs, (i) => i.key);
  for (const [l, m] of im.pairs) {
    const d = [];
    if (!l.carousel && !m.carousel && Math.abs(l.box.x - m.box.x) > 5) d.push(`x ${l.box.x}->${m.box.x}`);
    if (Math.abs(l.box.w - m.box.w) > Math.max(5, l.box.w * 0.02)) d.push(`w ${l.box.w}->${m.box.w}`);
    if (Math.abs(l.box.h - m.box.h) > Math.max(5, l.box.h * 0.02)) d.push(`h ${l.box.h}->${m.box.h}`);
    if (radius(l.br) !== radius(m.br)) d.push(`radius ${l.br}->${m.br}`);
    // A gallery of <img> tags and one of background tiles look the same; compare what both have.
    if (l.kind === 'img' && m.kind === 'img') {
      if (l.bs !== m.bs) d.push(`shadow ${l.bs}->${m.bs}`);
      if (l.fit !== m.fit && !(l.fit === 'fill' && m.fit === 'cover')) d.push(`fit ${l.fit}->${m.fit}`);
      if (!borderEq(l.border, m.border)) d.push(`border ${l.border}->${m.border}`);
      if (l.op !== m.op) d.push(`opacity ${l.op}->${m.op}`);
      if (l.filter !== m.filter) d.push(`filter ${l.filter}->${m.filter}`);
    } else if (l.kind === 'bg' && m.kind === 'bg' && l.att !== m.att) d.push(`attach ${l.att}->${m.att}`);
    if (d.length) issues.push(`  [y${l.box.y}] ${l.kind} ${l.key.slice(0, 40)}: ${d.join('; ')}`);
  }
  if (im.missing.length) issues.push(`  MISSING images (${A} only): ` + list(im.missing, (i) => `${i.kind}:${i.key.slice(0, 30)}@${i.box.y}(${i.box.w}x${i.box.h})`));
  if (im.extra.length) issues.push(`  EXTRA images (${B} only): ` + list(im.extra, (i) => `${i.kind}:${i.key.slice(0, 30)}@${i.box.y}(${i.box.w}x${i.box.h})`));
  notes.push(`images matched ${im.pairs.length}, missing ${im.missing.length}, extra ${im.extra.length}`);

  const bm = matchBy(L.btns, M.btns, (b) => b.key);
  for (const [l, m] of bm.pairs) {
    const d = [];
    for (const k of ['bg', 'color']) if (colDist(l[k], m[k]) > 20) d.push(`${k} ${l[k]}->${m[k]}`);
    if (!borderEq(l.border, m.border)) d.push(`border ${l.border}->${m.border}`);
    if (radius(l.br) !== radius(m.br)) d.push(`radius ${l.br}->${m.br}`);
    if (l.pad !== m.pad) d.push(`pad ${l.pad}->${m.pad}`);
    if (family(l.ff) !== family(m.ff) || l.fs !== m.fs || l.fw !== m.fw) d.push(`font ${l.ff} ${l.fs} ${l.fw}->${m.ff} ${m.fs} ${m.fw}`);
    if (Math.abs(l.box.w - m.box.w) > 4 || Math.abs(l.box.h - m.box.h) > 4) d.push(`size ${l.box.w}x${l.box.h}->${m.box.w}x${m.box.h}`);
    if (!l.carousel && !m.carousel && Math.abs(l.box.x - m.box.x) > 5) d.push(`x ${l.box.x}->${m.box.x}`);
    if (d.length) issues.push(`  [y${l.box.y}] button "${l.key}": ${d.join('; ')}`);
  }
  if (bm.missing.length) issues.push(`  MISSING buttons (${A} only): ` + bm.missing.map((b) => `"${b.key}"@${b.box.y}`).join(' | '));
  if (bm.extra.length) issues.push(`  EXTRA buttons (${B} only): ` + bm.extra.map((b) => `"${b.key}"@${b.box.y}`).join(' | '));
  notes.push(`buttons matched ${bm.pairs.length}, missing ${bm.missing.length}, extra ${bm.extra.length}`);

  return { issues, notes, pairs: text.pairs };
}

function crop(png, y0, y1, scale) {   // rows y0..y1, box-downscaled by an integer factor
  y0 = Math.max(0, Math.min(png.height, Math.round(y0)));
  y1 = Math.max(y0 + 1, Math.min(png.height, Math.round(y1)));
  const W = Math.floor(png.width / scale), H = Math.floor((y1 - y0) / scale) || 1, o = new PNG({ width: W, height: H });
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    let r = 0, g = 0, b = 0, n = 0;
    for (let dy = 0; dy < scale; dy++) for (let dx = 0; dx < scale; dx++) {
      const sy = y0 + y * scale + dy, sx = x * scale + dx;
      if (sy >= png.height || sx >= png.width) continue;
      const i = (sy * png.width + sx) * 4; r += png.data[i]; g += png.data[i + 1]; b += png.data[i + 2]; n++;
    }
    const j = (y * W + x) * 4; o.data[j] = r / n; o.data[j + 1] = g / n; o.data[j + 2] = b / n; o.data[j + 3] = 255;
  }
  return o;
}
function sideBySide(a, b) {   // old left, new right, magenta gutter
  const W = a.width + b.width + 8, H = Math.max(a.height, b.height), o = new PNG({ width: W, height: H });
  for (let i = 0; i < o.data.length; i += 4) { o.data[i] = 255; o.data[i + 1] = 0; o.data[i + 2] = 200; o.data[i + 3] = 255; }
  const put = (p, ox) => { for (let y = 0; y < p.height; y++) p.data.copy(o.data, (y * W + ox) * 4, y * p.width * 4, (y + 1) * p.width * 4); };
  put(a, 0);
  put(b, a.width + 8);
  return o;
}

// Slices run from one heading matched on both sides to the next, cut separately on each side, so a
// slice shows the same section even when everything above it has shifted.
function slices(stem, L, pairs, lp, mp) {
  const LP = PNG.sync.read(fs.readFileSync(lp)), MP = PNG.sync.read(fs.readFileSync(mp));
  const scale = L.vw >= 1800 ? 3 : L.vw >= 700 ? 2 : 1;
  const anchors = pairs.filter(([l]) => l.fs >= 20 || /^H[1-3]$/.test(l.tag)).map(([l, m]) => [l.tbox.y, m.tbox.y]).sort((a, b) => a[0] - b[0]);
  const cuts = [[0, 0]];
  for (const [ly, my] of anchors) {
    const last = cuts[cuts.length - 1];
    if (ly - 60 - last[0] >= 700 && my - 60 > last[1]) cuts.push([ly - 60, my - 60]);
  }
  cuts.push([LP.height, MP.height]);
  const sbs = path.join(outDir, 'sbs');
  fs.mkdirSync(sbs, { recursive: true });
  for (let i = 0; i < cuts.length - 1; i++) {
    const [l0, m0] = cuts[i];
    const l1 = Math.min(cuts[i + 1][0], l0 + 2600), m1 = Math.min(cuts[i + 1][1], m0 + 2600);
    fs.writeFileSync(path.join(sbs, `${stem}__s${String(i).padStart(2, '0')}.png`), PNG.sync.write(sideBySide(crop(LP, l0, l1, scale), crop(MP, m0, m1, scale))));
  }
  return `  ${cuts.length - 1} side-by-sides in ${sbs} (${A} left, ${B} right, scale 1/${scale})`;
}

const stems = fs.readdirSync(dir).filter((f) => f.endsWith('__live.json') && f.startsWith(opts.pages)).map((f) => f.replace(/__live\.json$/, '')).sort();
const full = [], leftovers = [], rows = {}, widths = new Set();
let dirty = 0;
for (const stem of stems) {
  if (!fs.existsSync(fileB(stem) + '.json')) continue;
  const L = JSON.parse(fs.readFileSync(path.join(dir, `${stem}__live.json`)));
  const M = JSON.parse(fs.readFileSync(fileB(stem) + '.json'));
  const [, page, w] = stem.match(/^(.*)__(\d+)$/);
  const dh = M.docH - L.docH;
  widths.add(w);
  (rows[page] ||= {})[w] = (dh > 0 ? '+' : '') + dh;
  const { issues, notes, pairs } = report(L, M);
  const open = issues.filter((l) => !ignore.some((re) => re.test(l)));
  const block = [`\n===== ${page} @ ${w} =====`, `height ${A} ${L.docH} ${B} ${M.docH} (${(dh > 0 ? '+' : '') + dh})   body bg ${L.bodyBg} / ${M.bodyBg}   ${notes.join(', ')}`, ...issues];
  if (L.delayJs?.length) block.push(`  note: ${A} delays JS until interaction (${L.delayJs.join(', ')})`);
  if (!opts['no-images'] && fs.existsSync(path.join(dir, `${stem}__live.png`)) && fs.existsSync(fileB(stem) + '.png')) {
    block.push(slices(stem, L, pairs, path.join(dir, `${stem}__live.png`), fileB(stem) + '.png'));
  }
  full.push(...block);
  if (open.length || Math.abs(dh) > maxHeight) {
    dirty++;
    leftovers.push(`\n== ${page} @ ${w}: height ${(dh > 0 ? '+' : '') + dh}, ${open.length} issue(s)`, ...open.slice(0, 15).map((l) => l.slice(0, 220)), ...(open.length > 15 ? [`  ... ${open.length - 15} more in report.txt`] : []));
  }
}
if (!Object.keys(rows).length) {
  console.error(`No pairs of captures found in ${dir}${opts.against ? ' and ' + opts.against : ''}.`);
  process.exit(2);
}
fs.writeFileSync(path.join(outDir, 'report.txt'), full.join('\n') + '\n');

const cols = [...widths].sort((a, b) => b - a);
console.log(`Document height, ${B} minus ${A}, in px:\n`);
console.log('page'.padEnd(40) + cols.map((w) => w.padStart(7)).join(''));
for (const [p, r] of Object.entries(rows)) console.log(p.slice(0, 39).padEnd(40) + cols.map((w) => (r[w] ?? '-').padStart(7)).join(''));
console.log(leftovers.join('\n'));
console.log(`\nFull report: ${path.join(outDir, 'report.txt')}`);
if (dirty) {
  console.log(`Not clean: ${dirty} page width(s) with issues or a height difference over ${maxHeight}px.`);
  process.exit(1);
}
console.log('Clean at every width.');
