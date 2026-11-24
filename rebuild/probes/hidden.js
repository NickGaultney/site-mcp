// Elements Elementor hides on some devices, with the devices. Hidden on every device means visitors never
// see it: don't rebuild it. Hidden on some means the new theme hides it at the same breakpoints.
const out = [];
document.querySelectorAll('[class*="elementor-hidden-"]').forEach((el) => {
  if (el.parentElement.closest('[class*="elementor-hidden-"]')) return;
  const devices = [...el.classList].filter((c) => c.startsWith('elementor-hidden-')).map((c) => c.slice(17));
  const all = ['desktop', 'tablet', 'mobile'].every((d) => devices.includes(d));
  const text = el.textContent.replace(/\s+/g, ' ').trim().slice(0, 60);
  out.push(`${all ? 'HIDDEN EVERYWHERE, do not rebuild' : 'hidden on ' + devices.join(', ')}: ${el.dataset.widget_type || el.dataset.element_type || el.tagName.toLowerCase()} .elementor-element-${el.dataset.id} "${text}"`);
});
return out.length ? out.join('\n') : 'Nothing is hidden per device on this page.';
