// The garage sale event type on the fictional sale demo stack (examples/demo-sale: 10 invented homes for Sat–Sun,
// April 10–11, 2027). The browser clock is pinned to moments of the sale (page.clock), so statuses never depend on
// the day the suite runs; the server answers with its own clock. Tests that add or change a home give it back, so
// the demo stays the same for the next run.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { SALE, SALE_MAILPIT, SHOTS, atLocal, watchConsole, politeTiles, uniqueEmail } from './helpers.mjs';

const TZ = 'America/Chicago';
const SAT = atLocal('2027-04-10', '09:30', TZ); // 52 Cedar Way is sold out today; 85 Cedar Way opens at 10
const SUN = atLocal('2027-04-11', '10:00', TZ); // 52 Cedar Way is back; 66 Birch Ln is paused today
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.beforeEach(async ({ page }) => { await politeTiles(page); });

/** Opens the sale map at a moment of the sale, with a clean device. */
async function openAt(page, when, path = '/') {
  await page.clock.install({ time: when });
  await page.goto(SALE + path);
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#listCount')).not.toHaveText('');
}
const cards = (page) => page.locator('#homeCards .home-card');
const card = (page, address) => cards(page).filter({ has: page.locator('.card-line b', { hasText: address }) });
const addresses = async (page) => (await cards(page).locator('.card-line b').allTextContents()).sort();

test('the sale theme, hero, sale row and words', async ({ page, request }) => {
  const guard = watchConsole(page);
  const event = (await (await request.get(SALE + '/api/event')).json()).event;
  expect([event.type, event.name, event.schedule_kind, event.days, event.early_bird_time, event.route_modes])
    .toEqual(['garage_sale', 'Community Rummage Sale', 'sale_days', ['2027-04-10', '2027-04-11'], '07:30', ['walking']]);
  await openAt(page, SAT);
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'garage_sale');
  await expect(page.locator('.intro h1')).toHaveText('Good finds. Close to home.');
  await expect(page.locator('.schedule-row')).toContainText('Sat–Sun, April 10–11, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Most homes: 8 AM–2 PM · Individual hours vary · Early birds: not before 7:30 AM');
  await expect(page.locator('#seasonPill')).toHaveText('Today');
  await expect(page.locator('.event-pill')).toHaveText('🏷️ Apr 10–11');
  await expect(page.locator('.side-panel b')).toHaveText('Having a sale?');
  await expect(page.locator('#routeTitle')).toHaveText('🏷️ Your sale stops');
  await expect(page.locator('.list-head h2')).toHaveText('🏷️ Sales around you');
  expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe('rgb(246, 245, 240)'); // off-white
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--brand').trim().toUpperCase())).toBe('#178D80');
  expect((await page.locator('body').innerText()).toLowerCase()).not.toMatch(/\$\d|add to cart|checkout|buy now/);
  for (const [when, word] of [[atLocal('2027-03-20', '10:00', TZ), 'Coming this spring'], [atLocal('2027-04-12', '10:00', TZ), 'Sale over']]) {
    await page.clock.setSystemTime(when);
    await page.reload();
    await expect(page.locator('#seasonPill')).toHaveText(word);
  }
  guard.assertClean();
});

