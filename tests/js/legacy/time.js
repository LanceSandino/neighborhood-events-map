// Frozen copy of public/assets/js/lib/time.js as released in v0.9.0-beta.1, unchanged below this line: the oracle of
// tests/js/events.test.mjs (the event-type extraction must give Halloween exactly the 0.9 results). Never edit.
/**
 * @module assets/js/lib/time
 * @description Event-timezone clock helpers ("open now", status labels) using Intl only.
 */
export function nowInZone(timeZone, date = new Date()) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(date).map((p) => [p.type, p.value]));
  return { date: `${parts.year}-${parts.month}-${parts.day}`, minutes: Number(parts.hour) * 60 + Number(parts.minute) };
}

export const toMinutes = (hhmm) => { const [h, m] = String(hhmm).split(':').map(Number); return h * 60 + m; };

export function time12(hhmm) {
  const [h, m] = String(hhmm).split(':').map(Number);
  const suffix = h >= 12 ? 'PM' : 'AM';
  const h12 = h % 12 === 0 ? 12 : h % 12;
  return m === 0 ? `${h12} ${suffix}` : `${h12}:${String(m).padStart(2, '0')} ${suffix}`;
}

/** @returns {{key: string, label: string}} */
export function statusOf(home, ctx) {
  if (home.status === 'paused') return { key: 'paused', label: 'Paused' };
  if (home.status === 'out_of_candy') return { key: 'out', label: 'Out of candy' };
  if (home.status === 'done') return { key: 'done', label: 'Done for tonight' };
  if (!ctx || ctx.today !== ctx.eventDate) return { key: 'later', label: `${time12(home.start)}–${time12(home.end)}` };
  const now = ctx.nowMinutes;
  if (now < toMinutes(home.start)) return { key: 'later', label: `Starts ${time12(home.start)}` };
  if (now >= toMinutes(home.end)) return { key: 'done', label: 'Done for tonight' };
  return { key: 'open', label: 'Handing out' };
}
