// The computed style of every visible element under each selector in ARGS, one line per element:
// tag and classes, box relative to the root, own text, and every property that differs from its default.
// Copy fix values from this, never estimate them. Run it at desktop, tablet and phone widths.
const props = ['display', 'position', 'padding', 'margin', 'border', 'borderRadius', 'boxShadow', 'backgroundColor', 'backgroundImage', 'backgroundSize', 'backgroundPosition', 'backgroundAttachment', 'color', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textTransform', 'textAlign', 'textDecorationLine', 'gap', 'flexDirection', 'flexWrap', 'flex', 'order', 'justifyContent', 'alignItems', 'gridTemplateColumns', 'opacity', 'objectFit', 'maxWidth', 'minHeight', 'zIndex', 'top', 'left', 'transform', 'filter', 'overflow'];
const defaults = { display: 'block', position: 'static', padding: '0px', margin: '0px', borderRadius: '0px', boxShadow: 'none', backgroundColor: 'rgba(0, 0, 0, 0)', backgroundImage: 'none', backgroundSize: 'auto', backgroundPosition: '0% 0%', backgroundAttachment: 'scroll', fontStyle: 'normal', textDecorationLine: 'none', gap: 'normal', flexDirection: 'row', flexWrap: 'nowrap', flex: '0 1 auto', order: '0', justifyContent: 'normal', alignItems: 'normal', gridTemplateColumns: 'none', opacity: '1', objectFit: 'fill', maxWidth: 'none', minHeight: '0px', zIndex: 'auto', top: 'auto', left: 'auto', transform: 'none', textTransform: 'none', letterSpacing: 'normal', filter: 'none', overflow: 'visible' };
// Inherited text properties are printed only where they change from the parent.
const inherited = new Set(['color', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textTransform', 'textAlign']);
const out = [];
if (!ARGS.length) return 'Pass one or more CSS selectors, e.g. .elementor-element-3f2a9c1';
for (const sel of ARGS) {
  const root = document.querySelector(sel);
  if (!root) { out.push(`!! nothing matches ${sel}`); continue; }
  const ry = root.getBoundingClientRect().top + scrollY;
  out.push(`# ${sel}`);
  const walk = (el, depth, parent) => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || (r.width === 0 && r.height === 0)) return;
    const own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.nodeValue.trim()).join(' ').slice(0, 40);
    const cls = (typeof el.className === 'string' ? el.className : '').split(' ').filter((c) => c && !/^elementor-(element|widget)$/.test(c)).slice(0, 3).join('.');
    let p = props.filter((k) => {
      if (k === 'border') return cs.border !== '' && !/^0px/.test(cs.border);
      if (inherited.has(k)) return !parent || cs[k] !== parent[k];
      return cs[k] !== defaults[k];
    }).map((k) => `${k}:${k === 'backgroundImage' ? cs[k].slice(0, 90) : cs[k]}`).join(' ');
    if (cs.border === '') p += ' ' + ['Top', 'Right', 'Bottom', 'Left'].map((s) => cs[`border${s}Width`] !== '0px' ? `border${s}:${cs[`border${s}Width`]} ${cs[`border${s}Style`]} ${cs[`border${s}Color`]}` : '').filter(Boolean).join(' ');
    for (const pseudo of ['::before', '::after']) {
      const ps = getComputedStyle(el, pseudo);
      if (ps.content !== 'none' && ps.content !== 'normal') p += ` ${pseudo}{content:${ps.content.slice(0, 20)} bg:${ps.backgroundColor} ${ps.backgroundImage.slice(0, 80)} opacity:${ps.opacity} inset:${ps.top} ${ps.right} ${ps.bottom} ${ps.left}}`;
    }
    out.push(`${'  '.repeat(depth)}${el.tagName.toLowerCase()}${cls ? '.' + cls : ''} [${Math.round(r.left)},${Math.round(r.top + scrollY - ry)} ${Math.round(r.width)}x${Math.round(r.height)}] ${own ? '"' + own + '" ' : ''}${p}`);
    if (depth < 12) [...el.children].forEach((c) => { if (!/^(SCRIPT|STYLE|SVG|PATH|OPTION|BR|NOSCRIPT)$/i.test(c.tagName)) walk(c, depth + 1, cs); });
  };
  walk(root, 0, null);
}
return out.join('\n');
