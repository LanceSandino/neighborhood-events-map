// Several events at once on the fictional lights demo stack (examples/demo-lights, ADR 0011): Christmas lights open and
// the default, a Halloween that has ended with its thank-you message, next year's Halloween (upcoming, tentative) and a
// rummage sale whose dates are to be announced. Runs in the "season" project, after the other lights tests: visitors
// switch events with the switcher (mouse and keyboard), each tab has its own theme, branding (the demo's Halloween has
// its own, ADR 0012) and saved stops; a resident lists
// next year's Halloween with "Use last year's details"; an organizer closes an event (next year's is created) and
// changes the default. It gives everything back (archiving what it added); bin/e2e.sh's demo seed brings next year's
// Halloween back for the next run. Browser clocks are pinned to a night of the lights season.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { LIGHTS, LIGHTS_MAILPIT, SHOTS, atLocal, watchConsole, politeTiles } from './helpers.mjs';

const TZ = 'America/Chicago';
const NIGHT = atLocal('2026-12-19', '19:30', TZ);
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const ORGANIZER = process.env.E2E_ORGANIZER || 'e2e-organizer@example.test';
const RESIDENT = 'demo-resident-01@example.invalid'; // 12 Evergreen Ct: listed for the lights and for last Halloween

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const api = async (request, path) => (await request.get(LIGHTS + path)).json();
async function events(request) {
  const list = (await api(request, '/api/events')).events;
  const pick = (type, state) => list.find((e) => e.type === type && e.state === state);
  return { list, lights: pick('holiday_lights', 'open'), closed: pick('halloween', 'closed'), next: pick('halloween', 'upcoming'), sale: pick('garage_sale', 'upcoming') };
}

async function signIn(page, request, email, next) {
  const since = Date.now() - 1000;
  await page.goto(`${LIGHTS}/login?next=${encodeURIComponent(next)}`);
  await page.locator('#loginEmail').fill(email);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  let path = null;
  for (let i = 0; i < 40 && !path; i++) {
    const found = await (await request.get(`${LIGHTS_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json();
    const msg = (found.messages || []).find((m) => Date.parse(m.Created) >= since);
    if (msg) path = (await (await request.get(`${LIGHTS_MAILPIT}/api/v1/message/${msg.ID}`)).json()).Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)?.[0];
    if (!path) await new Promise((r) => setTimeout(r, 500));
  }
  expect(path, `a sign-in link for ${email}`).toBeTruthy();
  await page.goto(LIGHTS + path);
  await page.getByRole('button', { name: 'Confirm sign-in' }).click();
}

test('the switcher: the default event at "/", every public event by mouse and keyboard, its own theme, the closed message and the upcoming cards', async ({ page, request }) => {
  const guard = watchConsole(page);
  const e = await events(request);
  expect([e.lights.is_default, e.closed.closing_message, e.next.starts_on, e.next.dates_tentative, e.sale.starts_on]).toEqual(
    [true, 'Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.', '2027-10-31', true, null]);
  await page.clock.install({ time: NIGHT });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(LIGHTS + '/');
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  const tabs = page.getByRole('navigation', { name: 'Events' }).getByRole('link');
  await expect(tabs).toHaveText(['✨Christmas Lights', '🎃Halloween 2026Ended', '🎃Halloween 2027Soon', '🏷️Community Rummage SaleSoon']);
  await expect(tabs.first()).toHaveAttribute('aria-current', 'page');
  for (const t of await tabs.all()) expect((await t.boundingBox()).height, 'a 44 px target').toBeGreaterThanOrEqual(44);
  await expect(page.locator('#homeCards .home-card')).toHaveCount(9);
  await page.screenshot({ path: `${SHOTS}/events-switcher-1440.png` });

  // Keyboard: from the skip link to the switcher, then Enter opens the ended Halloween.
  await page.keyboard.press('Tab');
  for (let i = 0; i < 20 && !(await page.evaluate(() => document.activeElement?.closest('.event-switch') !== null)); i++) await page.keyboard.press('Tab');
  await expect(tabs.first()).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(tabs.nth(1)).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${LIGHTS}/?e=${e.closed.id}`);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'halloween');
  await expect(tabs.nth(1)).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('.event-status-message')).toHaveText(e.closed.closing_message);
  await expect(page.locator('#homeCards')).toHaveCount(0);
  await expect(page.locator('#map')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Get my house ready' })).toHaveAttribute('href', `/me?e=${e.next.id}`);
  await page.screenshot({ path: `${SHOTS}/events-closed-1440.png` });

  await tabs.nth(2).click();
  await expect(page).toHaveURL(`${LIGHTS}/?e=${e.next.id}`);
  await expect(page.locator('#eventStatusTitle')).toHaveText('Oct 31, 2027, tentative');
  await expect(page.locator('.event-status .primary')).toHaveText('+ List your home');
  await tabs.nth(3).click();
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'garage_sale');
  await expect(page.locator('#eventStatusTitle')).toHaveText('Dates to be announced');
  await expect(page.locator('.event-status')).toContainText('House listings open once the organizer sets them.');
  await tabs.first().click();
  await expect(page).toHaveURL(`${LIGHTS}/`);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  guard.assertClean();
});

