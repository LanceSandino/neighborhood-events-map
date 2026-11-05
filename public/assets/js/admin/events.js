/**
 * @module assets/js/admin/events
 * @description Organizer → Events (ADR 0011): every event in its group (on the site now, hidden and getting ready,
 *     drafts, past events, archived; D-20261104-02) with its state, how many homes opted in and how many the map
 *     shows; prepare a draft (types this version offers, starting on the type's usual dates: Halloween October 31,
 *     Christmas lights November 1 to January 1, a sale's dates to be announced), edit (name, dates or "to be
 *     announced", tentative, hours, who may come — the event's own access in its type's words, or the community's,
 *     D-20261104-05 —, rules, the thank-you message, the type's own settings and the event's own branding —
 *     its logo (upload and remove, saved at once, D-20261105-03), headline, intro, colours and About text, each optional with the type's default as its placeholder, ADR 0012),
 *     preview a draft or a hidden event (a public event: its page), hide a draft or an upcoming event (residents get
 *     ready in My house, visitors see nothing; D-20261101-01) or create one hidden, announce a draft or a hidden event
 *     (upcoming), open, close (the confirmation shows the thank-you text, that next year's event is created hidden and
 *     where "/" goes; events also close by themselves after their last day), make an upcoming or open event the default
 *     (what "/" shows), archive, export one event's listings (CSRF-checked form POST) and delete an
 *     archived event's listings. Several events may be on at once, one of each kind. Each event's row, summary and form
 *     is its own section (data-event-section) with its type's mark: the one place in the console an event's words and
 *     branding show (D-20261031-52). The server checks everything.
 * @dependencies admin/common.js, lib/api.js, lib/icons.js
 */
import { $, call, esc, flash, fmtDate } from './common.js';
import { csrfToken } from '../lib/api.js';
import { icon } from '../lib/icons.js';

let data = null;
let editing = null; // the event whose form is open (null: a new draft)

const STATE = { draft: 'Draft', hidden: 'Hidden · residents can get ready', upcoming: 'Upcoming', open: 'Open — on the map', closed: 'Closed', archived: 'Archived' };
const accessChoices = (type, value) => [{ value: '', label: 'Same as the community' }, ...((typeOf(type) || {}).access_options || [])]
  .map((o) => `<option value="${esc(o.value)}" ${o.value === (value || '') ? 'selected' : ''}>${esc(o.label)}</option>`).join('');
const ownAccess = (e) => ((typeOf(e.type) || {}).access_options || []).find((o) => o.value === (e.settings || {}).access)?.label;
const PREVIEWABLE = ['draft', 'hidden']; // organizer-only pages; a public event has its own page
const typeOf = (key) => data.types.find((t) => t.key === key);
const oneDay = (key) => (typeOf(key) || {}).schedule_kind === 'single_day';
const drives = (key) => ((typeOf(key) || {}).route_profiles || []).includes('driving');
const hasSetting = (key, name) => ((typeOf(key) || {}).settings || []).includes(name);
const options = (key, name) => Object.entries(((typeOf(key) || {}).setting_options || {})[name] || {});
/** The console's groups, in order; a group without events is left out (the first one says so instead). */
const GROUPS = [
  { title: 'On the site now', states: ['open', 'upcoming'], hint: 'Visitors switch between these, and “/” shows the default. Open events show their homes; upcoming ones show their dates.' },
  { title: 'Hidden, getting ready', states: ['hidden'], hint: 'Residents list their homes in My house. Visitors see nothing until you announce or open it.' },
  { title: 'Drafts', states: ['draft'], hint: 'Only organizers see these.' },
  { title: 'Past events', states: ['closed'], hint: 'Over: visitors find each one under “Past events”, with its thank-you message. Residents can read their listings in My house.' },
  { title: 'Archived', states: ['archived'], hint: 'Gone from the public site. Their listings are kept until you delete them.' },
];
const dates = (e) => (!e.starts_on ? 'Dates to be announced' : (e.starts_on === e.ends_on ? e.starts_on : `${e.starts_on} – ${e.ends_on}`) + (e.dates_tentative ? ' (tentative)' : ''));

