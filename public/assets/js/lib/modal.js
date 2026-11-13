/**
 * @module assets/js/lib/modal
 * @description Dialogs / mobile bottom sheets on native <dialog> opened with showModal(): the browser makes the
 *     page inert, keeps focus inside, puts a dialog opened from another on top, closes on Escape (and the
 *     back gesture) and returns focus to the opener. Here: initial focus on the first control that is not the
 *     close button, [data-close] buttons, backdrop click (lib/light-dismiss.js) and toasts. No animation.
 * @dependencies lib/light-dismiss.js
 */
import { lightDismiss } from './light-dismiss.js';

const open = []; // in the order they were opened, for closeTopModal()

function focusables(el) {
  return [...el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    .filter((n) => n.offsetParent !== null);
}

export function openModal(id) {
  const dialog = document.getElementById(id);
  if (!dialog || typeof dialog.showModal !== 'function') return null;
  if (!dialog.open) {
    lightDismiss(dialog);
    dialog.showModal();
    open.push(dialog);
    dialog.addEventListener('close', () => { const i = open.indexOf(dialog); if (i >= 0) open.splice(i, 1); }, { once: true });
  }
  const f = focusables(dialog);
  const first = f.find((n) => !n.classList.contains('close-modal')) || f[0];
  if (first) first.focus();
  return dialog;
}

export function closeModal(dialog) {
  if (dialog?.open) dialog.close();
}

export function closeTopModal() {
  closeModal(open[open.length - 1]);
}

export function initModals() {
  document.addEventListener('click', (e) => {
    const closer = e.target.closest('[data-close]');
    if (closer) closeModal(closer.closest('dialog'));
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