// Words of Halloween (the owner's list) that must not show on another event's page (ADR 0012); the switcher's tab labels
// and a closed event's thank-you message name other events on purpose, and the residents' listings (cards, pins, the
// selected home, the route) are their own words — a lights home may offer treats or a "candy cane lane".
const HALLOWEEN = /ghost|trick[- ]?or[- ]?treat|\btreats?\b|candy|candies|scare|pumpkin|🎃|👻/giu;
const visibleWords = (page) => page.evaluate((src) => {
  const doc = document.documentElement.cloneNode(true);
  doc.querySelectorAll('script, style, .event-switch, .event-status-message, #homeCards, #map, #mapSelected, #routeItems').forEach((n) => n.remove());
  const attrs = [...doc.querySelectorAll('[title], [aria-label], [placeholder], [alt]')].map((n) => ['title', 'aria-label', 'placeholder', 'alt'].map((a) => n.getAttribute(a) || '').join(' '));
  const text = `${doc.textContent} ${attrs.join(' ')}`.replace(/🍬\s*Treats|Music, flashing and treats/g, ''); // the lights' own listing option
  return [...new Set(text.match(new RegExp(src, 'giu')) || [])];
}, HALLOWEEN.source);

test('each event has its own branding: the Halloween look stays on Halloween\'s tabs, the lights and the sale show their own and no Halloween word', async ({ page, request }) => {
  const e = await events(request);
  await page.clock.install({ time: NIGHT });
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    for (const halloween of [e.closed, e.next]) {
      await page.goto(`${LIGHTS}/?e=${halloween.id}`);
      await expect(page.locator('.intro h1')).toHaveText('👻 Hollow haunts. Big adventures.');
      await expect(page.locator('.brand-icon')).toHaveText('🎃');
      expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--brand').trim().toUpperCase())).toBe('#E0702B');
    }
    for (const [path, headline, color] of [['/', 'Holiday nights. Bright lights.', '#38597F'], [`/?e=${e.sale.id}`, 'Good finds. Close to home.', '#178D80']]) {
      await page.goto(LIGHTS + path);
      await expect(page.locator('.intro h1')).toHaveText(headline);
      expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--brand').trim().toUpperCase())).toBe(color);
      expect(await visibleWords(page), `${path} at ${width}px`).toEqual([]);
      await page.goto(LIGHTS + '/about' + path.slice(1));
      expect(await visibleWords(page), `About ${path} at ${width}px`).toEqual([]);
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${LIGHTS}/?e=${e.sale.id}`);
  await page.screenshot({ path: `${SHOTS}/events-sale-1440.png` });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${LIGHTS}/?e=${e.sale.id}`);
  await page.screenshot({ path: `${SHOTS}/events-sale-390.png` });
});