function form(e) {
  const type = e ? e.type : (data.types[0] || {}).key;
  const rules = ((e && e.settings && e.settings.rules) || []).join('\n');
  const [first, last] = e ? [e.starts_on, e.ends_on] : ((typeOf(type) || {}).new_dates || [null, null]);
  const tbd = !first;
  return `<form id="eventForm" class="form-grid" data-event-section="${esc(type || '')}" novalidate>
    <div class="field"><label for="evType">Event type</label><select id="evType" ${e ? 'disabled' : ''}>${(e ? [{ key: e.type, name: e.type_name }] : data.types).map((t) => `<option value="${esc(t.key)}" ${t.key === type ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
      <span class="form-caption">${e ? 'The type of an event never changes.' : 'This version offers ' + esc(data.types.map((t) => t.name).join(', ')) + '.'}</span></div>
    <div class="field"><label for="evName">Public name</label><input id="evName" maxlength="60" value="${esc(e ? e.name : '')}" placeholder="${esc((typeOf(type) || {}).name || '')}"></div>
    ${e ? '' : `<div class="field full"><span class="label" id="evStartAsLabel">Start as</span><div class="checks" role="radiogroup" aria-labelledby="evStartAsLabel">
      <label class="check-chip"><input type="radio" name="evStartAs" value="draft" checked> Draft — only organizers see it</label>
      <label class="check-chip"><input type="radio" name="evStartAs" value="hidden"> Hidden — residents can get ready</label></div>
      <span class="form-caption">Hidden: residents list their homes in My house (or use last year’s details) while visitors see nothing. Announce it when visitors may see it.</span></div>`}
    <div class="field full"><label class="check-chip"><input type="checkbox" id="evTbd" ${tbd ? 'checked' : ''}> Dates to be announced</label>
      <label class="check-chip"><input type="checkbox" id="evTentative" ${e && e.dates_tentative ? 'checked' : ''}> The dates are tentative</label>
      <span class="form-caption">Visitors see “dates to be announced” or “tentative”. Opening an event confirms its dates; residents can list once it has dates.</span></div>
    <div class="field ${tbd ? 'hide' : ''}" id="evStartField"><label for="evStart">${oneDay(type) ? 'Date *' : 'First day *'}</label><input id="evStart" type="date" value="${esc(first || '')}"></div>
    <div class="field ${oneDay(type) || tbd ? 'hide' : ''}" id="evEndField"><label for="evEnd">Last day</label><input id="evEnd" type="date" value="${esc(last || '')}"></div>
    <div class="field"><label for="evFrom">Most homes from</label><input id="evFrom" type="time" value="${esc(e ? e.default_start : '17:00')}"></div>
    <div class="field"><label for="evTo">until</label><input id="evTo" type="time" value="${esc(e ? e.default_end : '21:00')}"></div>
    <div class="field full"><label for="evAccess">Who may come</label><select id="evAccess">${accessChoices(type, e && e.settings && e.settings.access)}</select>
      <span class="form-caption">How visitors may reach the homes during this event, shown on its rules and About page. The site never grants gate access.</span></div>
    <div class="field full"><label for="evRules">Event rules (one per line, up to 8)</label><textarea id="evRules" maxlength="1400">${esc(rules)}</textarea>
      <span class="form-caption">Plain text, for example parking or quiet hours. Shown with the event.</span></div>
    <div class="field full"><label for="evClosing">Thank-you message when it closes (optional, up to 400 characters)</label><textarea id="evClosing" maxlength="400" placeholder="${esc(closingPlaceholder(type))}">${esc((e && e.settings && e.settings.closing_message) || '')}</textarea>
      <span class="form-caption">Leave it empty for the default, which names next year's dates. Shown instead of homes once the event is closed.</span></div>
    <div class="field full">${typeFields(type, e)}</div>
    <fieldset class="field full brand-fields" aria-describedby="evBrandHelp"><legend class="label">Branding of this event</legend>
      <p class="form-caption" id="evBrandHelp">Every field is optional: an empty field uses the built-in look of this kind of event, shown in grey. The community’s name and links are the same for every event (Community &amp; branding).</p>
      <div class="form-grid" id="evBrandFields">${brandFields(type, e)}</div>
      <p class="form-caption">${previewLink(e)}</p></fieldset>
    <div class="field full"><div class="modal-actions"><button type="button" class="secondary" id="evCancel">Cancel</button><button type="submit" class="primary">${e ? 'Save event' : 'Save draft'}</button></div></div>
  </form>`;
}

/** The event's logo: shown on its pages and emails instead of the type's drawn mark. Saved as soon as it is chosen; a new event is saved first. */
function logoField(e) {
  if (!e) return '<div class="field full" id="evLogoField"><span class="label">Logo</span><span class="form-caption">Save the draft first, then add its logo. Until then its pages show the drawn mark of this kind of event.</span></div>';
  return `<div class="field full" id="evLogoField"><label for="evLogo">Logo</label><div class="logo-row">${e.logo_url ? `<img class="logo-thumb" src="${esc(e.logo_url)}" alt="Current logo">` : ''}<input type="file" id="evLogo" data-event="${e.id}" accept=".svg,.png,.jpg,.jpeg,.webp">${e.logo_url ? `<button type="button" class="link-button" id="evLogoRemove" data-event="${e.id}">Remove</button>` : ''}</div><span class="form-caption">SVG, PNG, JPEG or WebP, up to 512 KB. Without one, this event’s pages show the drawn mark of its kind. Saved as soon as you choose it.</span></div>`;
}

/** The event's own branding; each field shows the type's default as its placeholder (an empty field keeps it). */
function brandFields(type, e) {
  const b = (e && e.settings && e.settings.branding) || {};
  const d = (typeOf(type) || {}).branding || {};
  const text = (id, key, label, max) => `<div class="field"><label for="${id}">${label}</label><input id="${id}" data-brand="${key}" maxlength="${max}" value="${esc(b[key] || '')}" placeholder="${esc(d[key] || '')}"></div>`;
  const color = (id, key, label) => `<div class="field"><label for="${id}">${label}</label><div class="color-row"><input id="${id}" data-brand="${key}" maxlength="7" spellcheck="false" autocomplete="off" value="${esc(b[key] || '')}" placeholder="${esc(d[key] || '')}" aria-describedby="${id}Help"><input type="color" id="${id}Pick" value="${esc((b[key] || d[key] || '#000000').toLowerCase())}" aria-label="Pick the ${label.toLowerCase()}"></div><span class="form-caption" id="${id}Help">Like ${esc(d[key] || '#38597F')}; text on it stays readable.</span></div>`;
  return [logoField(e), text('evHeadline', 'headline', 'Headline', 40), text('evHeadlineAccent', 'headline_accent', 'Headline (accent part)', 40),
    color('evPrimary', 'primary_color', 'Main colour'), color('evAccent', 'accent_color', 'Accent colour'),
    `<div class="field full"><label for="evIntro">Intro text</label><textarea id="evIntro" data-brand="intro" maxlength="280" placeholder="${esc(d.intro || '')}">${esc(b.intro || '')}</textarea></div>`,
    `<div class="field full"><label for="evAbout">About this event</label><textarea id="evAbout" data-brand="about" maxlength="1500" placeholder="${esc(d.about || '')}">${esc(b.about || '')}</textarea><span class="form-caption">Shown on the event’s About page; empty: the neighborhood’s general text, or the built-in one. Blank lines start a new paragraph.</span></div>`].join('');
}

/** Where to see the branding: a draft's preview, a public event's own page. */
function previewLink(e) {
  if (!e) return 'Save the draft, then preview it to see its look.';
  if (PREVIEWABLE.includes(e.state)) return `<a href="/admin/events/${e.id}/preview">Preview this ${e.state === 'draft' ? 'draft' : 'hidden event'}</a> to see its look (save first).`;
  return e.state === 'archived' ? '' : `<a href="/?e=${e.id}">Open this event’s page</a> to see its look (save first).`;
}

/** A colour's text field and its picker stay in step; the text may be empty (the type's default). */
function bindColors() {
  for (const id of ['evPrimary', 'evAccent']) {
    const textBox = $(`#${id}`);
    const pick = $(`#${id}Pick`);
    if (!textBox || !pick) continue;
    pick.addEventListener('input', () => { textBox.value = pick.value.toUpperCase(); });
    textBox.addEventListener('input', () => { if (/^#[0-9a-f]{6}$/i.test(textBox.value.trim())) pick.value = textBox.value.trim().toLowerCase(); });
  }
}

const closingPlaceholder = (type) => ((typeOf(type) || {}).closing_template || '').replace('{event}', 'This year’s event').replace('{next}', 'next year’s dates');

/** A sale's own settings: up to three sale days, the categories it takes and the early-bird time. */
function saleFields(type, s) {
  const days = s.days || [];
  const cats = s.categories || options(type, 'categories').map(([k]) => k);
  return [`<div class="field full type-field"><span class="label" id="evDaysLabel">Sale days (up to 3, inside the dates above)</span><div class="checks" role="group" aria-labelledby="evDaysLabel">${
    [0, 1, 2].map((i) => `<input id="evDay${i + 1}" class="sale-day-input" type="date" value="${esc(days[i] || '')}" aria-label="Sale day ${i + 1}">`).join('')}</div>
      <span class="form-caption">Leave them empty for a sale of up to three days: every day of it is a sale day. A sale opens once it has its days.</span></div>`,
  `<div class="field full type-field"><span class="label" id="evCatsLabel">Categories residents may list</span><div class="checks" role="group" aria-labelledby="evCatsLabel">${
    options(type, 'categories').map(([k, label]) => `<label class="check-chip"><input type="checkbox" name="evCategory" value="${esc(k)}" ${cats.includes(k) ? 'checked' : ''}> ${esc(label)}</label>`).join('')}</div></div>`,
  `<div class="field type-field"><label for="evEarly">Early birds not before (optional)</label><input id="evEarly" type="time" value="${esc(s.early_bird_time || '')}"></div>`];
}

/** Settings only some types have: the best night and quiet hours (lights), a sale's days, categories and early birds, the drive-by tour (types that allow driving). */
function typeFields(type, e) {
  const s = (e && e.settings) || {};
  const out = hasSetting(type, 'days') ? saleFields(type, s) : [];
  if (hasSetting(type, 'best_night')) out.push(`<div class="field type-field"><label for="evBest">Best night for a neighborhood walk (optional)</label><input id="evBest" type="date" value="${esc(s.best_night || '')}"></div>`);
  if (hasSetting(type, 'quiet_hours')) out.push(`<div class="field type-field"><label for="evQuiet">Quiet hours (optional)</label><input id="evQuiet" maxlength="120" value="${esc(s.quiet_hours || '')}" placeholder="e.g. Please keep it quiet after 10 PM"></div>`);
  if (drives(type)) out.push(`<div class="field full type-field"><label class="check-chip"><input type="checkbox" id="evDriving" ${(s.route_modes || []).includes('driving') ? 'checked' : ''}> ${hasSetting(type, 'days') ? 'Offer a driving tour' : 'Offer a drive-by tour'}</label><span class="form-caption">Driving directions appear only when a routing provider is configured; otherwise visitors get an ordered list of stops.</span></div>`);
  return `<div class="form-grid" id="evTypeFields">${out.join('')}</div>`;
}

function row(e) {
  const a = [];
  const offered = e.type_offered;
  if (PREVIEWABLE.includes(e.state) && offered) a.push(`<a class="secondary" href="/admin/events/${e.id}/preview">Preview</a>`, `<button type="button" class="secondary" data-announce="${e.id}">Announce</button>`);
  if (['draft', 'upcoming'].includes(e.state) && offered) a.push(`<button type="button" class="secondary" data-hide="${e.id}">${e.state === 'draft' ? 'Let residents get ready' : 'Hide'}</button>`);
  if (['draft', 'hidden', 'upcoming'].includes(e.state) && offered) a.push(`<button type="button" class="primary" data-open="${e.id}">Open</button>`);
  if (e.state === 'open' && offered) a.push(`<button type="button" class="primary" data-close="${e.id}">Close</button>`);
  if (['upcoming', 'open'].includes(e.state) && !e.is_default && offered) a.push(`<button type="button" class="secondary" data-default="${e.id}">Make default</button>`);
  if (e.state !== 'archived' && offered) a.push(`<button type="button" class="secondary" data-edit="${e.id}">Edit</button>`);
  if (e.state !== 'archived') a.push(`<button type="button" class="secondary" data-archive="${e.id}">Archive</button>`);
  a.push(`<form class="inline-form" method="post" action="/api/admin/events/${e.id}/export"><input type="hidden" name="_csrf" value="${esc(csrfToken())}"><button type="submit" class="secondary">Export CSV</button></form>`);
  if (e.state === 'archived' && e.opted_in > 0) a.push(`<button type="button" class="danger" data-purge="${e.id}">Delete listings</button>`);
  return `<tr data-event="${e.id}" data-event-section="${esc(e.type)}"><td>${icon(e.type_icon)} <b>${esc(e.name)}</b>${e.is_default ? ' <span class="badge">Default</span>' : ''}<br><span class="form-caption">${esc(e.type_name)}${offered ? '' : ' · not offered by this version'}${ownAccess(e) ? ` · ${esc(ownAccess(e))}` : ''}</span></td>
    <td class="nowrap">${esc(dates(e))}<br><span class="form-caption">${esc(e.default_start)}–${esc(e.default_end)}</span></td>
    <td>${esc(STATE[e.state] || e.state)}${e.state === 'open' && e.activated_at ? `<br><span class="form-caption">since ${esc(fmtDate(e.activated_at))}</span>` : ''}${e.state === 'closed' && e.closed_at ? `<br><span class="form-caption">since ${esc(fmtDate(e.closed_at))}</span>` : ''}</td>
    <td>${e.opted_in}</td><td>${e.state === 'open' ? e.public : '—'}</td><td><div class="row-actions">${a.join('')}</div></td></tr>`;
}

function group(g) {
  const rows = data.events.filter((e) => g.states.includes(e.state));
  // On the site now: as visitors' tabs order them, the default first, then the soonest (to be announced last); other groups newest first.
  if (g.states.includes('open')) rows.sort((a, b) => (b.is_default - a.is_default) || String(a.starts_on || '9999').localeCompare(String(b.starts_on || '9999')));
  const empty = g.states.includes('open') ? '<p class="notice">No event is on the site. Visitors see “No event on the map right now” (or the last thank-you message), and nobody can list a home until you announce or open an event.</p>' : '';
  if (!rows.length && !empty) return '';
  return `<div class="panel" data-event-group="${esc(g.states[0])}"><h2>${esc(g.title)}</h2><p class="form-caption">${esc(g.hint)}</p>${rows.length
    ? `<div class="table-wrap"><table class="data events-table"><thead><tr><th>Event</th><th>Dates</th><th>State</th><th>Homes opted in</th><th>On the map</th><th></th></tr></thead><tbody>${rows.map(row).join('')}</tbody></table></div>`
    : empty}</div>`;
}

function render() {
  $('#admin-events').innerHTML = `
    <div class="panel" id="eventsGuide"><h2>How events work</h2>
      <p class="form-caption"><b>Events close by themselves after their last day.</b> From midnight in the community’s time zone (within the hour) the homes leave the map, visitors find the thank-you message under “Past events”, “/” moves to the current event happening soonest, and next year’s event of the same kind is created hidden. Use Close to end an event earlier.</p>
      <p class="form-caption">Several events can be on at once, one of each kind. Next year’s hidden event lets residents get ready in My house; visitors see it once you announce it. Claims stay with their residents; listings belong to one event, and nothing is copied from an earlier event unless a resident chooses “Use last year's details”.</p></div>
    <div class="panel"><h2>${editing ? 'Edit event' : 'Prepare an event'}</h2><div id="eventFormHost">${editing !== undefined && editing !== false ? form(editing) : '<button type="button" class="primary" id="evNew">+ Prepare an event</button>'}</div></div>
    ${GROUPS.map(group).join('')}`;
  bind();
}

function readForm() {
  const type = $('#evType').value;
  const tbd = $('#evTbd').checked;
  const body = { name: $('#evName').value,
    starts_on: tbd ? null : $('#evStart').value, ends_on: tbd ? null : (oneDay(type) ? $('#evStart').value : ($('#evEnd').value || $('#evStart').value)),
    dates_tentative: !tbd && $('#evTentative').checked, default_start: $('#evFrom').value, default_end: $('#evTo').value,
    settings: { rules: $('#evRules').value.split('\n').map((l) => l.trim()).filter(Boolean), closing_message: $('#evClosing').value.trim(), access: $('#evAccess').value } };
  if ($('#evBest')) body.settings.best_night = tbd ? null : ($('#evBest').value || null);
  if ($('#evQuiet')) body.settings.quiet_hours = $('#evQuiet').value.trim();
  if ($('#evDriving')) body.settings.route_modes = $('#evDriving').checked ? ['walking', 'driving'] : ['walking'];
  if ($('#evDay1')) {
    const days = ['#evDay1', '#evDay2', '#evDay3'].map((id) => $(id).value).filter(Boolean);
    if (days.length && !tbd) body.settings.days = days;
    body.settings.categories = [...document.querySelectorAll('input[name="evCategory"]:checked')].map((i) => i.value);
    body.settings.early_bird_time = $('#evEarly').value || null;
  }
  body.settings.branding = Object.fromEntries([...document.querySelectorAll('#evBrandFields [data-brand]')].map((i) => [i.dataset.brand, i.value.trim()]).filter(([, v]) => v !== ''));
  if (!editing) {
    body.type = type;
    if (document.querySelector('input[name="evStartAs"]:checked')?.value === 'hidden') body.state = 'hidden';
  }
  return body;
}

const byId = (id) => data.events.find((e) => e.id === Number(id));

/** One button kind per life-cycle step: confirm, POST, say what happened, reload. */
function step(attr, confirmText, url, done, body = () => ({ confirm: true })) {
  document.querySelectorAll(`[data-${attr}]`).forEach((b) => b.addEventListener('click', async () => {
    const e = byId(b.dataset[attr]);
    const text = confirmText(e);
    if (text && !window.confirm(text)) return;
    await call(`/api/admin/events/${e.id}/${url}`, { method: 'POST', body: body(e) });
    flash(done(e));
    load();
  }));
}

function bind() {
  $('#evNew')?.addEventListener('click', () => { editing = null; $('#eventFormHost').innerHTML = form(null); bindForm(); });
  bindForm();
  document.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
    editing = byId(b.dataset.edit);
    render();
    $('#evName').focus();
  }));
  step('announce', (e) => `Announce “${e.name}” (${dates(e)})? Visitors see it with its dates and residents can list their homes${e.starts_on ? '' : ' once the dates are set'}. Its homes appear on the map when you open it.`,
    'announce', (e) => `“${e.name}” is announced.`);
  step('hide', (e) => `${e.state === 'draft' ? 'Let residents get ready for' : 'Hide'} “${e.name}”? Visitors do not see it (no tab, no page); residents can list their homes for it in My house${e.starts_on ? '' : ' once the dates are set'}. Announce it when visitors may see it.`,
    'hide', (e) => `“${e.name}” is hidden: residents can get ready.`);
  step('open', (e) => `Open “${e.name}” (${dates(e)})? Its listed homes appear on the map now${e.dates_tentative ? ' and its dates are confirmed' : ''}.${e.ends_on && e.ends_on < data.today ? ' Its last day has passed, so it closes by itself within the hour unless you change its dates first.' : ''}`,
    'open', (e) => `“${e.name}” is open: its homes are on the map now.`);
  step('close', (e) => {
    const p = e.close_preview || { message: '', next: { name: e.name, words: 'dates to be announced' } };
    const after = !e.is_default ? '' : (p.default_after ? ` “/” then shows “${p.default_after}”.` : ' “/” keeps showing the thank-you message until another event is announced or opened.');
    return `Close “${e.name}” now? (Events also close by themselves after their last day.) Its homes leave the map and visitors find this thank-you message under Past events:\n\n${p.message}\n\nNext year's event is created now, hidden: “${p.next.name}”, ${p.next.words}. Residents can get ready for it in My house (list their homes or use last year's details); visitors see it once you announce it.${after}`;
  }, 'close', (e) => `“${e.name}” is closed. Next year's event is ready. It stays hidden until you announce it.`);
  step('default', () => null, 'default', (e) => `“${e.name}” is the default event now: “/” shows it.`, () => ({}));
  step('archive', (e) => `Archive “${e.name}”? It leaves the public site for good${e.is_default ? ' and another public event becomes the default' : ''}. Its listings are kept until you delete them.`,
    'archive', (e) => `“${e.name}” is archived.`);
  document.querySelectorAll('[data-purge]').forEach((b) => b.addEventListener('click', async () => {
    const e = byId(b.dataset.purge);
    if (window.prompt(`Type DELETE to remove the ${e.opted_in} listings of “${e.name}” for good (claims stay). Export them first if you need a copy.`) !== 'DELETE') return;
    const r = await call(`/api/admin/events/${e.id}/purge-listings`, { method: 'POST', body: { confirm: 'DELETE' } });
    flash(`Deleted ${r.purged} listing(s) of “${e.name}”.`);
    load();
  }));
}

