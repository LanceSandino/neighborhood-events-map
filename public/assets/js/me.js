/**
 * @module assets/js/me
 * @description Resident dashboard: lists only the signed-in user's homes with their listing for the event on the
 *     map; status buttons and the edit form PATCH /api/me/homes/{id} (CSRF header); a home not listed for the event
 *     offers "List for <event>" (POST /api/me/homes/{id}/listing) from an empty form, never from an earlier event. Session expiry is enforced by the server; the
 *     countdown here is only a hint and never polls protected endpoints. The event type (events/index.js) fills and
 *     reads its own listing fields, sent as `details`, and supplies its hours and words.
 * @dependencies lib/api.js, lib/render.js, lib/time.js, lib/modal.js, events/index.js
 */
import { api, ApiError, friendlyError } from './lib/api.js';
import { esc } from './lib/render.js';
import { time12 } from './lib/time.js';
import { toast } from './lib/modal.js';
import { eventType } from './events/index.js';

let T = eventType(); // the community's event type, from /api/me
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

function homeCard(home, ev) {
  const node = $('#homeTemplate').content.firstElementChild.cloneNode(true);
  const l = home.listing;
  $('[data-f="address"]', node).textContent = home.address;
  $('[data-f="meta"]', node).textContent = [home.zone_label, l ? `${time12(l.start)}–${time12(l.end)}` : (ev.active ? `Not listed for ${ev.name}` : 'No listing'),
    l ? (l.published ? 'Shown on the map' : 'Hidden from the map') : ''].filter(Boolean).join(' · ');
  const warn = $('[data-f="warning"]', node);
  const warnings = [];
  if (l && l.pending_review) warnings.push('Waiting for an organizer to approve this new address. It appears on the map once approved.');
  else if (l && l.moderation_hidden) warnings.push('The organizer has hidden this listing. Contact them if you think this is a mistake.');
  if (!home.inside_boundary) warnings.push('This address is outside the current neighborhood boundary, so it is not shown publicly.');
  warn.textContent = warnings.join(' ');
  warn.classList.toggle('hide', warnings.length === 0);
  const form = $('[data-f="form"]', node);
  // A home without a listing for this event lists from an empty form: nothing comes from an earlier event.
  if (form.start) timeOptions(form.start, ...T.hours.start, l ? l.start : ev.start); // a sale's hours are per day (its own fields)
  if (form.end) timeOptions(form.end, ...T.hours.end, l ? l.end : ev.end);
  T.initForm(form, ev); // e.g. the season's nights to pick from
  if (l) {
    T.fillForm(form, l.details, l, ev);
    form.published.value = String(l.published);
    form.note.value = l.note;
  } else {
    const statusRow = $('.status-row', node);
    statusRow.classList.add('hide');
    form.classList.add('hide');
    const box = $('[data-f="listFor"]', node);
    box.classList.remove('hide');
    const btn = $('[data-f="listForButton"]', node);
    $('[data-f="listForText"]', node).textContent = home.can_list
      ? `This home is not on the map for ${ev.name} yet. List it to take part; you start from an empty form, nothing is copied from an earlier event.`
      : (ev.active ? 'House listings are closed right now.' : 'There is no event on the map right now. You can list this home when the organizer starts the next one.');
    if (home.can_list) {
      btn.textContent = `List for ${ev.name}`;
      btn.classList.remove('hide');
      btn.addEventListener('click', () => {
        box.classList.add('hide');
        form.classList.remove('hide');
        $('[data-f="save"]', node).textContent = `List for ${ev.name}`;
        form.start.focus();
      });
    }
  }
  node.querySelectorAll('[data-status]').forEach((b) => b.setAttribute('aria-pressed', String(l?.status === b.dataset.status)));
  // A refused field (e.g. a note with contact details) is named in the message, not only "the highlighted fields".
  const showError = (e) => {
    const el = $('[data-f="error"]', node);
    const fields = e instanceof ApiError ? Object.values(e.fields || {}) : [];
    el.textContent = fields.length ? fields.join(' ') : friendlyError(e);
    el.classList.remove('hide');
  };
  const send = async (method, url, body, msg) => {
    $('[data-f="error"]', node).classList.add('hide');
    try {
      const res = await api(url, { method, body });
      touch();
      node.replaceWith(homeCard(res.home, ev));
      toast(msg);
    } catch (e) { if (!handleAuthError(e)) showError(e); }
  };
  const patch = (body, msg) => send('PATCH', `/api/me/homes/${home.id}`, body, msg);
  node.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', () => patch({ status: b.dataset.status }, `Status updated: ${b.textContent.trim()}`)));
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    // Options the organizer turned off are not offered; they read as off.
    const start = form.start ? form.start.value : (l ? l.start : ev.start);
    const end = form.end ? form.end.value : (l ? l.end : ev.end);
    const body = { start, end, published: form.published.value === 'true', note: form.note.value, details: T.formDetails(form),
      ...T.formExtras(form, { start, end, event: ev }) };
    const errors = { ...T.validateDetails(body.details), ...(Array.isArray(body.windows) && !body.windows.length ? { windows: T.copy.noDays } : {}),
      ...(Array.isArray(body.windows) && body.windows.some((w) => w.end <= w.start) ? { hours: 'End time must be later than the start time.' } : {}) };
    if (l) {
      if (body.windows && Object.keys(errors).length) { showError(new Error(Object.values(errors).join(' '))); return; }
      patch(body, 'Saved. The public map updates right away.');
      return;
    }
    if (Object.keys(errors).length) { showError(new Error(Object.values(errors).join(' '))); return; }
    send('POST', `/api/me/homes/${home.id}/listing`, body, `Listed for ${ev.name}. ${T.copy.published}`);
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
    T = eventType(me.event_type);
    touch(me.session);
    if (me.user.is_admin) $('#adminLink').classList.remove('hide');
    const out = $('#claimOutcome');
    if (me.claim_outcome) {
      const o = me.claim_outcome;
      out.className = o.status === 'published' || o.status === 'pending_review' ? 'success' : 'notice';
      out.innerHTML = o.status === 'published'
        ? `🎉 <b>${esc(o.address)}</b> is on the map now. ${esc(T.copy.published)}`
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
    const ev = me.event || { active: true, name: 'this event', start: '17:00', end: '21:00' };
    for (const h of me.homes) list.appendChild(homeCard(h, ev));
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
