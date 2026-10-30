/**
 * @module assets/js/lib/render
 * @description HTML fragments for house cards, tags, selected-home panel and pins. Every
 *     value from the page or API data (resident text and ids alike) goes through esc(). Mirrors templates/home/_card.html.twig.
 */
import { statusOf, time12 } from './time.js';
import { isBigScare, isLowSensory } from './filters.js';

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const isScary = isBigScare;
/**
 * One vocabulary everywhere (pins, legend, cards, filters): 👻 Spooky, 🧩 Sensory-friendly, 🍺 21+, 🍬 Treats, in that
 * order. With a quick filter on (focus: the filters object), the homes that match show that filter's symbol, so the
 * map shows what the visitor picked.
 */
const KINDS = { spooky: ['👻', 'scary'], sensory: ['🧩', 'quiet'], adult: ['🍺', 'adult'], treats: ['🍬', ''] };
function kindOf(h, focus = {}) {
  if (focus.adult && h.adult_drinks) return 'adult';
  if (focus.lowSensory && isLowSensory(h)) return 'sensory';
  if (focus.candy && h.candy) return 'treats';
  return isScary(h) ? 'spooky' : isLowSensory(h) ? 'sensory' : h.adult_drinks ? 'adult' : 'treats';
}
export const symbolOf = (h, focus) => KINDS[kindOf(h, focus)][0];
export const pinClass = (h, focus) => KINDS[kindOf(h, focus)][1];

export function tagsHtml(h) {
  const t = [];
  if (h.candy) t.push(['🍬 Candy', 'candy']);
  if (isLowSensory(h)) t.push(['🧩 Sensory-friendly', 'quiet']);
  if (h.nonfood) t.push(['🎃 Non-food', 'teal', 'teal-pumpkin']); // Teal Pumpkin Project: non-food treats
  // Visitors see one "big scares" idea for jump scares or an intense setup.
  if (isScary(h)) t.push(['😱 Big scares', 'spooky']);
  else if (h.scare_level === 'mild') t.push(['🎃 Mild scares', 'spooky']);
  if (h.flashing_lights) t.push(['🔦 Flashing lights', 'strobe']);
  if (h.loud_audio) t.push(['🔊 Loud audio', 'strobe']);
  if (h.adult_drinks) t.push(['🍺 21+', 'drinks']);
  if (h.stroller_friendly) t.push(['👶 Kid-friendly', 'quiet']);
  // Icon and words are separate so the compact list can show icons only (the words stay for screen readers).
  return t.map(([label, cls, iconCls]) => {
    const [icon, ...words] = label.split(' ');
    return `<span class="tag ${cls}" title="${words.join(' ')}"><span aria-hidden="true"${iconCls ? ` class="${iconCls}"` : ''}>${icon}</span><span class="tag-text"> ${words.join(' ')}</span></span>`;
  }).join('');
}

export function metaLine(h, ctx) {
  const zone = ctx.zoneLabels[h.zone];
  return `${zone ? esc(zone) + ' · ' : ''}<span class="card-hours">${time12(h.start)}–${time12(h.end)}</span>`;
}

export function cardHtml(h, ctx, { saved, active, focus }) {
  const st = statusOf(h, ctx);
  const statusChip = st.key === 'later' && !st.label.startsWith('Starts') ? '' : `<span class="card-status ${st.key}">${esc(st.label)}</span>`;
  return `<li class="home-card${active ? ' active' : ''}" data-id="${esc(h.id)}" tabindex="0" aria-label="${esc(h.address)}">
    <div class="card-symbol" aria-hidden="true">${symbolOf(h, focus)}</div>
    <div class="card-main">
      <div class="card-line"><b>${esc(h.address)}</b><button type="button" class="save" data-save="${esc(h.id)}" aria-pressed="${saved}" aria-label="${saved ? 'Remove from' : 'Save to'} my stops: ${esc(h.address)}">${saved ? '♥' : '♡'}</button></div>
      <div class="card-meta">${metaLine(h, ctx)} ${statusChip}</div>
      <div class="tags">${tagsHtml(h)}${h.note ? '<span class="tag note-icon" title="Has a note — open the home to read it"><span aria-hidden="true">📝</span><span class="tag-text"> Note</span></span>' : ''}</div>
      ${h.note ? `<p class="card-note">${esc(h.note)}</p>` : ''}
    </div>
  </li>`;
}

export function selectedHtml(h, ctx, saved) {
  const st = statusOf(h, ctx);
  return `<button type="button" class="x" data-close-selected aria-label="Close">×</button>
    <b>${esc(h.address)}</b>
    <p>${metaLine(h, ctx)}${st.key === 'later' && !st.label.startsWith('Starts') ? '' : ` · <span class="card-status ${st.key}">${esc(st.label)}</span>`}</p>
    ${h.note ? `<p>${esc(h.note)}</p>` : ''}
    <div class="tags">${tagsHtml(h)}</div>
    <button type="button" class="primary" data-save="${esc(h.id)}" aria-pressed="${saved}">${saved ? '♥ Saved' : '♡ Save this stop'}</button>
    <p><button type="button" class="link-button" data-report="${esc(h.id)}">Report this listing</button></p>`;
}

export function pinHtml(h, { dim, number, focus }) {
  return `<div class="pin ${pinClass(h, focus)}${dim ? ' dim' : ''}${h.status !== 'active' ? ' inactive' : ''}"><span>${symbolOf(h, focus)}</span>${number ? `<i class="pin-number">${number}</i>` : ''}</div>`;
}

/** Leaflet style for a zone outline; with a selected zone, it stands out and the others recede. */
export function zoneStyle(z, selection) {
  // selection: nothing, one zone key, or a Set of keys (a walking area).
  const keys = selection instanceof Set ? selection : new Set(selection ? [selection] : []);
  const selected = keys.size > 0;
  if (z.status === 'coming_soon') return { color: '#a59cb2', weight: 1, dashArray: '2 6', fill: true, fillColor: '#a59cb2', fillOpacity: selected ? 0.04 : 0.10, opacity: selected ? 0.5 : 0.9 };
  if (keys.has(z.key)) return { color: '#f07936', weight: 3, dashArray: null, fill: true, fillColor: '#f07936', fillOpacity: 0.12, opacity: 1 };
  if (selected) return { color: '#7d8f86', weight: 1, dashArray: '4 6', fill: true, fillColor: '#1c1b29', fillOpacity: 0.10, opacity: 0.5 };
  return { color: z.residential ? '#7d8f86' : '#a59cb2', weight: 1, dashArray: '4 6', fill: !z.residential, fillOpacity: 0.06, opacity: 1 };
}
