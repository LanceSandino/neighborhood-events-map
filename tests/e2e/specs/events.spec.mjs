// Several events at once on the fictional lights demo stack (examples/demo-lights, ADR 0011): Christmas lights open and
// the default, a Halloween that has ended with its thank-you message, next year's Halloween (upcoming, tentative) and a
// rummage sale whose dates are to be announced. Runs in the "season" project, after the other lights tests: visitors
// switch current events with the switcher (mouse and keyboard) and find the ended Halloween under Past events
// (D-20261104-02), each event has its own theme, branding (the demo's Halloween has its own, ADR 0012), access
// (D-20261104-05) and saved stops; a resident lists
// next year's Halloween with "Use last year's details"; an organizer closes an event (next year's is created), sees the
// console's groups and changes the default (never to a closed event). It gives everything back (archiving what it added); bin/e2e.sh's demo seed brings next year's
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

test('the switcher: the default event at "/", current events by mouse and keyboard, each with its own theme, the upcoming cards, and the ended event under Past events', async ({ page, request }) => {
  const guard = watchConsole(page);
  const e = await events(request);
  expect([e.lights.is_default, e.closed.closing_message, e.next.starts_on, e.next.dates_tentative, e.sale.starts_on]).toEqual(
    [true, 'Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.', '2027-10-31', true, null]);
  await page.clock.install({ time: NIGHT });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(LIGHTS + '/');
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  const tabs = page.getByRole('navigation', { name: 'Events' }).getByRole('link');
  await expect(tabs, 'only current events are tabs').toHaveText(['Christmas Lights', 'HalloweenSoon', 'Community Rummage SaleSoon']);
  await expect(tabs.first()).toHaveAttribute('aria-current', 'page');
  for (const t of await tabs.all()) expect((await t.boundingBox()).height, 'a 44 px target').toBeGreaterThanOrEqual(44);
  const past = page.locator('a.past-link');
  await expect(past).toHaveText('Past events');
  expect((await past.boundingBox()).height, 'a 44 px target').toBeGreaterThanOrEqual(44);
  await expect(page.locator('.site-footer a[href="/past-events"]')).toBeVisible();
  await expect(page.locator('#homeCards .home-card')).toHaveCount(9);
  await page.screenshot({ path: `${SHOTS}/events-switcher-1440.png` });

  // Keyboard: from the skip link to the switcher, Enter opens the next tab; then on to Past events and the ended Halloween.
  await page.keyboard.press('Tab');
  for (let i = 0; i < 20 && !(await page.evaluate(() => document.activeElement?.closest('.event-switch') !== null)); i++) await page.keyboard.press('Tab');
  await expect(tabs.first()).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(tabs.nth(1)).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${LIGHTS}/?e=${e.next.id}`);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'halloween');
  await expect(tabs.nth(1)).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('#eventStatusTitle')).toHaveText('Oct 31, 2027, tentative');
  await expect(page.locator('.event-status .primary')).toHaveText('+ List your home');
  await tabs.nth(1).focus();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await expect(past, 'the entry follows the tabs').toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${LIGHTS}/past-events`);
  await expect(page.getByRole('heading', { name: 'Past events', level: 1 })).toBeVisible();
  await expect(past).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('.event-switch [aria-current]'), 'no tab is this page').toHaveCount(0);
  const listed = page.locator('.past-list a');
  await expect(listed).toHaveCount(1);
  await expect(listed).toContainText('Halloween 2026');
  await expect(listed).toContainText('Oct 31, 2026');
  await expect(listed).toHaveAttribute('href', `/?e=${e.closed.id}`);
  await listed.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${LIGHTS}/?e=${e.closed.id}`);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'halloween');
  await expect(past).toHaveAttribute('aria-current', 'true');
  await expect(page.locator('.event-status-message')).toHaveText(e.closed.closing_message);
  await expect(page.locator('#homeCards')).toHaveCount(0);
  await expect(page.locator('#map')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Get my house ready' })).toHaveAttribute('href', `/me?e=${e.next.id}`);
  await page.screenshot({ path: `${SHOTS}/events-closed-1440.png` });

  // The way back from a thank-you page, then the sale's tab.
  await page.getByRole('link', { name: 'Back to Christmas Lights' }).click();
  await expect(page).toHaveURL(`${LIGHTS}/`);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await tabs.nth(2).click();
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
  const text = `${doc.textContent} ${attrs.join(' ')}`.replace(/Treats|Music, flashing and treats/g, ''); // the lights' own listing option
  return [...new Set(text.match(new RegExp(src, 'giu')) || [])];
}, HALLOWEEN.source);

test('each event has its own branding: the Halloween look stays on Halloween\'s tabs, the lights and the sale show their own and no Halloween word', async ({ page, request }) => {
  const e = await events(request);
  await page.clock.install({ time: NIGHT });
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    for (const halloween of [e.closed, e.next]) {
      await page.goto(`${LIGHTS}/?e=${halloween.id}`);
      await expect(page.locator('.intro h1')).toHaveText('Hollow haunts. Big adventures.');
      await expect(page.locator('.brand-icon svg use')).toHaveAttribute('href', /#brand-halloween$/);
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

// The organizer console is the community's (D-20261031-52): outside an event's own section (data-event-section: its
// row, summary or form in Events, its listing column, its audit rows) no page shows an event's words, emoji or colours —
// not the default event's (here the lights' midnight blue) and not the Halloween's own branding (its headline, orange).
const CONSOLE_FORBIDDEN = /ghost|trick[- ]?or[- ]?treat|\btreats?\b|candy|candies|scare|pumpkin|halloween|holiday nights|hollow haunts|🎃|👻|✨/giu;
test('the organizer console names the community and uses its own colours; event branding stays in each event\'s own section', async ({ page, request }) => {
  const guard = watchConsole(page);
  await signIn(page, request, ORGANIZER, '/admin');
  await expect(page).toHaveURL(/\/admin$/);
  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme });
    for (const [path, ready] of [['/admin', '.checklist'], ['/admin/community', '#settingsForm'], ['/admin/community/map', '#status'], ['/admin/addresses', '#rows tr'],
      ['/admin/disputes', '#list'], ['/admin/audit', 'table.data'], ['/admin/events', '.events-table']]) {
      await page.goto(LIGHTS + path);
      await expect(page.locator(ready).first()).toBeAttached();
      await expect(page.locator('.admin-top .brand-icon')).toHaveText('M');
      expect(await page.locator('link[href*="theme.css"]').count(), `${path}: no event's stylesheet`).toBe(0);
      const look = await page.evaluate(() => [getComputedStyle(document.body).getPropertyValue('--orange').trim().toUpperCase(), getComputedStyle(document.querySelector('.admin-top .brand-icon')).backgroundColor]);
      expect(look[0], `${path} (${scheme}): the console's own colour`).toBe(scheme === 'light' ? '#3B5674' : '#93B2D6');
      const words = await page.evaluate((src) => {
        const doc = document.documentElement.cloneNode(true);
        doc.querySelectorAll('script, style, [data-event-section]').forEach((n) => n.remove());
        const attrs = [...doc.querySelectorAll('[title], [aria-label], [placeholder], [alt]')].map((n) => ['title', 'aria-label', 'placeholder', 'alt'].map((a) => n.getAttribute(a) || '').join(' '));
        return [...new Set(`${doc.textContent} ${attrs.join(' ')}`.match(new RegExp(src, 'giu')) || [])];
      }, CONSOLE_FORBIDDEN.source);
      expect(words, `${path} (${scheme}) outside an event's own section`).toEqual([]);
    }
  }
  // Where an event is in focus it shows: its row in Events with its type's mark, its listing column in Addresses.
  await page.goto(LIGHTS + '/admin/events');
  await expect(page.locator('.events-table [data-event-section="halloween"]').first().locator('svg use').first()).toHaveAttribute('href', /#i-pumpkin$/);
  await page.goto(LIGHTS + '/admin/addresses');
  await expect(page.locator('#listingHead')).toHaveText('Christmas Lights listing');
  guard.assertClean();
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
  await page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: /Halloween/ }).click();
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

