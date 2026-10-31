// The Christmas lights event type on the fictional lights demo stack (examples/demo-lights: 10 invented homes for
// Dec 1, 2026 – Jan 3, 2027). The browser clock is pinned to nights of the season (page.clock), so statuses never
// depend on the day the suite runs; the server answers with its own clock. Tests that add or change a home give it
// back, so the demo stays the same for the next run.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { LIGHTS, LIGHTS_MAILPIT, SHOTS, atLocal, watchConsole, politeTiles, uniqueEmail } from './helpers.mjs';

const TZ = 'America/Chicago';
const DEC12 = atLocal('2026-12-12', '19:30', TZ); // 60 Evergreen Ct is off this night
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.beforeEach(async ({ page }) => { await politeTiles(page); });

/** Opens the lights map at a moment of the season, with a clean device. */
async function openAt(page, when, path = '/') {
  await page.clock.install({ time: when });
  await page.goto(LIGHTS + path);
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#listCount')).not.toHaveText('');
}
const cards = (page) => page.locator('#homeCards .home-card');
const addresses = async (page) => (await cards(page).locator('.card-line b').allTextContents()).sort();
const count = async (page) => Number((await page.locator('#listCount').textContent()).split(' ')[0]);

test('the lights theme, hero, season row and words; no safety promise', async ({ page, request }) => {
  const guard = watchConsole(page);
  const event = (await (await request.get(LIGHTS + '/api/event')).json()).event;
  expect([event.type, event.schedule_kind, event.starts_on, event.ends_on, event.route_modes]).toEqual(['holiday_lights', 'nightly_range', '2026-12-01', '2027-01-03', ['walking']]);
  await openAt(page, DEC12);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await expect(page.locator('.intro h1')).toHaveText('Holiday nights. Bright lights.');
  await expect(page.locator('.schedule-row')).toContainText('Dec 1, 2026 – Jan 3, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Most homes: 6–10 PM · Times vary by house');
  await expect(page.locator('#seasonPill')).toHaveText('Tonight');
  await expect(page.locator('.event-pill')).toContainText('DEC 1 – JAN 3');
  await expect(page.locator('.side-panel b')).toHaveText('Putting up lights?');
  await expect(page.locator('#routeTitle')).toHaveText('✨ Your lights tour');
  expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe('rgb(247, 245, 239)'); // calm cream
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--brand').trim().toUpperCase())).toBe('#38597F');
  expect((await page.locator('body').innerText()).toLowerCase()).not.toMatch(/\bsafe for\b|\bseizure.safe\b/);
  for (const [when, word] of [[atLocal('2026-11-20', '19:00', TZ), 'Season preview'], [atLocal('2027-01-10', '19:00', TZ), 'Season over']]) {
    await page.clock.setSystemTime(when);
    await page.reload();
    await expect(page.locator('#seasonPill')).toHaveText(word);
  }
  guard.assertClean();
});