function syncDates() {
  const type = $('#evType').value;
  const tbd = $('#evTbd').checked;
  $('#evStartField').classList.toggle('hide', tbd);
  $('#evEndField').classList.toggle('hide', tbd || oneDay(type));
  $('#evTentative').disabled = tbd;
  $('#evStart').previousElementSibling.textContent = oneDay(type) ? 'Date *' : 'First day *';
}

/** Choosing or removing a logo saves it at once and redraws only its own field, so what is typed elsewhere in the form stays. */
function bindLogo() {
  const send = async (fd, done) => {
    fd.append('event', String(editing.id));
    const r = await call('/api/admin/community/logo', { method: 'POST', body: fd });
    editing.logo_url = r.url;
    $('#evLogoField').outerHTML = logoField(editing);
    flash(done);
  };
  $('#evBrandFields').addEventListener('change', (ev) => {
    if (ev.target.id !== 'evLogo' || !ev.target.files[0]) return;
    const fd = new FormData();
    fd.append('logo', ev.target.files[0]);
    send(fd, 'Logo saved.');
  });
  $('#evBrandFields').addEventListener('click', (ev) => {
    if (ev.target.id !== 'evLogoRemove') return;
    const fd = new FormData();
    fd.append('remove', '1');
    send(fd, 'Logo removed.');
  });
}

