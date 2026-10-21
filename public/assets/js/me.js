/**
 * @module assets/js/me
 * @description Resident dashboard: lists only the signed-in user's homes; status buttons and the
 *     edit form PATCH /api/me/homes/{id} (CSRF header). Session expiry is enforced by the server; the
 *     countdown here is only a hint and never polls protected endpoints.
 */
import { api, ApiError, friendlyError } from './lib/api.js';
import { esc } from './lib/render.js';
import { time12 } from './lib/time.js';
import { toast } from './lib/modal.js';

/** Listing yes/no fields; the form shows only those the community offers (partials/_listing_checks). */
const LISTING_FLAGS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'stroller_friendly'];
const $ = (s, root = document) => root.querySelector(s);
let expiresAt = 0;
let idleMinutes = 30;

function timeOptions(select, from, to, value) {
  let html = '';
  for (let h = from; h <= to; h++) for (const m of ['00', '30']) {
    const t = `${String(h).padStart(2, '0')}:${m}`;
    html += `<option value="${t}" ${t === value ? 'selected' : ''}>${time12(t)}</option>`;
  }
  select.innerHTML = html;
}

function handleAuthError(e) {
  if (e instanceof ApiError && e.status === 401) {
    location.href = '/login?expired=' + (e.code === 'session_expired' ? '1' : '0') + '&next=%2Fme';
    return true;
  }
  return false;
}

function touch(info) {
  if (info) { idleMinutes = info.idle_minutes; expiresAt = Date.now() + info.expires_in * 1000; }
  else expiresAt = Date.now() + idleMinutes * 60000;
}

function tick() {
  const left = Math.max(0, Math.round((expiresAt - Date.now()) / 60000));
  $('#sessionHint').textContent = expiresAt === 0 ? '' : left > 0
    ? `For your security you’ll be signed out after ${idleMinutes} minutes of inactivity (about ${left} min left).`
    : 'Your session has probably ended. Saving will ask you to sign in again.';
}

function homeCard(home, eventDate) {
  const node = $('#homeTemplate').content.firstElementChild.cloneNode(true);
  const l = home.listing;
  $('[data-f="address"]', node).textContent = home.address;
  $('[data-f="meta"]', node).textContent = [home.zone_label, l ? `${time12(l.start)}–${time12(l.end)}` : 'No listing', l && !l.published ? 'Hidden from the map' : 'Shown on the map'].filter(Boolean).join(' · ');
  const warn = $('[data-f="warning"]', node);
  const warnings = [];
  if (l && l.pending_review) warnings.push('Waiting for an organizer to approve this new address. It appears on the map once approved.');
  else if (l && l.moderation_hidden) warnings.push('The organizer has hidden this listing. Contact them if you think this is a mistake.');
  if (!home.inside_boundary) warnings.push('This address is outside the current neighborhood boundary, so it is not shown publicly.');
  if (l && !l.current_season) warnings.push(`This listing is from ${l.event_date}. Save it to take part on ${eventDate}.`);
  warn.textContent = warnings.join(' ');
  warn.classList.toggle('hide', warnings.length === 0);
  const form = $('[data-f="form"]', node);
  timeOptions(form.start, 15, 22, l?.start);
  timeOptions(form.end, 15, 23, l?.end);
  if (l) {
    form.scare_level.value = l.scare_level;
    form.published.value = String(l.published);
    form.note.value = l.note;
    for (const k of LISTING_FLAGS) if (form[k]) form[k].checked = !!l[k];
  }
  node.querySelectorAll('[data-status]').forEach((b) => b.setAttribute('aria-pressed', String(l?.status === b.dataset.status)));
  const showError = (e) => { const el = $('[data-f="error"]', node); el.textContent = friendlyError(e); el.classList.remove('hide'); };
  const patch = async (body, msg) => {
    $('[data-f="error"]', node).classList.add('hide');
    try {
      const res = await api(`/api/me/homes/${home.id}`, { method: 'PATCH', body });
      touch();
      node.replaceWith(homeCard(res.home, eventDate));
      toast(msg);
    } catch (e) { if (!handleAuthError(e)) showError(e); }
  };
  node.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', () => patch({ status: b.dataset.status }, `Status updated: ${b.textContent.trim()}`)));
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const body = { start: form.start.value, end: form.end.value, scare_level: form.scare_level.value, published: form.published.value === 'true', note: form.note.value };
    for (const k of LISTING_FLAGS) body[k] = form[k] ? form[k].checked : false; // options the organizer turned off are not offered
    patch(body, 'Saved. The public map updates right away.');
  });
  $('[data-f="release"]', node).addEventListener('click', async () => {
    const answer = prompt(`Type RELEASE to stop managing ${home.address}. Its listing will be removed from the map.`);
    if (answer !== 'RELEASE') return;
    try { await api(`/api/me/homes/${home.id}`, { method: 'DELETE', body: { confirm: 'RELEASE' } }); node.remove(); toast('Home released.'); } catch (e) { if (!handleAuthError(e)) showError(e); }
  });
  return node;
}

