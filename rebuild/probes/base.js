// The base styles every page inherits, for the top of the new theme's stylesheet: Elementor's breakpoints,
// body text, paragraph margins, headings, links, bold text and boxed container widths at this width.
// Run it at 1440, 1024 and 390.
const pick = (el, props) => {
  if (!el) return '(none on this page)';
  const cs = getComputedStyle(el);
  return props.map((p) => `${p}:${cs[p]}`).join(' ');
};
const text = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'color', 'textTransform'];
const main = document.querySelector('main, #content, .site-main, [data-elementor-type=wp-page], [data-elementor-type=wp-post]') || document.body;
const out = [];
const cfg = window.elementorFrontendConfig;
if (cfg?.responsive?.activeBreakpoints) {
  out.push('Elementor breakpoints (CSS media queries use max-width: value; its JS uses value + 1 as a minimum):');
  for (const [name, b] of Object.entries(cfg.responsive.activeBreakpoints)) out.push(`  ${name}: ${b.direction}-width ${b.value}px`);
} else if (cfg?.breakpoints) {
  out.push('Elementor breakpoints: ' + JSON.stringify(cfg.breakpoints));
}
out.push('', 'body: ' + pick(document.body, [...text, 'backgroundColor']));
const p = [...main.querySelectorAll('p')].find((e) => e.offsetParent && e.textContent.trim().length > 40);
out.push('p: ' + pick(p, [...text, 'margin']));
if (p) {
  const last = [...p.parentElement.children].filter((c) => c.tagName === 'P').pop();
  out.push('p:last-child margin: ' + getComputedStyle(last).margin);
}
for (const h of ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) {
  const el = [...main.querySelectorAll(h)].find((e) => e.offsetParent);
  if (el) out.push(`${h} "${el.textContent.trim().slice(0, 30)}": ` + pick(el, [...text, 'margin']));
}
const a = [...main.querySelectorAll('p a, li a')].find((e) => e.offsetParent);
out.push('link in text: ' + pick(a, ['color', 'textDecorationLine', 'fontWeight']));
const b = [...main.querySelectorAll('strong, b')].find((e) => e.offsetParent);
out.push('bold: ' + pick(b, ['color', 'fontWeight']));
const boxed = [...document.querySelectorAll('.e-con-boxed > .e-con-inner, .elementor-section-boxed > .elementor-container')].find((e) => e.offsetParent);
out.push('boxed container: ' + pick(boxed, ['maxWidth', 'width', 'padding', 'gap']));
const section = boxed?.parentElement;
out.push('its section: ' + pick(section, ['padding', 'gap']));
return out.join('\n');
