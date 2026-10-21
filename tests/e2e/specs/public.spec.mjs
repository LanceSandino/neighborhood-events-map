// Public family experience on the fictional demo stack (14 seeded fictional homes, no zones).
import { test, expect } from '@playwright/test';
import { DEMO, VENTURA, SHOTS, watchConsole } from './helpers.mjs';
import { politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const total = async (request) => (await (await request.get(DEMO + '/api/homes')).json()).homes.length;
const openNow = async (request) => (await (await request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active').length;

test('filters affect list and pins together and persist after reload; favorites persist', async ({ page, request }) => {
  const guard = watchConsole(page);
  const n = await total(request);
  expect(n).toBeGreaterThanOrEqual(14);
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#listCount')).toHaveText(`${await openNow(request)} showing`);
  await expect(page.locator('.leaflet-marker-icon')).toHaveCount(n);
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  const shown = Number((await page.locator('#listCount').textContent()).split(' ')[0]);
  expect(shown).toBeLessThan(n);
  expect(shown).toBeGreaterThan(0);
  await expect(page.locator('.home-card')).toHaveCount(shown);
  expect(await page.locator('.leaflet-marker-icon .pin:not(.dim)').count()).toBeGreaterThanOrEqual(shown);
  await page.locator('.home-card .save').first().click();
  await expect(page.locator('#savedCount')).toHaveText('1');
  await page.reload();
  await expect(page.getByRole('button', { name: 'Sensory-friendly', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#listCount')).toHaveText(`${shown} showing`);
  await expect(page.locator('#savedCount')).toHaveText('1');
  const stored = await page.evaluate(() => Object.keys(localStorage));
  expect(stored).toContain('nhm:maple-hollow-demo:2026');
  await page.locator('#btnReset').click({ force: true }).catch(() => {});
  guard.assertClean();
});

test('selecting a card opens the map preview; keyboard works; My route lists saved stops on the same page', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const card = page.locator('.home-card').nth(2);
  await card.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#mapSelected')).toHaveClass(/open/);
  await expect(page.locator('#mapSelected b')).toHaveText(await card.locator('.card-line b').textContent());
  await expect(page.locator('#route')).toHaveClass(/empty/);
  for (const i of [0, 3, 5]) await page.locator('.home-card .save').nth(i).click();
  await expect(page.locator('#route')).toBeVisible();
  await expect(page.locator('#routeItems .route-item')).toHaveCount(3);
  await expect(page.locator('#routeNotice')).toContainText('not walking directions');
  await expect(page.locator('.leaflet-marker-icon .pin-number')).toHaveCount(3);
  await expect(page.locator('#savedCount')).toHaveText('3');
});

test('nginx refuses percent-escaped paths before the app; escaped query strings still work', async ({ request }) => {
  expect((await request.get(DEMO + '/api/community')).status()).toBe(200);
  for (const p of ['/%61pi/community', '/%6De', '/_internal_%6Cogout', '/api/homes/%31']) {
    expect((await request.get(DEMO + p, { maxRedirects: 0 })).status(), p).toBe(400);
  }
  expect((await request.get(DEMO + '/api/addresses/lookup?q=Maple%20Hollow')).status()).not.toBe(400);
});

test('map failure still leaves a readable list', async ({ page, request }) => {
  const n = await total(request);
  await page.route('**/vendor/leaflet/leaflet.js', (r) => r.abort());
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#mapFallback')).toBeVisible();
  await expect(page.locator('.home-card')).toHaveCount(await openNow(request));
  expect(n).toBeGreaterThan(await openNow(request));
});

for (const [w, h] of [[375, 812], [430, 932], [956, 440], [768, 1024], [1180, 820], [1440, 1000]]) {
  test(`no horizontal overflow and 44px targets at ${w}px (+ screenshots)`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    for (const [name, base] of [['demo', DEMO], ['ventura', VENTURA]]) {
      await page.goto(base + '/');
      await page.waitForTimeout(1500);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${name} ${w}px`).toBeLessThanOrEqual(0);
      const small = await page.evaluate(() => [...document.querySelectorAll('.controls button, .controls select, .mobile-bottom a, .bottom-actions a, .bottom-actions button, .map-tools button')]
        .filter((e) => e.offsetParent && e.getBoundingClientRect().height < 40).map((e) => e.id || e.textContent.trim()));
      expect(small, `${name} ${w}px small targets`).toEqual([]);
      await page.screenshot({ path: `${SHOTS}/${name}-${w}.png`, fullPage: w <= 430 });
    }
  });
}

test('reduced motion and mobile register sheet screenshot', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(DEMO + '/#list-my-house');
  await expect(page.locator('#registerModal')).toBeVisible();
  const scroll = await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior);
  expect(scroll).toBe('auto');
  await page.screenshot({ path: `${SHOTS}/demo-register-390.png` });
});

test('dark appearance follows the system setting on public pages (+ screenshots)', async ({ browser }) => {
  for (const [w, h] of [[375, 812], [1440, 1000]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: 'dark' });
    const page = await ctx.newPage();
    await politeTiles(page);
    await page.goto(DEMO + '/');
    await page.waitForTimeout(800);
    const look = await page.evaluate(() => {
      const lum = (c) => { const [r, g, b] = c.match(/\d+/g).map(Number); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
      const bg = getComputedStyle(document.body).backgroundColor;
      return { scheme: getComputedStyle(document.documentElement).colorScheme, body: lum(bg), card: lum(getComputedStyle(document.querySelector('.home-card')).backgroundColor),
        text: lum(getComputedStyle(document.querySelector('.home-card b')).color), tool: lum(getComputedStyle(document.querySelector('.map-tools button')).backgroundColor),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    expect(look.scheme).toBe('dark');
    expect(look.body).toBeLessThan(40);
    expect(look.card).toBeLessThan(60);
    expect(look.tool).toBeLessThan(60);
    expect(look.text).toBeGreaterThan(200);
    expect(look.overflow).toBeLessThanOrEqual(0);
    await page.screenshot({ path: `${SHOTS}/demo-dark-${w}.png`, fullPage: w <= 430 });
    await ctx.close();
  }
});

test('form check chips are compact and aligned (register step 2)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(DEMO + '/#list-my-house');
  await page.getByRole('button', { name: /Can’t find your address/ }).click();
  await page.locator('#seHouse').fill('4321');
  await page.locator('#seStreet').selectOption({ index: 1 });
  await page.waitForTimeout(400);
  const box = await page.locator('#pinMap .leaflet-overlay-pane path').first().boundingBox();
  await page.mouse.click(box.x + box.width * 0.4, box.y + box.height * 0.6);
  await page.getByRole('button', { name: 'Next: your stop →' }).click();
  const chips = await page.locator('#registerForm .check-chip').evaluateAll((els) => els.filter((e) => e.offsetParent).map((e) => {
    const r = e.getBoundingClientRect(); const i = e.querySelector('input').getBoundingClientRect();
    return { h: Math.round(r.height), iw: Math.round(i.width), ih: Math.round(i.height), centred: Math.abs((i.top + i.height / 2) - (r.top + r.height / 2)) < 2 };
  }));
  expect(chips.length).toBeGreaterThan(4);
  for (const c of chips) {
    expect(c.h).toBeLessThanOrEqual(44);
    expect([c.iw, c.ih]).toEqual([16, 16]);
    expect(c.centred).toBe(true);
  }
  expect(new Set(chips.map((c) => c.h)).size).toBe(1);
});

test('homes not handing out stay off the list and routes; filters show their count; share and show-on-map work', async ({ page, request }) => {
  const all = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  const closed = all.filter((h) => h.status !== 'active');
  expect(closed.length).toBeGreaterThan(0);
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const listed = await page.locator('.home-card .card-line b').allTextContents();
  for (const h of closed) expect(listed).not.toContain(h.address);
  await page.locator('#btnShowClosed').click();
  await expect(page.locator('.home-card')).toHaveCount(all.length);
  await page.locator('#btnShowClosed').click();
  // A route built from every visible home leaves the closed ones out and says so.
  await page.getByRole('button', { name: /^Plan with all \d+ matching homes$/ }).click();
  await expect(page.locator('#routeItems .route-item')).toHaveCount(all.length - closed.length);
  await expect(page.locator('.route-status')).toContainText(`${closed.length} not handing out now`);
  await expect(page.locator('#btnCopyRoute')).toHaveCount(0);
  await page.locator('#btnShareRoute').click();
  await expect(page.locator('.toast')).toContainText(/Link copied|Copy this link/);
  await page.locator('#btnRouteMap').click();
  await expect(page.locator('.leaflet-marker-icon .pin-number').first()).toBeInViewport();
  // Active filters are counted on the Filters button.
  await expect(page.locator('#filterCount')).toBeHidden();
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  await page.locator('#btnFilters').click();
  await page.locator('[data-filter="noScares"]').click();
  await expect(page.locator('#filterCount')).toHaveText('2');
  await page.locator('#btnReset').click();
  await expect(page.locator('#filterCount')).toBeHidden();
});

test('a shared link opens the same stops and filters', async ({ page, browser }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  for (const i of [0, 2]) await page.locator('.home-card .save').nth(i).click();
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  const stops = await page.locator('#routeItems .route-item b').allTextContents();
  const link = await page.locator('#btnShareRoute').getAttribute('data-url');
  expect(link).toContain('stops=');
  expect(link).toContain('f=');
  // Someone else (fresh browser, nothing saved) opens the link.
  const ctx = await browser.newContext();
  const other = await ctx.newPage();
  await politeTiles(other);
  await other.goto(link.replace(/^https?:\/\/[^/]+/, DEMO));
  await expect(other.locator('#route')).toBeInViewport();
  await expect(other.locator('#routeSub')).toContainText('shared with you');
  expect(await other.locator('#routeItems .route-item b').allTextContents()).toEqual(stops);
  await expect(other.getByRole('button', { name: 'Sensory-friendly', exact: true })).toHaveAttribute('aria-pressed', 'true');
  // They keep it as one of their own routes; the link is no longer needed.
  await other.locator('#btnSaveRoute').click();
  await other.locator('#routeName').fill('From a friend');
  await other.locator('#routeNameForm').getByRole('button', { name: 'Save', exact: true }).click();
  await expect(other.locator('#routePick option:checked')).toHaveText('From a friend');
  expect(other.url()).not.toContain('stops=');
  expect(await other.locator('#routeItems .route-item b').allTextContents()).toEqual(stops);
  await ctx.close();
});

test('route: reorder stops, keep the order, share it; start from my location', async ({ browser }) => {
  test.skip(browser.browserType().name() === 'webkit', 'location needs HTTPS; only Chromium can treat the plain-HTTP test host as secure');
  const ctx = await browser.newContext({ permissions: ['geolocation'] });
  const page = await ctx.newPage();
  await politeTiles(page);
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const openHomes = (await (await page.request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active');
  for (const h of openHomes.slice(0, 3)) await page.locator(`.home-card .save[data-save="${h.id}"]`).click();
  const names = () => page.locator('#routeItems .route-item b').allTextContents();
  const before = await names();
  expect(before).toHaveLength(3);
  await page.getByRole('button', { name: `Move ${before[0]} later` }).click();
  expect(await names()).toEqual([before[1], before[0], before[2]]);
  await expect(page.getByRole('button', { name: `Move ${before[0]} later` })).toBeFocused();
  await expect(page.locator('#btnShareRoute')).toHaveAttribute('data-url', /[?&]o=1/);
  await page.reload();
  expect(await names(), 'own order survives a reload').toEqual([before[1], before[0], before[2]]);
  await page.getByRole('button', { name: 'Use the suggested order' }).click();
  expect(await names()).toEqual(before);
  // Start from the visitor's location (next to the third stop): that stop comes first; nothing is put in the link.
  const target = openHomes.find((h) => h.address === before[2]);
  await ctx.setGeolocation({ latitude: target.lat, longitude: target.lon });
  await page.locator('#routeStart').selectOption('here');
  await expect(page.locator('#routeItems .route-item b').first()).toHaveText(before[2]);
  await expect(page.locator('#btnShareRoute')).not.toHaveAttribute('data-url', /start=|lat|lon/);
  await ctx.close();
});

test('"What\'s new" opens release notes with a source link; My house → List my house replaces the sheet', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.locator('.site-footer [data-whats-new]').click();
  const dialog = page.getByRole('dialog', { name: 'What’s new' });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('summary').first()).toContainText('this version');
  await expect(dialog.getByRole('link', { name: 'Source code on GitHub' })).toHaveAttribute('href', /^https:\/\//);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('.mobile-bottom [data-action="house"]').click();
  await page.locator('#houseModal [data-action="register"]').click();
  await expect(page.locator('#houseModal')).toBeHidden();
  await expect(page.locator('#registerModal')).toBeVisible();
  await page.locator('#registerModal .close-modal').click();
  await expect(page.locator('#registerModal')).toBeHidden();
});

test('Appearance panel: Light over a dark system (kept after reload), Auto again, accessibility options', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark', viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  await politeTiles(page);
  await page.goto(DEMO + '/privacy');
  const lum = () => page.evaluate(() => { const [r, g, b] = getComputedStyle(document.body).backgroundColor.match(/\d+/g).map(Number); return 0.2126 * r + 0.7152 * g + 0.0722 * b; });
  expect(await lum()).toBeLessThan(40);
  await page.getByRole('button', { name: 'Appearance and accessibility' }).click();
  const panel = page.getByRole('dialog', { name: 'Appearance' });
  await panel.getByRole('button', { name: '☀️ Light' }).click();
  expect(await lum()).toBeGreaterThan(200);
  await panel.getByLabel(/Larger text/).check();
  await panel.getByLabel(/Reduce motion/).check();
  await panel.getByRole('button', { name: 'Done' }).click();
  await page.reload();
  expect(await lum(), 'applied before paint on the next page').toBeGreaterThan(200);
  expect(await page.evaluate(() => [document.documentElement.dataset.text, document.documentElement.dataset.motion, getComputedStyle(document.documentElement).scrollBehavior])).toEqual(['large', 'reduce', 'auto']);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 'larger text never scrolls sideways').toBeLessThanOrEqual(0);
  await page.getByRole('button', { name: 'Appearance and accessibility' }).click();
  await expect(panel.getByRole('button', { name: '☀️ Light' })).toHaveAttribute('aria-pressed', 'true');
  await panel.getByRole('button', { name: 'Reset' }).click();
  expect(await lum()).toBeLessThan(40);
  expect(await page.evaluate(() => localStorage.getItem('nhm:appearance'))).toBeNull();
  await ctx.close();
});

test('route: skip stops, bring them back, add nearby suggestions, switch between saved and all matching', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const openHomes = (await (await page.request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active');
  // No favourites: every matching home (on request); skip one.
  await page.getByRole('button', { name: /^Plan with all \d+ matching homes$/ }).click();
  const items = page.locator('#routeItems .route-item');
  await expect(items).toHaveCount(openHomes.length);
  const first = (await items.first().locator('b').textContent()).trim();
  await page.getByRole('button', { name: `Skip ${first}` }).click();
  await expect(items).toHaveCount(openHomes.length - 1);
  await expect(page.locator('#btnShareRoute')).not.toHaveAttribute('data-url', new RegExp(`\\b${openHomes.find((h) => h.address === first).id}\\b`));
  await page.getByRole('button', { name: 'Bring back' }).click();
  await expect(items).toHaveCount(openHomes.length);
  // One favourite: suggestions nearby can be added; "All matching" shows everything again.
  await page.getByRole('button', { name: /^🧡 Saved/ }).click();
  await page.locator(`.home-card .save[data-save="${openHomes[0].id}"]`).click();
  await expect(items).toHaveCount(1);
  const add = page.locator('[data-add-stop]').first();
  await expect(add).toBeVisible();
  await add.click();
  await expect(items).toHaveCount(2);
  await page.getByRole('button', { name: /^All matching/ }).click();
  await expect(items).toHaveCount(openHomes.length);
  await page.getByRole('button', { name: /^🧡 Saved/ }).click();
  await expect(items).toHaveCount(2);
});

test('phone in landscape: no side rail, bottom bar shown, list and map side by side', async ({ page }) => {
  await page.setViewportSize({ width: 956, height: 440 });
  await page.goto(DEMO + '/');
  await expect(page.locator('.sidebar')).toBeHidden();
  await expect(page.locator('.mobile-bottom')).toBeVisible();
  const [list, map] = await Promise.all([page.locator('.list-pane').boundingBox(), page.locator('#mapPane').boundingBox()]);
  expect(Math.abs(list.y - map.y)).toBeLessThan(2);
  expect(map.height).toBeLessThanOrEqual(440);
});

test('compact list: one row per home with icons, remembered after reload; long notes are clamped on cards', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const note = page.locator('#homeCards .card-note').first();
  await expect(note).toBeVisible();
  expect(await note.evaluate((n) => getComputedStyle(n).webkitLineClamp)).toBe('2');
  await page.getByRole('button', { name: 'Compact list' }).click();
  await expect(page.locator('#homeCards')).toHaveClass(/compact/);
  await expect(page.locator('#homeCards .card-note').first()).toBeHidden();
  await expect(page.locator('#homeCards .note-icon').first()).toBeVisible();
  const tallest = await page.locator('#homeCards .home-card').evaluateAll((els) => Math.max(...els.map((e) => e.getBoundingClientRect().height)));
  expect(tallest).toBeLessThan(80);
  await page.reload();
  await expect(page.getByRole('button', { name: 'Compact list' })).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Cards' }).click();
  await expect(page.locator('#homeCards')).not.toHaveClass(/compact/);
});

test('a remembered "My current location" start is used straight away on the next visit', async ({ browser }) => {
  test.skip(browser.browserType().name() === 'webkit', 'location needs HTTPS; only Chromium can treat the plain-HTTP test host as secure');
  const ctx = await browser.newContext({ permissions: ['geolocation'] });
  const page = await ctx.newPage();
  await politeTiles(page);
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const openHomes = (await (await page.request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active');
  const target = openHomes[openHomes.length - 1];
  await ctx.setGeolocation({ latitude: target.lat, longitude: target.lon });
  await page.getByRole('button', { name: /^Plan with all \d+ matching homes$/ }).click();
  await page.locator('#routeStart').selectOption('here');
  await expect(page.locator('#routeItems .route-item b').first()).toHaveText(target.address);
  // Next visit: the choice is remembered, the position is asked for again and used without re-choosing.
  await page.reload();
  await expect(page.locator('#routeStart')).toHaveValue('here');
  await expect(page.locator('#routeItems .route-item b').first()).toHaveText(target.address);
  await ctx.close();
});

test('each filter shows how many homes it would list', async ({ page, request }) => {
  const open = (await (await request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active');
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const candy = page.getByRole('button', { name: 'Candy', exact: true });
  await expect(candy.locator('.fcount')).toHaveText(String(open.filter((h) => h.candy).length));
  const bigScares = open.filter((h) => h.jump_scares || h.scare_level === 'intense').length;
  await expect(page.locator('[data-filter="noScares"] .fcount')).toHaveText(String(open.length - bigScares));
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  await expect(page.locator('#listCount')).toHaveText(`${await page.locator('[data-filter="lowSensory"] .fcount').textContent()} showing`);
});

test('saved routes keep their own homes and filters; switch between them, survive a reload, delete one', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const openHomes = (await (await page.request.get(DEMO + '/api/homes')).json()).homes.filter((h) => h.status === 'active');
  const save = (h) => page.locator(`.home-card .save[data-save="${h.id}"]`).click();
  const names = () => page.locator('#routeItems .route-item b').allTextContents();
  const saveAs = async (name) => {
    await page.locator('#btnSaveRoute').click();
    await page.locator('#routeName').fill(name);
    await page.locator('#routeNameForm').getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('#routePick option:checked')).toHaveText(name);
  };
  await save(openHomes[0]); await save(openHomes[1]);
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  await saveAs('Little kids');
  const little = await names();
  expect(little).toHaveLength(2);
  // A second route from the unsaved one: other homes, other filters.
  await page.locator('#routePick').selectOption({ label: 'My unsaved route' });
  await page.getByRole('button', { name: 'Sensory-friendly', exact: true }).click();
  await save(openHomes[0]); await save(openHomes[1]); await save(openHomes[2]);
  await saveAs('Big kids');
  expect(await names()).toEqual([openHomes[2].address]);
  await expect(page.getByRole('button', { name: 'Sensory-friendly', exact: true })).toHaveAttribute('aria-pressed', 'false');
  // Switching brings back that route's homes and filters, also after a reload.
  await page.locator('#routePick').selectOption({ label: 'Little kids' });
  expect(await names()).toEqual(little);
  await expect(page.getByRole('button', { name: 'Sensory-friendly', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await page.reload();
  await expect(page.locator('#routePick option:checked')).toHaveText('Little kids');
  expect(await names()).toEqual(little);
  page.once('dialog', (d) => d.accept());
  await page.locator('#btnDeleteRoute').click();
  await expect(page.locator('#routePick option')).toHaveText(['My unsaved route', 'Big kids']);
});

test('bottom tabs are Map, Rules and My house; Rules opens the guide; the map stays on the neighborhood', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(DEMO + '/');
  await expect(page.locator('.mobile-bottom a')).toHaveText(['🗺️Map', '📋Rules', '🏠My house']);
  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(page.locator('.sidebar .nav a'), 'the desktop sidebar offers the same three places').toHaveText([/Map$/, /Rules$/, /My house$/]);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('.mobile-bottom [data-action="info"]').click();
  await expect(page.locator('#infoModal')).toBeVisible();
  await expect(page.locator('#infoModal .eyebrow')).toContainText('Rules');
  await page.keyboard.press('Escape');
  // Outside the boundary is dimmed, and zooming far out keeps the neighborhood large on screen (zoom buttons are desktop-only).
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.reload();
  await expect(page.locator('path.outside-mask')).toHaveCount(1);
  for (let i = 0; i < 8; i++) await page.locator('#btnZoomOut').click();
  await page.waitForTimeout(800);
  const [area, map] = await Promise.all([page.locator('path[stroke="#f07936"]').first().boundingBox(), page.locator('#map').boundingBox()]);
  expect(area.width, 'the neighborhood still fills a good part of the map').toBeGreaterThan(map.width * 0.25);
});

test('filters are icons with counts on every screen (names for screen readers); Filters expands the rest', async ({ page }) => {
  for (const [w, h] of [[390, 844], [1280, 800]]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(DEMO + '/');
    await expect(page.locator('.metrics')).toHaveCount(0);
    const quick = page.locator('.quick-filters [data-filter]');
    expect(await quick.count()).toBeGreaterThanOrEqual(3);
    for (const name of ['Candy', 'Sensory-friendly', 'Saved only']) {
      const b = page.getByRole('button', { name, exact: true });
      await expect(b).toBeVisible();
      expect(await b.locator('.sr-only').evaluate((e) => e.getBoundingClientRect().width), `${name}: the word is for screen readers only`).toBeLessThanOrEqual(1);
      await expect(b.locator('.fcount')).toHaveText(/^\d+$/);
      expect((await b.boundingBox()).height).toBeGreaterThanOrEqual(44);
    }
    await expect(page.getByRole('button', { name: 'No big scares' })).toBeHidden();
    await page.locator('#btnFilters').click();
    await expect(page.getByRole('button', { name: 'No big scares' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  }
});

test('changing filters zooms the map to the matching homes, and Reset shows the whole neighborhood again', async ({ page, request }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  // The zoom level is in every tile address (/z/x/y); the map keeps only the current level's tiles once it settles.
  const zoom = () => page.evaluate(() => Math.max(...[...document.querySelectorAll('img.leaflet-tile')].map((t) => Number((t.getAttribute('src') || '').match(/\/(\d+)\/\d+\/\d+(?:@2x)?\.png/)?.[1] || 0))));
  await expect.poll(zoom).toBeGreaterThan(0);
  await page.waitForTimeout(800);
  const before = await zoom();
  const one = (await (await request.get(DEMO + '/api/homes')).json()).homes.find((h) => h.status === 'active');
  await page.locator('#search').fill(one.address);
  await expect(page.locator('#listCount')).toHaveText('1 showing');
  await expect.poll(zoom, { timeout: 8000 }).toBeGreaterThan(before);
  await page.locator('#btnFilters').click(); // Reset sits in the expanded Filters panel
  await page.locator('#btnReset').click();
  await expect.poll(zoom, { timeout: 8000 }).toBeLessThanOrEqual(before + 0.5);
});