test('each event keeps its own saved stops; a shared link keeps its event', async ({ page, request }) => {
  const guard = watchConsole(page);
  const e = await events(request);
  await page.clock.install({ time: NIGHT });
  await page.goto(LIGHTS + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await page.locator('#homeCards .home-card').first().locator('[data-save]').click();
  const saved = page.getByRole('button', { name: 'Saved only' });
  await expect(saved.locator('.fcount')).toHaveText('1');
  await page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: /Halloween 2026/ }).click();
  await expect(page.locator('.event-status')).toBeVisible();
  await page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: /Christmas Lights/ }).click();
  await expect(saved.locator('.fcount')).toHaveText('1');
  const keys = await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('nhm:maple-hollow-lights-demo:')));
  expect(keys, 'one store, the lights event\'s').toEqual([`nhm:maple-hollow-lights-demo:${e.lights.id}`]);
  // A stop list shared on the lights names them; opened on another tab's page it names that event instead.
  const ids = (await api(request, '/api/homes')).homes.slice(0, 2).map((h) => h.id);
  await page.goto(`${LIGHTS}/?stops=${ids.join('.')}&e=${e.lights.id}`);
  await expect(page.locator('#routeItems .route-item')).toHaveCount(2);
  await page.goto(`${LIGHTS}/?stops=${ids.join('.')}&e=${e.closed.id}`);
  await expect(page.locator('.event-status')).toBeVisible();
  await expect(page.locator('#routeItems')).toHaveCount(0);
  await page.evaluate(() => localStorage.clear());
  guard.assertClean();
});

test('the switcher and the event pages: no overflow from 375 to 1440 px, axe in light and dark', async ({ page, request }) => {
  const e = await events(request);
  await page.clock.install({ time: NIGHT });
  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme });
    for (const [path, name] of [['/', 'lights'], [`/?e=${e.closed.id}`, 'closed'], [`/?e=${e.next.id}`, 'upcoming'], [`/?e=${e.sale.id}`, 'to be announced']]) {
      for (const width of [375, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(LIGHTS + path);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} at ${width}px (${scheme})`).toBe(0);
        const strip = await page.locator('.event-switch').boundingBox();
        expect(strip.x + strip.width, `${name} at ${width}px: the switcher stays inside the screen`).toBeLessThanOrEqual(width);
      }
      const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`), `${name} (${scheme})`).toEqual([]);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(LIGHTS + '/');
    await page.screenshot({ path: `${SHOTS}/events-switcher${scheme === 'dark' ? '-dark' : ''}-390.png` });
    await page.goto(LIGHTS + `/?e=${e.closed.id}`);
    await expect(page.locator('.event-switch [aria-current="page"]')).toBeInViewport();
    if (scheme === 'light') await page.screenshot({ path: `${SHOTS}/events-closed-390.png` });
    await page.setViewportSize({ width: 1440, height: 900 });
    if (scheme === 'dark') {
      await page.goto(LIGHTS + '/');
      await page.screenshot({ path: `${SHOTS}/events-switcher-dark-1440.png` });
    }
  }
});

test('a resident switches events in My house and lists next year\'s Halloween with "Use last year\'s details"', async ({ page, request }) => {
  const guard = watchConsole(page);
  const e = await events(request);
  await signIn(page, request, RESIDENT, '/me');
  await expect(page).toHaveURL(/\/me$/);
  const card = page.locator('.dash-home', { hasText: '12 Evergreen Ct' });
  await expect(card.locator('[data-f="meta"]')).toContainText('Shown on the map');
  await expect(card.locator('[data-f="events"]')).toContainText('Halloween 2026 listed (ended)');
  await expect(card.locator('[data-f="events"]')).toContainText('Halloween 2027 not listed');

  // The ended Halloween is kept as it was: read-only.
  await page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: /Halloween 2026/ }).click();
  await expect(page).toHaveURL(`${LIGHTS}/me?e=${e.closed.id}`);
  await expect(card.locator('[data-f="meta"]')).toContainText('Halloween has ended');
  await expect(card.locator('[data-f="form"]')).toBeHidden();
  await expect(card.locator('.status-row')).toBeHidden();

  // Next year's: an empty form, then the resident's own details of last time, checked and saved.
  await page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: /Halloween 2027/ }).click();
  await expect(page).toHaveURL(`${LIGHTS}/me?e=${e.next.id}`);
  await expect(card.locator('[data-f="meta"]')).toContainText('Not listed for Halloween');
  await card.getByRole('button', { name: 'List for Halloween' }).click();
  const form = card.locator('[data-f="form"]');
  await expect(form.locator('textarea[name="note"]')).toHaveValue('');
  for (const box of await form.locator('input[type="checkbox"]').all()) await expect(box).not.toBeChecked();
  await card.getByRole('button', { name: 'Use last year’s details' }).click();
  await expect(page.locator('.toast')).toContainText('Last year’s details are in the form');
  await expect(form.locator('textarea[name="note"]')).toHaveValue('Glow-stick path to the porch and a friendly scarecrow.');
  await expect(form.locator('select[name="start"]')).toHaveValue('17:00');
  await expect(form.locator('select[name="end"]')).toHaveValue('20:30');
  await expect(form.locator('input[name="candy"]')).toBeChecked();
  await expect(form.locator('input[name="stroller_friendly"]')).toBeChecked();
  expect((await api(request, `/api/homes?e=${e.next.id}`)).homes, 'nothing is saved by copying').toEqual([]);
  await card.getByRole('button', { name: 'List for Halloween' }).click();
  await expect(page.locator('.toast', { hasText: 'Listed for Halloween' })).toBeVisible();
  await expect(page.locator('.dash-home', { hasText: '12 Evergreen Ct' }).locator('[data-f="meta"]')).toContainText('Shown when the event opens');
  expect((await api(request, `/api/homes?e=${e.next.id}`)).homes, 'not public before the event opens').toEqual([]);
  guard.assertClean();
});

