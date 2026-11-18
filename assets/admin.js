// Copy buttons, revoke confirmation and auto-dismissing toasts for Settings → Site MCP.
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy], [data-copy-from]');
  if (!btn) return;
  const text = btn.dataset.copy ?? document.querySelector(btn.dataset.copyFrom)?.textContent.trim();
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const area = Object.assign(document.createElement('textarea'), { value: text });
    document.body.append(area);
    area.select();
    document.execCommand('copy');
    area.remove();
  }
  btn.classList.add('is-copied');
  const label = btn.querySelector('span');
  const before = label?.textContent;
  if (label) label.textContent = 'Copied';
  setTimeout(() => { btn.classList.remove('is-copied'); if (label) label.textContent = before; }, 1600);
});

document.addEventListener('submit', (e) => {
  const message = e.target.dataset.confirm;
  if (message && !confirm(message)) e.preventDefault();
});

document.querySelectorAll('.smcp-toast').forEach((toast) => {
  setTimeout(() => toast.classList.add('is-hidden'), 3200);
});