function resumeDestination(me) {
  let next = null;
  try { next = localStorage.getItem('nhm:next'); localStorage.removeItem('nhm:next'); } catch { /* ignore */ }
  if (next && next !== '/me' && /^\/(me|admin)(\/[A-Za-z0-9/_-]*)?$/.test(next) && (me.user.is_admin || next.startsWith('/me'))) {
    location.replace(next);
    return true;
  }
  return false;
}

async function load() {
  try {
    const me = await api('/api/me');
    if (!me.claim_outcome && resumeDestination(me)) return;
    touch(me.session);
    if (me.user.is_admin) $('#adminLink').classList.remove('hide');
    const out = $('#claimOutcome');
    if (me.claim_outcome) {
      const o = me.claim_outcome;
      out.className = o.status === 'published' || o.status === 'pending_review' ? 'success' : 'notice';
      out.innerHTML = o.status === 'published'
        ? `🎉 <b>${esc(o.address)}</b> is on the map now. Thanks for handing out treats!`
        : o.status === 'pending_review'
          ? `📝 <b>${esc(o.address)}</b> is saved. Because it is a new address, an organizer approves it before it appears on the map.`
          : o.status === 'already_claimed'
          ? `This home was already claimed by another neighbor, so nothing was changed. <a href="/#list-my-house">Request a review</a> if you think this is a mistake.`
          : esc(o.message || 'Your listing request could not be completed.');
      out.focus();
    }
    const list = $('#homes');
    list.innerHTML = '';
    if (!me.homes.length) list.innerHTML = '';
    const another = $('#listAnother');
    if (another && me.homes.length) {
      $('[data-f="title"]', another).textContent = 'List another home';
      $('[data-f="text"]', another).textContent = 'Manage more than one property? Use the map’s “List your home” form while signed in, or contact the organizer.';
    }
    for (const h of me.homes) list.appendChild(homeCard(h, me.event_date));
  } catch (e) {
    if (!handleAuthError(e)) $('#homes').innerHTML = `<p class="notice danger">${esc(friendlyError(e))}</p>`;
  }
}

$('#btnLogout').addEventListener('click', async () => {
  try { await api('/api/auth/logout', { method: 'POST' }); } catch { /* already signed out */ }
  location.href = '/';
});
// Ends every session of this account (a lost phone, a shared computer), this one included.
$('#btnLogoutAll').addEventListener('click', async () => {
  if (!window.confirm('Sign out on every device, including this one? You can sign in again with an email link.')) return;
  try { await api('/api/me/sign-out-everywhere', { method: 'POST' }); } catch (e) { if (!handleAuthError(e)) { toast(friendlyError(e)); return; } }
  location.href = '/';
});
load();
tick();
setInterval(tick, 30000);