test('quick filters and the Filters panel: categories, open now, each sale day, multi-family, early birds, search', async ({ page }) => {
  const guard = watchConsole(page);
  await openAt(page, SAT);
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  await expect(page.locator('#btnShowClosed')).toHaveText('Show 1 home with no sale hours left');
  const quick = async (name, n, expected) => {
    const b = page.getByRole('button', { name, exact: true });
    await expect(b.locator('.fcount')).toHaveText(String(n));
    expect((await b.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await b.click();
    await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
    if (expected) expect(await addresses(page)).toEqual(expected);
    await b.click();
  };
  await quick('Kids & toys', 3, ['14 Juniper Ct', '85 Cedar Way', '93 Birch Ln']);
  await quick('Furniture', 2, ['27 Cedar Way', '66 Birch Ln']);
  await quick('Tools', 3, ['104 Cedar Way', '33 Juniper Ct', '66 Birch Ln']);
  await page.locator('#btnFilters').click();
  const mini = async (name, n, expected) => {
    const b = page.getByRole('button', { name, exact: true });
    expect((await b.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await b.click();
    await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
    if (expected) expect(await addresses(page)).toEqual(expected);
    await b.click();
  };
  await mini('Household', 3, ['104 Cedar Way', '27 Cedar Way', '52 Cedar Way']);
  await mini('Electronics', 2, ['33 Juniper Ct', '85 Cedar Way']);
  await mini('Free items', 2, ['41 Birch Ln', '93 Birch Ln']);
  await mini('Open now', 5, ['14 Juniper Ct', '27 Cedar Way', '33 Juniper Ct', '66 Birch Ln', '93 Birch Ln']);
  await mini('Open Saturday', 6, ['14 Juniper Ct', '27 Cedar Way', '33 Juniper Ct', '66 Birch Ln', '85 Cedar Way', '93 Birch Ln']);
  await mini('Open Sunday', 6, ['104 Cedar Way', '14 Juniper Ct', '33 Juniper Ct', '41 Birch Ln', '52 Cedar Way', '93 Birch Ln']);
  await mini('Multi-family', 3, ['14 Juniper Ct', '66 Birch Ln', '85 Cedar Way']);
  await mini('Early birds welcome', 2, ['33 Juniper Ct', '93 Birch Ln']);
  // Search over streets, categories and notes.
  for (const [q, expected] of [['stroller', ['93 Birch Ln']], ['books', ['41 Birch Ln']], ['lego', ['85 Cedar Way']], ['free', ['41 Birch Ln', '93 Birch Ln']], ['birch kids', ['93 Birch Ln']]]) {
    await page.locator('#search').fill(q);
    await expect(page.locator('#listCount')).toHaveText(`${expected.length} showing`);
    expect(await addresses(page)).toEqual(expected);
  }
  await page.locator('#search').fill('');
  // Cards, pins and the selected-home panel agree.
  await page.getByRole('button', { name: 'Open Saturday', exact: true }).click();
  await expect(page.locator('#filterCount')).toHaveText('1');
  expect(await page.locator('.leaflet-marker-icon .pin:not(.dim)').count(), 'the matching pins are the cards').toBe(6);
  await expect(page.locator('.leaflet-marker-icon[title="52 Cedar Way"] .pin')).toHaveClass(/inactive/); // sold out today: greyed
  await expect(page.locator('.leaflet-marker-icon[title="78 Juniper Ct"] .pin')).toHaveClass(/inactive/); // closed
  const first = cards(page).first();
  await first.locator('.card-main').click();
  await expect(page.locator('#mapSelected')).toHaveClass(/open/);
  await expect(page.locator('#mapSelected b')).toHaveText(await first.locator('.card-line b').textContent());
  await expect(page.locator('#mapSelected [data-save]')).toHaveText('♡ Save this stop');
  await page.reload();
  await expect(page.getByRole('button', { name: 'Open Saturday', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await page.locator('#btnReset').click();
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  guard.assertClean();
});

test('open now vs opens at; sold out today vs tomorrow; paused for one day; closed; never "Open now" outside a home\'s hours', async ({ page }) => {
  const open = async () => (await cards(page).filter({ has: page.locator('.card-status.open') }).locator('.card-line b').allTextContents()).sort();
  await openAt(page, atLocal('2027-04-10', '06:30', TZ));
  expect(await open(), 'before any home opens').toEqual([]);
  await expect(card(page, '33 Juniper Ct').locator('.card-status')).toHaveText('Opens 7 AM');
  await page.clock.setSystemTime(SAT);
  await page.reload();
  expect(await open()).toEqual(['14 Juniper Ct', '27 Cedar Way', '33 Juniper Ct', '66 Birch Ln', '93 Birch Ln']);
  await expect(card(page, '85 Cedar Way').locator('.card-status')).toHaveText('Opens 10 AM');
  await expect(card(page, '52 Cedar Way').locator('.card-status')).toHaveText('Sold out today');
  await expect(card(page, '41 Birch Ln').locator('.card-status')).toHaveText('Opens Sun 9 AM');
  await expect(card(page, '33 Juniper Ct').locator('.card-hours')).toHaveText('Sat 7 AM–12 PM · Sun 9 AM–1 PM');
  await expect(card(page, '14 Juniper Ct').locator('.card-hours')).toHaveText('Sat–Sun 8 AM–2 PM');
  await page.locator('#btnShowClosed').click();
  await expect(card(page, '78 Juniper Ct').locator('.card-status')).toHaveText('Closed');
  await page.clock.setSystemTime(atLocal('2027-04-10', '10:05', TZ));
  await page.reload();
  await expect(card(page, '85 Cedar Way').locator('.card-status')).toHaveText('Open now', { timeout: 5000 });
  await page.clock.setSystemTime(SUN);
  await page.reload();
  await expect(page.locator('#listCount')).toHaveText('6 showing');
  await expect(card(page, '52 Cedar Way').locator('.card-status')).toHaveText('Open now');
  await expect(page.locator('.leaflet-marker-icon[title="52 Cedar Way"] .pin')).not.toHaveClass(/inactive/);
  await page.locator('#btnShowClosed').click();
  await expect(card(page, '66 Birch Ln').locator('.card-status')).toHaveText('Paused today');
  await page.clock.setSystemTime(atLocal('2027-04-11', '14:05', TZ));
  await page.reload();
  expect(await open(), 'after every home\'s Sunday hours').toEqual([]);
});

test('saved stops belong to this event; without a provider the route is an ordered list with no lines and no time', async ({ page, request }) => {
  const guard = watchConsole(page);
  const event = (await (await request.get(SALE + '/api/event')).json()).event;
  await openAt(page, SAT);
  for (const i of [0, 1, 3]) await cards(page).nth(i).locator('.save').click();
  await expect(page.locator('#savedCount')).toHaveText('3');
  await expect(page.locator('#routeItems .route-item')).toHaveCount(3);
  await expect(page.locator('#route .route-mode')).toHaveCount(0); // no driving tour without a provider
  await expect(page.locator('[data-provider-path]')).toHaveCount(0);
  await expect(page.locator('#routeItems')).not.toContainText(/\bkm\b|\bmin\b/);
  await page.locator('.route-rules summary').click();
  await expect(page.locator('.route-rules')).toContainText('Park on one side of the street so traffic can pass.');
  await expect(page.locator('.route-rules')).toContainText('Early birds: not before 7:30 AM');
  await expect(page.locator('.route-rules')).toContainText('No recalled, unsafe or prohibited items');
  await expect(page.locator('.leaflet-marker-icon .pin-number')).toHaveCount(3);
  expect(await page.evaluate(() => Object.keys(localStorage))).toContain(`nhm:maple-hollow-sale-demo:${event.id}`);
  expect(await page.locator('#btnShareRoute').getAttribute('data-url')).toContain(`e=${event.id}`);
  await page.goto(SALE + '/about');
  await expect(page.locator('main')).toContainText('This map sells nothing: prices and payment stay between you and the seller.');
  await expect(page.locator('main')).toContainText('🐦 Early birds: not before 7:30 AM');
  guard.assertClean();
});

for (const scheme of ['light', 'dark']) {
  for (const [name, path] of [['map', '/'], ['register sheet', '/#list-my-house'], ['route', '/#route'], ['about', '/about']]) {
    test(`sale: no WCAG 2.1 AA violations: ${name} (${scheme})`, async ({ browser }) => {
      const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
      const page = await ctx.newPage();
      await politeTiles(page);
      await page.clock.install({ time: SAT });
      await page.goto(SALE + path);
      await page.waitForTimeout(800);
      if (path === '/') await page.locator('#btnFilters').click();
      const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
      const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
      expect(summary, summary.join('\n')).toEqual([]);
      await ctx.close();
    });
  }
}

for (const [w, h] of [[375, 812], [390, 844], [430, 932], [768, 1024], [956, 440], [1180, 820], [1440, 1000]]) {
  test(`sale: no horizontal overflow and 44px targets at ${w}px`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    await openAt(page, SAT);
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    await page.locator('#btnFilters').click();
    const small = await page.evaluate(() => [...document.querySelectorAll('.controls button, .controls select, .filter-more button, .filter-more select, .filter-more input, .mobile-bottom a, .map-tools button, .home-card .save, .side-action')]
      .filter((e) => e.offsetParent && e.getBoundingClientRect().height < 44).map((e) => e.id || e.className || e.textContent.trim()));
    expect(small, `${w}px small targets`).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  });
}

test('sale screenshots: desktop and phone, light and dark; reduced motion', async ({ browser }) => {
  for (const scheme of ['light', 'dark']) {
    for (const [w, h] of [[390, 844], [1440, 1000]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await politeTiles(page);
      await page.clock.install({ time: SAT });
      await page.goto(SALE + '/');
      await page.waitForTimeout(1200);
      expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto');
      expect(await page.evaluate(() => [...document.querySelectorAll('*')].filter((e) => getComputedStyle(e).animationName !== 'none').length), 'nothing animates').toBe(0);
      await page.screenshot({ path: `${SHOTS}/sale-${scheme === 'dark' ? 'dark-' : ''}${w}.png` });
      await ctx.close();
    }
  }
});

test('the map failing (tiles or Leaflet blocked) still leaves a readable list', async ({ page }) => {
  await page.route(/tile\.openstreetmap\.org/, (r) => r.abort());
  await page.route('**/vendor/leaflet/leaflet.js', (r) => r.abort());
  await openAt(page, SAT);
  await expect(page.locator('#mapFallback')).toBeVisible();
  await expect(page.locator('#listCount')).toHaveText('9 showing');
  await expect(cards(page).first().locator('.tags')).not.toBeEmpty();
});

async function linkFor(request, email, since) {
  for (let i = 0; i < 40; i++) {
    const found = await (await request.get(`${SALE_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json();
    const msg = (found.messages || []).find((m) => Date.parse(m.Created) >= since);
    const path = msg && (await (await request.get(`${SALE_MAILPIT}/api/v1/message/${msg.ID}`)).json()).Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)?.[0];
    if (path) return path;
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`no link for ${email}`);
}

test('a resident lists a sale: days with their own hours, categories, payment kinds; a note with contact details is refused and nothing is stored; My house: paused, sold out, closed', async ({ page, request }) => {
  const guard = watchConsole(page);
  const email = uniqueEmail('sale');
  const house = String(5000 + Math.floor(Math.random() * 4000));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(SALE + '/#list-my-house');
  await expect(page.locator('#registerTitle')).toHaveText('List your sale');
  await page.getByRole('button', { name: /Can’t find your address/ }).click();
  await page.locator('#seHouse').fill(house);
  await page.locator('#seStreet').selectOption({ label: 'BIRCH LN' });
  await page.waitForTimeout(400);
  const box = await page.locator('#pinMap .leaflet-overlay-pane path').first().boundingBox();
  // Somewhere in the empty south-east of the outline, never on top of a demo home.
  await page.mouse.click(box.x + box.width * (0.62 + Math.random() * 0.2), box.y + box.height * (0.62 + Math.random() * 0.2));
  await page.getByRole('button', { name: 'Next: your stop →' }).click();
  const form = page.locator('#registerForm');
  await expect(form.locator('fieldset[data-step="2"]')).toBeVisible();
  await expect(form.locator('#regStart')).toHaveCount(0); // each sale day has its own hours
  await expect(form.locator('input[name="sale_day"]')).toHaveCount(2);
  await expect(form).toContainText('The kinds only — never a username or phone number.');
  await page.screenshot({ path: `${SHOTS}/sale-register-390.png` });
  for (const chip of await form.locator('fieldset[data-step="2"] .check-chip').all()) {
    if (await chip.isVisible()) expect((await chip.boundingBox()).height).toBeGreaterThanOrEqual(44);
  }
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await expect(form.locator('[data-error="categories"]')).toHaveText('Choose at least one category.');
  await form.locator('label', { hasText: 'Toys' }).click();
  await form.locator('label', { hasText: 'Furniture' }).click();
  await form.locator('select[name="day_start"][data-day="2027-04-10"]').selectOption('07:00');
  await form.locator('select[name="day_end"][data-day="2027-04-10"]').selectOption('12:00');
  await form.locator('select[name="day_start"][data-day="2027-04-11"]').selectOption('09:00');
  await form.locator('select[name="day_end"][data-day="2027-04-11"]').selectOption('13:00');
  await form.locator('label', { hasText: 'Multi-family sale' }).click();
  await form.locator('label', { hasText: '💵 Cash' }).click();
  await form.locator('#regNote').fill('Toys and a sofa. Questions? Call 555-123-4567');
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await page.locator('#regEmail').fill(email);
  const since = Date.now() - 1000;
  await page.getByRole('button', { name: /Email me the confirmation link/ }).click();
  // The server refuses the note: the form goes back to it and says why; nothing was sent or stored.
  await expect(form.locator('[data-error="note"]')).toHaveText('Please remove the phone number: notes are public and never carry contact details.', { timeout: 60_000 });
  await expect(form.locator('fieldset[data-step="2"]')).toBeVisible();
  await page.waitForTimeout(1500);
  const mails = (await (await request.get(`${SALE_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json()).messages || [];
  expect(mails, 'no link for a refused note').toEqual([]);
  const address = new RegExp(`^${house} Birch Ln$`, 'i');
  expect((await (await request.get(SALE + '/api/homes')).json()).homes.some((h) => address.test(h.address)), 'nothing stored').toBe(false);
  await form.locator('#regNote').fill('Toys and a sofa; everything must go.');
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await page.getByRole('button', { name: /Email me the confirmation link/ }).click();
  await expect(page.locator('#registerSuccess')).toContainText('Check your email', { timeout: 60_000 });
  await page.goto(SALE + await linkFor(request, email, since));
  await page.locator('form[action="/auth/confirm"] button[type="submit"]').click();
  await expect(page).toHaveURL(/\/me$/);
  const home = page.locator('.dash-home', { has: page.locator('h2', { hasText: address }) });
  await expect(home).toBeVisible();
  const mine = (await (await request.get(SALE + '/api/homes')).json()).homes.find((h) => address.test(h.address));
  expect(mine.windows).toEqual([{ date: '2027-04-10', start: '07:00', end: '12:00' }, { date: '2027-04-11', start: '09:00', end: '13:00' }]);
  expect(mine.details).toEqual({ categories: ['toys', 'furniture'], multi_family: true, early_birds_allowed: false, payment_types: ['cash'] });
  expect(mine.note).toBe('Toys and a sofa; everything must go.');
  // My house fills the days and hours, refuses a contact note there too, then pause, sold out and closed.
  await expect(home.locator('select[name="day_start"][data-day="2027-04-11"]')).toHaveValue('09:00');
  await home.locator('textarea[name="note"]').fill('Venmo @thrifty_jane');
  await home.getByRole('button', { name: 'Save changes' }).click();
  await expect(home.locator('[data-f="error"]')).toContainText('Please remove the payment username or handle');
  expect((await (await request.get(`${SALE}/api/homes/${mine.id}`)).json()).home.note, 'the refused edit is not stored').toBe('Toys and a sofa; everything must go.');
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());
  for (const [label, status] of [['⏸️ Paused today', 'paused'], ['📦 Sold out today', 'sold_out']]) {
    await page.locator('.dash-home', { has: page.locator('h2', { hasText: address }) }).getByRole('button', { name: label }).click();
    await expect(page.locator('.dash-home', { has: page.locator('h2', { hasText: address }) }).getByRole('button', { name: label })).toHaveAttribute('aria-pressed', 'true');
    const api = (await (await request.get(`${SALE}/api/homes/${mine.id}`)).json()).home;
    expect([api.status, api.status_date]).toEqual([status, today]);
  }
  const mineNow = page.locator('.dash-home', { has: page.locator('h2', { hasText: address }) });
  await mineNow.getByRole('button', { name: '🔒 Closed' }).click();
  await expect(mineNow.getByRole('button', { name: '🔒 Closed' })).toHaveAttribute('aria-pressed', 'true');
  const closed = (await (await request.get(`${SALE}/api/homes/${mine.id}`)).json()).home;
  expect([closed.status, closed.status_date, closed.display_status]).toEqual(['closed', null, 'closed']);
  await page.screenshot({ path: `${SHOTS}/sale-me-390.png` });
  // Give the home back so the demo stays the same.
  page.once('dialog', (d) => d.accept('RELEASE'));
  await mineNow.getByRole('button', { name: 'Give up this home' }).click();
  await expect(page.locator('.toast', { hasText: 'Home released.' })).toBeVisible();
  expect((await request.get(`${SALE}/api/homes/${mine.id}`)).status()).toBe(404);
  guard.assertClean();
});
