/**
 * @module assets/js/login
 * @description Passwordless sign-in: PoW in a worker, then POST /api/auth/request-link (neutral reply).
 */
import { api, ApiError, friendlyError } from './lib/api.js';
import { solvePow } from './lib/pow.js';
import { esc } from './lib/render.js';

const form = document.getElementById('loginForm');
// Remember a safe, non-sensitive destination (e.g. /admin/disputes) for after the email link.
const next = new URLSearchParams(location.search).get('next') || '';
try {
  if (/^\/(me|admin)(\/[A-Za-z0-9/_-]*)?$/.test(next)) localStorage.setItem('nhm:next', next); else localStorage.removeItem('nhm:next');
} catch { /* storage unavailable: land on /me */ }
const $ = (s) => document.querySelector(s);
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = $('#loginEmail').value.trim();
  $('[data-error="email"]').textContent = '';
  $('#loginError').classList.add('hide');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { $('[data-error="email"]').textContent = 'Enter a valid email address.'; return; }
  const btn = form.querySelector('button[type=submit]');
  btn.disabled = true;
  $('#powStatus').classList.remove('hide');
  try {
    const pow = await solvePow('login', (p) => { $('#powBar').style.width = `${Math.round(p * 100)}%`; });
    const res = await api('/api/auth/request-link', { method: 'POST', body: { email, pow, website: $('#loginWebsite')?.value || '' } });
    form.hidden = true;
    const ok = $('#loginSuccess');
    ok.innerHTML = `<b>📬 Check your email.</b><br>${esc(res.message)}`;
    ok.classList.remove('hide');
    ok.focus();
  } catch (ex) {
    if (ex instanceof ApiError && ex.fields?.email) $('[data-error="email"]').textContent = ex.fields.email;
    $('#loginError').textContent = friendlyError(ex);
    $('#loginError').classList.remove('hide');
  } finally {
    btn.disabled = false;
    $('#powStatus').classList.add('hide');
  }
});
