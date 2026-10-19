/**
 * @module assets/js/admin/common
 * @description Shared organizer-console helpers: CSRF-aware API calls, flash messages, session-expiry
 *     redirect, sign-out, HTML escaping and small DOM utilities.
 */
import { api, ApiError, friendlyError } from '../lib/api.js';
export { esc } from '../lib/render.js';

export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => [...r.querySelectorAll(s)];

export function flash(message, kind = 'success') {
  const el = $('#flash');
  el.className = kind === 'error' ? 'notice danger' : kind === 'info' ? 'notice info' : 'success';
  el.textContent = message;
  el.scrollIntoView({ block: 'nearest' });
}

export async function call(url, opts = {}) {
  try {
    return await api(url, opts);
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      location.href = `/login?expired=${e.code === 'session_expired' ? 1 : 0}&next=${encodeURIComponent(location.pathname)}`;
      throw e;
    }
    const problems = e?.body?.error?.problems;
    const fields = e?.fields && Object.keys(e.fields).length ? ' ' + Object.values(e.fields).join(' ') : '';
    flash(friendlyError(e) + (problems ? ' ' + problems.join(' ') : '') + fields, 'error');
    throw e;
  }
}

export function confirmText(message) {
  return window.confirm(message);
}

$('#btnLogout')?.addEventListener('click', async () => {
  try { await api('/api/auth/logout', { method: 'POST' }); } catch { /* ignore */ }
  location.href = '/';
});