test('the switcher, Past events and the event pages: no overflow from 375 to 1440 px, axe in light and dark', async ({ page, request }) => {
  const e = await events(request);
  await page.clock.install({ time: NIGHT });
  for (const scheme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: scheme });
    for (const [path, name] of [['/', 'lights'], [`/?e=${e.closed.id}`, 'closed'], [`/?e=${e.next.id}`, 'upcoming'], [`/?e=${e.sale.id}`, 'to be announced'], ['/past-events', 'past events']]) {
      for (const width of [375, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(LIGHTS + path);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} at ${width}px (${scheme})`).toBe(0);
        const strip = await page.locator('.event-switch').boundingBox();
        expect(strip.x + strip.width, `${name} at ${width}px: the switcher stays inside the screen`).toBeLessThanOrEqual(width);
        await expect(page.locator('a.past-link'), `${name} at ${width}px: the entry stays on the screen`).toBeInViewport();
      }
      const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`), `${name} (${scheme})`).toEqual([]);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(LIGHTS + '/');
    await page.screenshot({ path: `${SHOTS}/events-switcher${scheme === 'dark' ? '-dark' : ''}-390.png` });
    await page.goto(LIGHTS + `/?e=${e.next.id}`);
    await expect(page.locator('.event-switch [aria-current="page"]')).toBeInViewport();
    await page.goto(LIGHTS + `/?e=${e.closed.id}`);
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

test('an organizer opens and closes an event (next year\'s is created), changes the default and gives each event its own access', async ({ page, browser, request }) => {
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

  // The console's groups say where each event stands, and that events close by themselves.
  await expect(page.locator('#eventsGuide')).toContainText('Events close by themselves after their last day.');
  const groups = () => page.locator('#admin-events h2').allTextContents();
  await expect(page.locator('[data-event-group="open"] h2')).toHaveText('On the site now');
  await expect(page.locator('[data-event-group="closed"] h2')).toHaveText('Past events');
  expect((await groups()).slice(0, 3), 'the guide, the form, then what is on the site').toEqual(['How events work', 'Prepare an event', 'On the site now']);
  await expect(page.locator('[data-event-group="hidden"]'), 'nothing hidden yet: no empty group').toHaveCount(0);
  await expect(page.locator('[data-event-group="open"]').locator(`tr[data-event="${e.lights.id}"]`)).toBeVisible();
  await expect(page.locator('[data-event-group="closed"]').locator(`tr[data-event="${e.closed.id}"]`)).toBeVisible();

  // Closing: the confirmation shows the thank-you text and next year's dates.
  let shown = '';
  page.once('dialog', (d) => { shown = d.message(); d.accept(); });
  await row(e.sale.id).getByRole('button', { name: 'Close' }).click();
  await expect(page.locator('#flash')).toContainText('is closed. Next year\'s event is ready.');
  expect(shown).toContain('Community Rummage Sale 2027 was a success — thank you! The next sale: dates to be announced.');
  expect(shown).toContain('created now, hidden: “Community Rummage Sale”, dates to be announced');
  expect(shown).toContain('Events also close by themselves after their last day.');
  expect(shown, 'it is not the default: "/" stays').not.toContain('“/” then shows');
  const after = await events(request);
  expect(after.sale, 'the next sale is hidden from visitors (0.17)').toBeUndefined();
  const nextSale = (await (await page.request.get(LIGHTS + '/api/admin/events')).json()).events.find((x) => x.type === 'garage_sale' && x.state === 'hidden');
  expect(nextSale.id, 'the next sale, created by closing').not.toBe(e.sale.id);
  await expect(row(nextSale.id)).toContainText('Hidden · residents can get ready');
  await expect(page.locator('[data-event-group="hidden"] h2')).toHaveText('Hidden, getting ready');
  const order = await groups();
  expect(order.indexOf('On the site now') < order.indexOf('Hidden, getting ready') && order.indexOf('Hidden, getting ready') < order.indexOf('Past events'), order.join(', ')).toBe(true);
  await expect(page.locator('[data-event-group="hidden"]').locator(`tr[data-event="${nextSale.id}"]`)).toBeVisible();
  await expect(page.locator('[data-event-group="closed"]').locator(`tr[data-event="${e.sale.id}"]`)).toBeVisible();
  const closedSale = after.list.find((x) => x.id === e.sale.id);
  expect([closedSale.state, nextSale.starts_on]).toEqual(['closed', null]);

  // A closed event cannot be chosen as the default (D-20261104-04): no button, and the server refuses.
  await expect(row(e.sale.id).getByRole('button', { name: 'Make default' })).toHaveCount(0);
  const refused = await page.evaluate(async (id) => {
    const r = await fetch(`/api/admin/events/${id}/default`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: '{}' });
    return [r.status, (await r.json()).error.code];
  }, e.sale.id);
  expect(refused).toEqual([409, 'not_current']);

  // An upcoming event becomes the default: "/" shows it; then the lights again.
  await row(e.next.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Halloween” is the default event now');
  const visitor = await (await page.context().browser().newContext()).newPage();
  await politeTiles(visitor);
  await visitor.goto(LIGHTS + '/');
  await expect(visitor.locator('html')).toHaveAttribute('data-event-type', 'halloween');
  await expect(visitor.locator('#eventStatusTitle')).toHaveText('Oct 31, 2027, tentative');
  await row(e.lights.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Christmas Lights” is the default event now');
  await visitor.goto(LIGHTS + '/');
  await expect(visitor.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await visitor.context().close();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({ path: `${SHOTS}/admin-events-1440.png` });

  // Give back: the closed sale and next year's Halloween (with the resident's listing) are archived, the next sale is
  // announced (the demo's sale to be announced again); the demo seed brings next year's Halloween back.
  for (const ev of [e.sale, e.next]) {
    page.once('dialog', (d) => d.accept());
    await row(ev.id).getByRole('button', { name: 'Archive' }).click();
    await expect(row(ev.id)).toContainText('Archived');
  }
  page.once('dialog', (d) => d.accept());
  await row(nextSale.id).getByRole('button', { name: 'Announce' }).click();
  await expect(row(nextSale.id)).toContainText('Upcoming');

  // Each event's own access (the owner's case: the rummage sale is open to the public, the lights are not), in the type's
  // words with "Same as the community" as the empty choice; given back afterwards.
  const setAccess = async (id, value) => {
    await row(id).getByRole('button', { name: 'Edit' }).click();
    await page.locator('#evAccess').selectOption(value);
    await page.getByRole('button', { name: 'Save event' }).click();
    await expect(page.locator('#eventForm'), 'saved, and the list reloaded').toHaveCount(0);
  };
  await row(nextSale.id).getByRole('button', { name: 'Edit' }).click();
  await expect(page.locator('#evAccess option')).toHaveText(['Same as the community', 'Open to the public', 'Gated — gates open to shoppers during sale hours', 'Gated — residents and guests', 'Residents only', 'Not stated']);
  await expect(page.locator('#evAccess')).toHaveValue('');
  await page.getByRole('button', { name: 'Cancel' }).click();
  await setAccess(nextSale.id, 'public');
  await setAccess(e.lights.id, 'gated_guests');
  await expect(row(e.lights.id)).toContainText('Gated — residents and guests');
  const guest = await (await browser.newContext()).newPage();
  await politeTiles(guest);
  for (const [query, title, access] of [['', 'Gated — residents and guests', 'gated_guests'], [`?e=${nextSale.id}`, 'Open to the public', 'public']]) {
    await guest.goto(`${LIGHTS}/${query}`);
    await guest.locator('.sidebar a[data-action="info"]').click();
    await expect(guest.locator('#infoModal .guide-facts')).toContainText(title);
    await guest.goto(`${LIGHTS}/about${query}`);
    await expect(guest.locator('.about-facts')).toContainText(title);
    expect((await (await request.get(`${LIGHTS}/api/event${query}`)).json()).community.access).toBe(access);
  }
  await guest.context().close();
  await setAccess(nextSale.id, '');
  await setAccess(e.lights.id, '');
  expect((await (await request.get(`${LIGHTS}/api/event`)).json()).community.access, 'the demo community\'s own').toBe('public');

  const end = await events(request);
  expect(end.list.map((x) => [x.type, x.state, x.is_default])).toEqual([['holiday_lights', 'open', true], ['halloween', 'closed', false], ['garage_sale', 'upcoming', false]]);
  guard.assertClean();
});