test('quick filters and the Filters panel: drive-by, no flashing reported, music, walk-up, open tonight, a night, a style', async ({ page }) => {
  const guard = watchConsole(page);
  await openAt(page, DEC12);
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  await expect(page.locator('#btnShowClosed')).toHaveText('Show 1 home with no nights left');
  const quick = async (name, n, expected) => {
    const b = page.getByRole('button', { name, exact: true });
    await expect(b.locator('.fcount')).toHaveText(String(n));
    expect((await b.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await b.click();
    await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
    if (expected) expect(await addresses(page)).toEqual(expected);
    await b.click();
  };
  await quick('Drive-by', 4, ['108 Evergreen Ct', '24 Tinsel Ln', '60 Evergreen Ct', '72 Tinsel Ln']);
  await quick('No flashing reported', 7);
  await quick('Music', 3, ['120 Tinsel Ln', '24 Tinsel Ln', '96 Holly Dr']);
  await page.locator('#btnFilters').click();
  const mini = async (name, n) => {
    const b = page.getByRole('button', { name, exact: true });
    expect((await b.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await b.click();
    await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
    await b.click();
  };
  await mini('Walk-up welcome', 5);
  await mini('Open tonight', 6);
  // One night of the season: New Year's Eve, then Jan 1, then the night 60 Evergreen Ct is off.
  for (const [night, n, without] of [['2026-12-31', 8, '72 Tinsel Ln'], ['2027-01-01', 7, '36 Starlight Way'], ['2026-12-12', 6, '60 Evergreen Ct']]) {
    await page.locator('#night').fill(night);
    await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
    expect(await addresses(page)).not.toContain(without);
  }
  await page.locator('#style').selectOption('inflatables');
  await page.locator('#night').fill('');
  await expect(page.locator('#listCount')).toHaveText('2 showing');
  expect(await addresses(page)).toEqual(['108 Evergreen Ct', '36 Starlight Way']);
  await expect(page.locator('#filterCount')).toHaveText('1');
  // Cards, pins and the selected-home panel agree.
  expect(await page.locator('.leaflet-marker-icon .pin:not(.dim):not(.inactive)').count(), 'the lit pins are the cards').toBe(2);
  await expect(page.locator('.leaflet-marker-icon[title="84 Starlight Way"] .pin')).toHaveClass(/inactive/); // done for the season: greyed
  const card = cards(page).first();
  await card.locator('.card-main').click();
  await expect(page.locator('#mapSelected')).toHaveClass(/open/);
  await expect(page.locator('#mapSelected b')).toHaveText(await card.locator('.card-line b').textContent());
  await expect(page.locator('#mapSelected .card-status')).toHaveText(await card.locator('.card-status').textContent());
  await expect(page.locator('#mapSelected [data-save]')).toHaveText('♡ Save this stop');
  await page.reload();
  await expect(page.locator('#style')).toHaveValue('inflatables');
  await page.locator('#btnReset').click();
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  await expect(page.locator('#style')).toHaveValue('any');
  guard.assertClean();
});

test('never "Lights on" outside a window; New Year\'s Eve into Jan 1; off tonight; done for the season', async ({ page }) => {
  const lit = async () => (await cards(page).filter({ has: page.locator('.card-status.lights_on') }).locator('.card-line b').allTextContents()).sort();
  await openAt(page, atLocal('2026-12-12', '16:00', TZ));
  expect(await lit(), 'before any window opens').toEqual([]);
  await expect(cards(page).filter({ hasText: '108 Evergreen Ct' }).locator('.card-status')).toHaveText('Later tonight');
  await page.clock.setSystemTime(atLocal('2026-12-12', '16:45', TZ));
  await page.reload();
  expect(await lit(), 'its window opened at 4:30 PM').toEqual(['108 Evergreen Ct']);
  await page.clock.setSystemTime(DEC12);
  await page.reload();
  expect(await lit()).toEqual(['108 Evergreen Ct', '12 Evergreen Ct', '24 Tinsel Ln', '36 Starlight Way', '48 Holly Dr', '72 Tinsel Ln']);
  const off = cards(page).filter({ hasText: '60 Evergreen Ct' });
  await expect(off.locator('.card-status')).toHaveText('Off tonight');
  await expect(off.locator('.card-hours')).toHaveText('Sun, Dec 13 · 5–11 PM');
  await expect(page.locator('.leaflet-marker-icon[title="60 Evergreen Ct"] .pin')).toHaveClass(/inactive/);
  await page.locator('#btnShowClosed').click();
  await expect(cards(page).filter({ hasText: '84 Starlight Way' }).locator('.card-status')).toHaveText('Done for the season');
  await page.clock.setSystemTime(atLocal('2026-12-13', '19:30', TZ));
  await page.reload();
  await expect(cards(page).filter({ hasText: '60 Evergreen Ct' }).locator('.card-status')).toHaveText('Lights on', { timeout: 5000 });
  await page.clock.setSystemTime(atLocal('2026-12-31', '22:15', TZ));
  await page.reload();
  expect(await lit(), '10:15 PM on New Year\'s Eve').toEqual(['60 Evergreen Ct', '96 Holly Dr']);
  await page.clock.setSystemTime(atLocal('2026-12-31', '23:05', TZ));
  await page.reload();
  expect(await lit(), 'after every window').toEqual([]);
  await page.clock.setSystemTime(atLocal('2027-01-01', '19:00', TZ));
  await page.reload();
  expect(await lit()).toEqual(['108 Evergreen Ct', '12 Evergreen Ct', '120 Tinsel Ln', '24 Tinsel Ln', '48 Holly Dr', '60 Evergreen Ct', '96 Holly Dr']);
  expect(await addresses(page), 'the home whose nights ended on Dec 31 has none left').not.toContain('36 Starlight Way');
});

test('saved stops belong to this event; without a provider the tour is an ordered list with no lines and no time', async ({ page, request }) => {
  const guard = watchConsole(page);
  const event = (await (await request.get(LIGHTS + '/api/event')).json()).event;
  await openAt(page, DEC12);
  for (const i of [0, 1, 3]) await cards(page).nth(i).locator('.save').click();
  await expect(page.locator('#savedCount')).toHaveText('3');
  await expect(page.locator('#routeItems .route-item')).toHaveCount(3);
  await expect(page.locator('#route .route-mode')).toHaveCount(0); // no drive-by tour without a provider
  await expect(page.locator('[data-provider-path]')).toHaveCount(0);
  await expect(page.locator('#routeItems')).not.toContainText(/\bkm\b|\bmin\b/);
  await expect(page.locator('#routeItems .route-item').first()).toContainText(/Drive-by|Walk-up/);
  await page.locator('.route-rules summary').click();
  await expect(page.locator('.route-rules')).toContainText('Do not knock on or walk up to “Drive-by” homes');
  await expect(page.locator('.leaflet-marker-icon .pin-number')).toHaveCount(3);
  expect(await page.evaluate(() => Object.keys(localStorage))).toContain(`nhm:maple-hollow-lights-demo:${event.id}`);
  expect(await page.locator('#btnShareRoute').getAttribute('data-url')).toContain(`e=${event.id}`);
  guard.assertClean();
});

for (const scheme of ['light', 'dark']) {
  for (const [name, path] of [['map', '/'], ['register sheet', '/#list-my-house'], ['route', '/#route'], ['about', '/about']]) {
    test(`lights: no WCAG 2.1 AA violations: ${name} (${scheme})`, async ({ browser }) => {
      const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
      const page = await ctx.newPage();
      await politeTiles(page);
      await page.clock.install({ time: DEC12 });
      await page.goto(LIGHTS + path);
      await page.waitForTimeout(800);
      const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
      const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
      expect(summary, summary.join('\n')).toEqual([]);
      await ctx.close();
    });
  }
}

for (const [w, h] of [[375, 812], [390, 844], [430, 932], [768, 1024], [956, 440], [1180, 820], [1440, 1000]]) {
  test(`lights: no horizontal overflow and 44px targets at ${w}px`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    await openAt(page, DEC12);
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    await page.locator('#btnFilters').click();
    const small = await page.evaluate(() => [...document.querySelectorAll('.controls button, .controls select, .filter-more button, .filter-more select, .filter-more input, .mobile-bottom a, .map-tools button, .home-card .save, .side-action')]
      .filter((e) => e.offsetParent && e.getBoundingClientRect().height < 44).map((e) => e.id || e.className || e.textContent.trim()));
    expect(small, `${w}px small targets`).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}

test('lights screenshots: desktop and phone, light and dark; reduced motion', async ({ browser }) => {
  for (const scheme of ['light', 'dark']) {
    for (const [w, h] of [[390, 844], [1440, 1000]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await politeTiles(page);
      await page.clock.install({ time: DEC12 });
      await page.goto(LIGHTS + '/');
      await page.waitForTimeout(1200);
      expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto');
      expect(await page.evaluate(() => [...document.querySelectorAll('*')].filter((e) => getComputedStyle(e).animationName !== 'none').length), 'nothing animates').toBe(0);
      await page.screenshot({ path: `${SHOTS}/lights-${scheme === 'dark' ? 'dark-' : ''}${w}.png` });
      await ctx.close();
    }
  }
});

test('the map failing (tiles or Leaflet blocked) still leaves a readable list', async ({ page }) => {
  await page.route(/tile\.openstreetmap\.org/, (r) => r.abort());
  await page.route('**/vendor/leaflet/leaflet.js', (r) => r.abort());
  await openAt(page, DEC12);
  await expect(page.locator('#mapFallback')).toBeVisible();
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  await expect(cards(page).first().locator('.tags')).not.toBeEmpty();
});

async function linkFor(request, email, since) {
  for (let i = 0; i < 40; i++) {
    const found = await (await request.get(`${LIGHTS_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json();
    const msg = (found.messages || []).find((m) => Date.parse(m.Created) >= since);
    const path = msg && (await (await request.get(`${LIGHTS_MAILPIT}/api/v1/message/${msg.ID}`)).json()).Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)?.[0];
    if (path) return path;
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`no link for ${email}`);
}

test('a resident lists a display: nights, styles, viewing mode, music and station, flashing, treats; My house: off tonight and done for the season', async ({ page, request }) => {
  const guard = watchConsole(page);
  const email = uniqueEmail('lights');
  const house = String(5000 + Math.floor(Math.random() * 4000));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(LIGHTS + '/#list-my-house');
  await expect(page.locator('#registerTitle')).toHaveText('List your light display');
  await page.getByRole('button', { name: /Can’t find your address/ }).click();
  await page.locator('#seHouse').fill(house);
  await page.locator('#seStreet').selectOption({ label: 'HOLLY DR' });
  await page.waitForTimeout(400);
  const box = await page.locator('#pinMap .leaflet-overlay-pane path').first().boundingBox();
  // Somewhere in the empty south-east of the outline, never on top of a demo home.
  await page.mouse.click(box.x + box.width * (0.62 + Math.random() * 0.2), box.y + box.height * (0.62 + Math.random() * 0.2));
  await page.getByRole('button', { name: 'Next: your stop →' }).click();
  const form = page.locator('#registerForm');
  await expect(form.locator('fieldset[data-step="2"]')).toBeVisible();
  await expect(form).toContainText('Drive-by only means please do not approach');
  await expect(form.locator('input[name="nights_mode"][value="all"]')).toBeChecked();
  await expect(form.locator('input[name="viewing_mode"][value="drive_by_only"]')).toBeChecked();
  await page.screenshot({ path: `${SHOTS}/lights-register-390.png` });
  for (const chip of await form.locator('fieldset[data-step="2"] .check-chip').all()) {
    if (await chip.isVisible()) expect((await chip.boundingBox()).height).toBeGreaterThanOrEqual(44);
  }
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await expect(form.locator('[data-error="styles"]')).toHaveText('Choose at least one display style.');
  await form.locator('label', { hasText: 'Classic' }).first().click();
  await form.locator('input[name="fm_frequency"]').fill('88.7');
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await expect(form.locator('[data-error="fm_frequency"]')).toHaveText('Turn on music to add its radio station, or leave the station empty.');
  await form.locator('label', { hasText: '🎵 Music' }).click();
  await form.locator('label', { hasText: 'Walk-up welcome' }).click();
  await form.locator('label', { hasText: 'Hot cocoa' }).click();
  await form.locator('label', { hasText: 'Only some nights' }).click();
  const grid = form.locator('[data-nights]');
  await expect(grid).toBeVisible();
  await expect(grid.locator('input[name="night"]')).toHaveCount(34);
  for (const night of await grid.locator('input[name="night"]').all()) {
    if (!['2026-12-24', '2026-12-31', '2027-01-01'].includes(await night.getAttribute('value'))) await night.uncheck();
  }
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await page.locator('#regEmail').fill(email);
  const since = Date.now() - 1000;
  await page.getByRole('button', { name: /Email me the confirmation link/ }).click();
  await expect(page.locator('#registerSuccess')).toContainText('Check your email', { timeout: 60_000 });
  await page.goto(LIGHTS + await linkFor(request, email, since));
  await page.locator('form[action="/auth/confirm"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/me$/);
  const address = new RegExp(`^${house} Holly Dr$`, 'i');
  const card = page.locator('.dash-home', { has: page.locator('h2', { hasText: address }) });
  await expect(card).toBeVisible();
  const mine = (await (await request.get(LIGHTS + '/api/homes')).json()).homes.find((h) => address.test(h.address));
  expect(mine.windows.map((x) => x.date)).toEqual(['2026-12-24', '2026-12-31', '2027-01-01']);
  expect(mine.details).toEqual({ styles: ['classic'], flashing_warning: false, music_enabled: true, fm_frequency: '88.7', viewing_mode: 'walk_up_welcome', treats: false, cocoa: true });
  // My house fills the nights it picked, then "Off tonight" and "Done for the season".
  await expect(card.locator('input[name="nights_mode"][value="pick"]')).toBeChecked();
  await expect(card.locator('input[name="night"]:checked')).toHaveCount(3);
  await card.getByRole('button', { name: '🌙 Off tonight' }).click();
  await expect(card.getByRole('button', { name: '🌙 Off tonight' })).toHaveAttribute('aria-pressed', 'true');
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());
  let api = (await (await request.get(`${LIGHTS}/api/homes/${mine.id}`)).json()).home;
  expect([api.status, api.status_date]).toEqual(['off_tonight', today]);
  await card.getByRole('button', { name: '✅ Done for the season' }).click();
  await expect(card.getByRole('button', { name: '✅ Done for the season' })).toHaveAttribute('aria-pressed', 'true');
  api = (await (await request.get(`${LIGHTS}/api/homes/${mine.id}`)).json()).home;
  expect([api.status, api.status_date, api.display_status]).toEqual(['finished', null, 'finished']);
  await page.screenshot({ path: `${SHOTS}/lights-me-390.png` });
  // Give the home back so the demo stays the same.
  page.once('dialog', (d) => d.accept('RELEASE'));
  await card.getByRole('button', { name: 'Give up this home' }).click();
  await expect(page.locator('.toast', { hasText: 'Home released.' })).toBeVisible();
  expect((await request.get(`${LIGHTS}/api/homes/${mine.id}`)).status()).toBe(404);
  guard.assertClean();
});