test('an organizer opens and closes an event (next year\'s is created) and changes the default', async ({ page, request }) => {
  const guard = watchConsole(page);
  const e = await events(request);
  await signIn(page, request, ORGANIZER, '/admin/events');
  await expect(page).toHaveURL(/\/admin\/events$/);
  const row = (id) => page.locator(`tr[data-event="${id}"]`);
  await expect(row(e.lights.id)).toContainText('Default');

  // The sale gets its dates and opens: a sale to be announced cannot open before.
  await row(e.sale.id).getByRole('button', { name: 'Edit' }).click();
  await page.locator('#evTbd').uncheck();
  await page.locator('#evStart').fill('2027-04-10');
  await page.locator('#evEnd').fill('2027-04-11');
  await page.getByRole('button', { name: 'Save event' }).click();
  await expect(page.locator('#flash')).toContainText('Event saved');
  page.once('dialog', (d) => d.accept());
  await row(e.sale.id).getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('#flash')).toContainText('is open');

  // Closing: the confirmation shows the thank-you text and next year's dates.
  let shown = '';
  page.once('dialog', (d) => { shown = d.message(); d.accept(); });
  await row(e.sale.id).getByRole('button', { name: 'Close' }).click();
  await expect(page.locator('#flash')).toContainText('is closed. Next year\'s event is ready.');
  expect(shown).toContain('Community Rummage Sale 2027 was a success — thank you! The next sale: dates to be announced.');
  expect(shown).toContain('“Community Rummage Sale”, dates to be announced');
  const after = await events(request);
  expect(after.sale.id, 'the next sale, created by closing').not.toBe(e.sale.id);
  const closedSale = after.list.find((x) => x.id === e.sale.id);
  expect([closedSale.state, after.sale.starts_on]).toEqual(['closed', null]);

  // The closed sale becomes the default: "/" shows its thank-you message; then the lights again.
  await row(e.sale.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Community Rummage Sale” is the default event now');
  const visitor = await (await page.context().browser().newContext()).newPage();
  await politeTiles(visitor);
  await visitor.goto(LIGHTS + '/');
  await expect(visitor.locator('html')).toHaveAttribute('data-event-type', 'garage_sale');
  await expect(visitor.locator('.event-status-message')).toContainText('was a success — thank you!');
  await row(e.lights.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Christmas Lights” is the default event now');
  await visitor.goto(LIGHTS + '/');
  await expect(visitor.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await visitor.context().close();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({ path: `${SHOTS}/admin-events-1440.png` });

  // Give back: the closed sale and next year's Halloween (with the resident's listing) are archived; the demo seed
  // brings next year's Halloween back.
  for (const ev of [e.sale, e.next]) {
    page.once('dialog', (d) => d.accept());
    await row(ev.id).getByRole('button', { name: 'Archive' }).click();
    await expect(row(ev.id)).toContainText('Archived');
  }
  const end = await events(request);
  expect(end.list.map((x) => [x.type, x.state, x.is_default])).toEqual([['holiday_lights', 'open', true], ['halloween', 'closed', false], ['garage_sale', 'upcoming', false]]);
  guard.assertClean();
});
