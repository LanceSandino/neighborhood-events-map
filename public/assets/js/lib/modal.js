/**
 * @module assets/js/lib/modal
 * @description Accessible dialogs / mobile bottom sheets: focus moves in on open and returns on close,
 *     Escape and backdrop close, Tab is kept inside the dialog. No animation (sensory-friendly).
 */
const stack = [];

function focusables(el) {
  return [...el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter((n) => n.offsetParent !== null || n === document.activeElement);
}

export function openModal(id) {
  const wrap = document.getElementById(id);
  if (!wrap) return null;
  stack.push({ wrap, returnTo: document.activeElement });
  wrap.style.zIndex = String(2000 + stack.length); // a dialog opened from another one is always on top
  wrap.hidden = false;
  wrap.classList.add('open');
  document.body.style.overflow = 'hidden';
  const first = focusables(wrap).find((n) => !n.classList.contains('close-modal')) || focusables(wrap)[0];
  if (first) first.focus();
  return wrap;
}

export function closeModal(wrap) {
  const i = stack.findIndex((s) => s.wrap === wrap);
  if (i < 0) return;
  const [{ returnTo }] = stack.splice(i, 1);
  wrap.classList.remove('open');
  wrap.hidden = true;
  if (!stack.length) document.body.style.overflow = '';
  if (returnTo && typeof returnTo.focus === 'function') returnTo.focus();
}

export function closeTopModal() {
  if (stack.length) closeModal(stack[stack.length - 1].wrap);
}

export function initModals() {
  document.addEventListener('click', (e) => {
    const closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.modal-wrap')); return; }
    if (e.target.classList && e.target.classList.contains('modal-wrap')) closeModal(e.target);
  });
  document.addEventListener('keydown', (e) => {
    if (!stack.length) return;
    const { wrap } = stack[stack.length - 1];
    if (e.key === 'Escape') { e.preventDefault(); closeModal(wrap); return; }
    if (e.key === 'Tab') {
      const f = focusables(wrap);
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
}

export function toast(message, ms = 3200) {
  const t = document.createElement('div');
  t.className = 'toast';
  t.setAttribute('role', 'status');
  t.textContent = message;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), ms);
}
