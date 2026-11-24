// The @font-face rules this page actually uses, ready to copy into the theme, and the weights that exist
// per family. Declare exactly these weights: a missing weight falls back (500 renders as 400 when only
// 400 and 700 load), and a range such as "500 700" makes the browser render 500 bold where live does not.
// Copy the files with theme_file_write source_url, and the italic files too: synthetic italics are
// wider and change line breaks.
const clean = (s) => String(s || '').replace(/["']/g, '').trim();
const weight = (w) => ({ normal: '400', bold: '700' })[clean(w)] || clean(w) || '400';
const key = (f, w, s, u) => [clean(f).toLowerCase(), weight(w), clean(s) || 'normal', clean(u || 'U+0-10FFFF').toUpperCase()].join('|');
const faces = [...document.fonts].filter((f) => f.status === 'loaded');
const loaded = new Set(faces.map((f) => key(f.family, f.weight, f.style, f.unicodeRange)));
const families = {};
faces.forEach((f) => (families[clean(f.family)] ||= new Set()).add(weight(f.weight) + (clean(f.style) === 'normal' ? '' : ' ' + clean(f.style))));
const rules = [], unreadable = [];
const walk = (list, href) => {
  for (const r of list) {
    if (r instanceof CSSFontFaceRule) {
      const st = r.style;
      const k = key(st.getPropertyValue('font-family'), st.getPropertyValue('font-weight'), st.getPropertyValue('font-style'), st.getPropertyValue('unicode-range'));
      if (!loaded.has(k)) continue;
      const src = st.getPropertyValue('src').replace(/url\(["']?([^"')]+)["']?\)/g, (_, u) => `url("${new URL(u, href || location.href).href}")`);
      const [, w, s] = k.split('|');
      rules.push(`@font-face { font-family: "${clean(st.getPropertyValue('font-family'))}"; font-weight: ${w}; font-style: ${s};${st.getPropertyValue('unicode-range') ? ' unicode-range: ' + st.getPropertyValue('unicode-range') + ';' : ''} src: ${src}; }`);
    } else if (r.styleSheet) {
      walk(r.styleSheet.cssRules, r.styleSheet.href || href);   // @import
    } else if (r.cssRules) {
      walk(r.cssRules, href);   // @media, @supports, @layer
    }
  }
};
for (const sheet of document.styleSheets) {
  try {
    walk(sheet.cssRules, sheet.href);
  } catch {
    unreadable.push(sheet.href);
  }
}
const out = ['Weights loaded, per family:', ...Object.entries(families).map(([f, w]) => `  ${f}: ${[...w].sort().join(', ')}`), '', ...rules];
if (unreadable.length) out.push('', 'Cross-origin stylesheets (open them and copy the @font-face rules for the weights above):', ...unreadable.map((h) => '  ' + h));
return out.join('\n');
