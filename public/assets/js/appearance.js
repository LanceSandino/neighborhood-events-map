/**
 * @module assets/js/appearance
 * @description Header "Appearance" panel: theme (Auto/Light/Dark) and accessibility options (larger text,
 *     more contrast, reduced motion). Choices apply at once, are saved on this device only and are
 *     re-applied before paint by theme-init.js on every page.
 */
const KEY = 'nhm:appearance';
const COLORS = { light: '#f8f7f4', dark: '#15141d' };
const root = document.documentElement;
const dialog = document.getElementById('appearanceDialog');

function load() {
  try { const p = JSON.parse(localStorage.getItem(KEY) || '{}'); return p && typeof p === 'object' ? p : {}; } catch { return {}; }
}

function save(prefs) {
  try { if (Object.keys(prefs).length) localStorage.setItem(KEY, JSON.stringify(prefs)); else localStorage.removeItem(KEY); } catch { /* applies to this page only */ }
}

function apply(prefs) {
  for (const k of ['theme', 'text', 'contrast', 'motion']) {
    if (prefs[k]) root.setAttribute(`data-${k}`, prefs[k]); else root.removeAttribute(`data-${k}`);
  }
  document.querySelectorAll('meta[name="theme-color"]').forEach((m) => {
    const dark = prefs.theme ? prefs.theme === 'dark' : (m.getAttribute('media') || '').includes('dark');
    m.setAttribute('content', dark ? COLORS.dark : COLORS.light);
  });
  if (!dialog) return;
  dialog.querySelectorAll('[data-pref="theme"]').forEach((b) => b.setAttribute('aria-pressed', String((prefs.theme || 'auto') === b.dataset.value)));
  dialog.querySelectorAll('input[data-pref]').forEach((i) => { i.checked = prefs[i.dataset.pref] === i.dataset.value; });
}

function set(key, value) {
  const prefs = load();
  if (value) prefs[key] = value; else delete prefs[key];
  save(prefs);
  apply(prefs);
}

apply(load());
if (dialog && typeof dialog.showModal === 'function') {
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-appearance]')) { e.preventDefault(); dialog.showModal(); return; }
    if (e.target.closest('[data-appearance-close]')) { dialog.close(); return; }
    if (e.target.closest('[data-appearance-reset]')) { save({}); apply({}); return; }
    const t = e.target.closest('button[data-pref="theme"]');
    if (t) { set('theme', t.dataset.value === 'auto' ? null : t.dataset.value); return; }
    if (e.target === dialog) {
      const r = dialog.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dialog.close();
    }
  });
  dialog.addEventListener('change', (e) => {
    const i = e.target.closest('input[data-pref]');
    if (i) set(i.dataset.pref, i.checked ? i.dataset.value : null);
  });
}