function bindForm() {
  const f = $('#eventForm');
  if (!f) return;
  $('#evType').addEventListener('change', () => {
    const type = $('#evType').value;
    const [first, last] = (typeOf(type) || {}).new_dates || [null, null];
    $('#evTbd').checked = !first;
    $('#evStart').value = first || '';
    $('#evEnd').value = last || '';
    $('#evClosing').placeholder = closingPlaceholder(type);
    $('#evAccess').innerHTML = accessChoices(type, '');
    $('#evTypeFields').outerHTML = typeFields(type, null);
    $('#evBrandFields').innerHTML = brandFields(type, null); // the new type's defaults as placeholders
    bindColors();
    syncDates();
  });
  bindColors();
  bindLogo();
  $('#evTbd').addEventListener('change', syncDates);
  syncDates();
  $('#evCancel').addEventListener('click', () => { editing = false; render(); });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = readForm();
    if (editing) {
      try {
        await call(`/api/admin/events/${editing.id}`, { method: 'PUT', body });
      } catch (e) {
        if (e?.code !== 'listings_move' || !window.confirm(`${e.message}\n\nMove them to ${body.starts_on}?`)) return;
        await call(`/api/admin/events/${editing.id}`, { method: 'PUT', body: { ...body, move_listings: true } });
      }
      flash('Event saved.');
    } else {
      await call('/api/admin/events', { method: 'POST', body });
      flash(body.state === 'hidden' ? 'Hidden event saved: residents can get ready. Announce or open it when you are ready.' : 'Draft saved. Preview it, then announce or open it when you are ready.');
    }
    editing = false;
    load();
  });
}

async function load() {
  data = await call('/api/admin/events');
  if (editing) editing = data.events.find((e) => e.id === editing.id) || false;
  render();
}
editing = false;
load();
