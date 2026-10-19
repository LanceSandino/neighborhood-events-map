// Shared E2E helpers: Mailpit access, link rewriting for the container network, console guards.
import { expect } from '@playwright/test';

export const DEMO = process.env.E2E_DEMO_URL || 'http://host.docker.internal:8088';
export const VENTURA = process.env.E2E_VENTURA_URL || 'http://host.docker.internal:8087';
export const MAILPIT = process.env.E2E_MAILPIT_URL || 'http://host.docker.internal:8026';
export const SHOTS = process.env.E2E_SHOTS || '/work/docs/design/screenshots';

export async function latestLink(request, to, { since = 0, base = DEMO } = {}) {
  for (let i = 0; i < 40; i++) {
    const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + to)}`);
    const data = await res.json();
    const msg = (data.messages || []).find((m) => Date.parse(m.Created) >= since);
    if (msg) {
      const full = await (await request.get(`${MAILPIT}/api/v1/message/${msg.ID}`)).json();
      const m = full.Text.match(/https?:\/\/[^/\s]+(\/auth\/confirm\?t=[A-Za-z0-9_-]{43})/);
      if (m) return { url: base + m[1], subject: full.Subject, text: full.Text, to: full.To };
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error('No email for ' + to);
}

export async function mailTo(request, to) {
  const res = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + to)}`);
  return (await res.json()).messages || [];
}

export function watchConsole(page) {
  const problems = [];
  page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text()); });
  page.on('pageerror', (e) => problems.push(String(e)));
  // Expected API outcomes (409 conflict, 410 used link, 422 validation, 429 throttled) are not page errors.
  // WebKit reports its own <select> drop-down styling as a style-src violation under a strict CSP (the page
  // has no inline styles; the menus render normally), so that one WebKit message is not a page error.
  const webkit = page.context().browser()?.browserType().name() === 'webkit';
  const ignore = (p) => /tile\.openstreetmap\.org/.test(p) || /status of (409|410|422|429)/.test(p)
    || (webkit && /Refused to apply a stylesheet because its hash, its nonce, or 'unsafe-inline'/.test(p));
  return { assertClean: () => expect(problems.filter((p) => !ignore(p)), problems.join('\n')).toEqual([]) };
}

export const uniqueEmail = (tag) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

/** In CI, keep tile requests off the volunteer OSM servers (blank tiles; the UI still renders). */
export async function politeTiles(page) {
  if (process.env.E2E_BLOCK_TILES) {
    await page.route(/tile\.openstreetmap\.org/, (r) => r.fulfill({ status: 200, contentType: 'image/png', body: Buffer.alloc(0) }));
  }
}
