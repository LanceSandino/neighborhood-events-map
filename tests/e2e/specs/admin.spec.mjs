// Organizer console on the Ventura Reserve example stack (real county plats): sign in by email link, review status/warnings,
// draw a second (disconnected) area with Leaflet.draw, save (approval resets), then restore the
// exported official plat geometry by import and verify an exact export round trip. Never approves.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { VENTURA, SHOTS, latestLink, watchConsole } from './helpers.mjs';
import { politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const ORGANIZER = process.env.E2E_ORGANIZER || 'e2e-organizer@example.test';
const VENTURA_MAILPIT = process.env.E2E_VENTURA_MAILPIT_URL || 'http://host.docker.internal:8025';

test('organizer reviews the real-plat example, draws, saves, re-imports and exports identically', async ({ page, request }) => {
  const guard = watchConsole(page);
  process.env.E2E_MAILPIT_URL = VENTURA_MAILPIT;
  await page.goto(VENTURA + '/login');
  const since = Date.now() - 1000;
  await page.locator('#loginEmail').fill(ORGANIZER);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  const res = await fetch(`${VENTURA_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + ORGANIZER)}`).then((r) => r.json());
  const msg = res.messages.find((m) => Date.parse(m.Created) >= since);
  const full = await fetch(`${VENTURA_MAILPIT}/api/v1/message/${msg.ID}`).then((r) => r.json());
  const url = VENTURA + full.Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)[0];
  await page.goto(url);
  await page.getByRole('button', { name: 'Confirm sign-in' }).click();
  await expect(page).toHaveURL(/\/me$/);
  await expect(page.locator('#adminLink')).toBeVisible();

  await page.goto(VENTURA + '/admin');
  await expect(page.locator('.checklist')).toContainText('Boundary approval');
  await expect(page.locator('#admin-overview')).not.toContainText('overlap'); // the recorded plats do not overlap
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({ path: `${SHOTS}/ventura-admin-overview.png`, fullPage: true });

  await page.goto(VENTURA + '/admin/community/map');
  await expect(page.locator('#status')).toContainText('not approved');
  for (const z of ['phase-1', 'phase-2', 'phase-3']) await expect(page.locator('#layers')).toContainText('Zone ' + z);
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOTS}/ventura-admin-map.png` });

  const original = await (await page.request.get(VENTURA + '/api/admin/geometries/export')).text();
  fs.writeFileSync('/tmp/ventura-original.geojson', original);

  // Draw a separate rectangle as an extra boundary area (disconnected), then save.
  await page.locator('#drawAs').selectOption('boundary');
  await page.locator('.leaflet-draw-draw-rectangle').click();
  const box = await page.locator('#editorMap').boundingBox();
  await page.mouse.move(box.x + 60, box.y + 60);
  await page.mouse.down();
  await page.mouse.move(box.x + 140, box.y + 120, { steps: 5 });
  await page.mouse.up();
  await expect(page.locator('#status')).toContainText('unsaved changes');
  await page.getByRole('button', { name: 'Save geometry' }).click();
  await expect(page.locator('#flash')).toContainText(/Saved as version v\d+/);
  const before = JSON.parse(original).features.filter((f) => f.properties.kind === 'boundary').length;
  const drawn = JSON.parse(await (await page.request.get(VENTURA + '/api/admin/geometries/export')).text());
  expect(drawn.features.filter((f) => f.properties.kind === 'boundary').length).toBe(before + 1);
  expect(drawn.features.filter((f) => f.properties.kind === 'zone').length).toBe(JSON.parse(original).features.filter((f) => f.properties.kind === 'zone').length);

  // Restore the official layers by importing the earlier export; export must match it exactly.
  await page.locator('#importFile').setInputFiles('/tmp/ventura-original.geojson');
  await expect(page.locator('#flash')).toContainText('Imported into the editor');
  await page.getByRole('button', { name: 'Save geometry' }).click();
  await expect(page.locator('#flash')).toContainText(/Saved as version v\d+/);
  const restored = await (await page.request.get(VENTURA + '/api/admin/geometries/export')).text();
  expect(restored).toBe(original);
  const pub = await (await request.get(VENTURA + '/api/community')).json();
  expect(pub.registration.open).toBe(false);

  // A zone's name can be dragged to where it reads best; the public map gets that spot, and Reset gives it back.
  const handle = page.locator('.zone-name-handle').first();
  const zoneName = (await handle.textContent()).trim();
  const nameBox = await handle.locator('span').boundingBox();
  await page.mouse.move(nameBox.x + nameBox.width / 2, nameBox.y + nameBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(nameBox.x + nameBox.width / 2 + 60, nameBox.y + nameBox.height / 2 + 40, { steps: 8 });
  await page.mouse.up();
  await expect(page.locator('#flash')).toContainText(`Moved the name “${zoneName}”`);
  const zoneOf = (list) => list.find((z) => z.label === zoneName);
  const placed = zoneOf((await (await request.get(VENTURA + '/api/community')).json()).zones).label_at;
  expect(placed).toHaveLength(2);
  await page.goto(VENTURA + '/admin/community');
  // The organizer sees the event type; this version offers Halloween only (D-20261031-01).
  await expect(page.getByLabel('Event type')).toHaveValue('halloween');
  await expect(page.locator('#eType option')).toHaveText(['Halloween']);
  const row = page.locator('#zoneRows tr').filter({ has: page.locator(`[data-z="label"][value="${zoneName}"]`) });
  await row.getByRole('button', { name: 'Reset' }).click();
  await page.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.locator('#flash')).toContainText('Settings saved.');
  expect(zoneOf((await (await request.get(VENTURA + '/api/community')).json()).zones).label_at).toBeNull();
  expect((await (await request.get(VENTURA + '/api/event')).json()).event.type).toBe('halloween');
  guard.assertClean();
});

test('session-expired sign-in returns an organizer to the page they were on', async ({ page }) => {
  await page.goto(VENTURA + '/login?expired=1&next=%2Fadmin%2Fdisputes');
  await expect(page.getByRole('heading', { name: 'Session expired' })).toBeVisible();
  const since = Date.now() - 1000;
  await page.locator('#loginEmail').fill(ORGANIZER);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  let msg;
  for (let i = 0; i < 30 && !msg; i++) {
    const res = await fetch(`${VENTURA_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + ORGANIZER)}`).then((r) => r.json());
    msg = res.messages.find((m) => Date.parse(m.Created) >= since);
    if (!msg) await new Promise((r) => setTimeout(r, 500));
  }
  const full = await fetch(`${VENTURA_MAILPIT}/api/v1/message/${msg.ID}`).then((r) => r.json());
  await page.goto(VENTURA + full.Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)[0]);
  await page.getByRole('button', { name: 'Confirm sign-in' }).click();
  await expect(page).toHaveURL(/\/admin\/disputes$/);
  await expect(page.getByRole('heading', { name: 'Review requests' })).toBeVisible();
});

// A 1×1 PNG: the smallest valid logo.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==', 'base64');

// One sign-in covers both checks: the per-email sign-in link limit (3 per 15 minutes) allows three sign-ins per run.
test('organizer downloads the claims list as a CSV (a CSRF-checked POST, never a plain link) and uploads a logo through the upload pool', async ({ page }) => {
  const guard = watchConsole(page);
  const since = Date.now() - 1000;
  await page.goto(VENTURA + '/login?next=%2Fadmin');
  await page.locator('#loginEmail').fill(ORGANIZER);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  let msg;
  for (let i = 0; i < 30 && !msg; i++) {
    const res = await fetch(`${VENTURA_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + ORGANIZER)}`).then((r) => r.json());
    msg = res.messages.find((m) => Date.parse(m.Created) >= since);
    if (!msg) await new Promise((r) => setTimeout(r, 500));
  }
  const full = await fetch(`${VENTURA_MAILPIT}/api/v1/message/${msg.ID}`).then((r) => r.json());
  await page.goto(VENTURA + full.Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)[0]);
  await page.getByRole('button', { name: 'Confirm sign-in' }).click();
  await expect(page).toHaveURL(/\/admin$/);

  await expect(page.locator('a[href*="claims.csv"]')).toHaveCount(0);
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Export claims (CSV, private)' }).click()]);
  expect(download.suggestedFilename()).toMatch(/^claims-[\d-]+\.csv$/);
  expect(fs.readFileSync(await download.path(), 'utf8')).toMatch(/^address_id,address,zone/);
  await expect(page.locator('.checklist')).toBeVisible(); // still on the console

  // The one place a file goes up: nginx sends it to PHP-FPM's upload pool, the only pool that accepts files.
  await page.goto(VENTURA + '/admin/community');
  await page.locator('#bLogo').setInputFiles({ name: 'e2e-logo.png', mimeType: 'image/png', buffer: PNG });
  await expect(page.getByText('Logo updated.')).toBeVisible();
  await expect(page.getByText(/Current: .+/)).toBeVisible();
  await page.locator('#bLogoRemove').click();
  await expect(page.getByText('Logo removed.')).toBeVisible();
  await expect(page.getByText(/Current: .+/)).toHaveCount(0);

  // Even signed in, a multipart body to any other endpoint never reaches PHP.
  for (const path of ['/api/admin/community', '/api/auth/request-link', '/api/me/homes', '/']) {
    const res = await page.request.post(VENTURA + path, { multipart: { x: '1' } });
    expect(res.status(), path).toBe(415);
  }
  guard.assertClean();
});

