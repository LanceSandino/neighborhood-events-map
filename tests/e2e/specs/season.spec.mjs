// Seasons on the fictional demo stack (runs last: project "season"; bin/e2e.sh lists the fictional homes again for
// the default event afterwards; ADR 0011). An organizer closes Halloween: its homes leave the map at once, visitors see
// the thank-you message (a stop list shared for it opens without its stops), next year's Halloween is created as
// upcoming, and a resident lists their home for it in My house from an empty form (or from their own details of last
// time). Next year's opens and becomes the default. Christmas lights and a rummage sale are prepared, previewed and
// opened next to it — each with its own look, not the demo's Halloween branding —: the same resident lists the same home
// for each, independent listings, and an ended season's listing is never public again. The demo then gets a Halloween
// event on October 31 back, as it started, with its branding entered in the event's Branding section.
import { test, expect } from '@playwright/test';
import { DEMO, watchConsole, politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const ORGANIZER = process.env.E2E_ORGANIZER || 'e2e-organizer@example.test';
const RESIDENT = 'demo-resident-01@example.invalid'; // the fictional demo's first home (examples/demo/homes.json)
// The demo's own Mailpit, under a name no other spec changes (admin.spec points E2E_MAILPIT_URL at Ventura's in its worker).
const DEMO_MAILPIT = process.env.E2E_DEMO_MAILPIT_URL || 'http://host.docker.internal:8026';

async function signIn(page, request, email, next) {
  const since = Date.now() - 1000;
  await page.goto(`${DEMO}/login?next=${encodeURIComponent(next)}`);
  await page.locator('#loginEmail').fill(email);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  let path = null;
  for (let i = 0; i < 40 && !path; i++) {
    const found = await (await request.get(`${DEMO_MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json();
    const msg = (found.messages || []).find((m) => Date.parse(m.Created) >= since);
    if (msg) path = (await (await request.get(`${DEMO_MAILPIT}/api/v1/message/${msg.ID}`)).json()).Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)?.[0];
    if (!path) await new Promise((r) => setTimeout(r, 500));
  }
  expect(path, `a sign-in link for ${email}`).toBeTruthy();
  await page.goto(DEMO + path);
  await page.getByRole('button', { name: 'Confirm sign-in' }).click();
}

test('an organizer closes the season; last season disappears, next year is created and residents list again from an empty form', async ({ page, browser, request }) => {
  const guard = watchConsole(page);
  const before = (await (await request.get(DEMO + '/api/event')).json()).event;
  expect([before.state, before.is_default]).toEqual(['open', true]);
  const homes = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(homes.length).toBeGreaterThanOrEqual(14);
  const mine = homes.find((h) => h.address === '100 Pumpkin Patch Ln');
  expect(mine).toBeTruthy();
  const name = before.name;

  // A stop list shared during the current event names it.
  const open = homes.filter((h) => h.status === 'active');
  const shared = `${DEMO}/?stops=${open[0].id}.${open[1].id}&e=${before.id}`;
  const visitor = await (await browser.newContext()).newPage();
  await politeTiles(visitor);
  await visitor.goto(shared);
  await expect(visitor.locator('#routeItems .route-item')).toHaveCount(2);
  expect(await visitor.locator('#btnShareRoute').getAttribute('data-url')).toContain(`e=${before.id}`);
  await expect(visitor.locator('.event-switch'), 'one event: no switcher (Halloween looks as it always did)').toHaveCount(0);

  await signIn(page, request, ORGANIZER, '/admin/events');
  await expect(page).toHaveURL(/\/admin\/events$/);
  await expect(page.getByRole('heading', { name: 'Events', level: 1 })).toBeVisible();
  const row = (id) => page.locator(`tr[data-event="${id}"]`);
  await expect(row(before.id)).toContainText('Open — on the map');
  // Close: the confirmation names the thank-you text and next year's tentative date. The primary button is readable.
  const close = row(before.id).getByRole('button', { name: 'Close' });
  const [fg, bg] = await close.evaluate((b) => [getComputedStyle(b).color, getComputedStyle(b).backgroundColor]);
  expect(fg, 'text and background differ').not.toBe(bg);
  expect(bg, 'the primary colour, not the plain surface').not.toBe('rgb(255, 255, 255)');
  const year = Number(before.date.slice(0, 4));
  let shown = '';
  page.once('dialog', (d) => { shown = d.message(); d.accept(); });
  await close.click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is closed. Next year's event is ready.`);
  expect(shown).toContain(`${name} ${year} was a success — thank you! Come back next year (Oct 31, ${year + 1}, tentative).`);
  const listed = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events;
  const next = listed.find((e) => e.type === 'halloween' && e.state === 'upcoming');
  expect([next.name, next.starts_on, next.dates_tentative]).toEqual([name, `${year + 1}-10-31`, true]);
  await expect(row(before.id)).toContainText('Closed');

  // Last season's homes are gone at once; visitors see the thank-you message; nothing was carried over.
  const after = (await (await request.get(DEMO + '/api/event')).json()).event;
  expect([after.id, after.state, after.closing_message]).toEqual([before.id, 'closed', shown.split('\n\n')[1]]);
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes).toEqual([]);
  expect((await request.get(`${DEMO}/api/homes/${mine.id}`)).status()).toBe(404);
  await visitor.goto(DEMO + '/');
  await expect(visitor.locator('.event-status-message')).toHaveText(after.closing_message);
  await expect(visitor.locator('.event-switch a')).toHaveCount(2);
  // The stop list shared for the ended event opens its thank-you page, without stops.
  await visitor.goto(shared);
  await expect(visitor.locator('.event-status')).toBeVisible();
  await expect(visitor.locator('#routeItems .route-item')).toHaveCount(0);
  await visitor.context().close();

  // The resident keeps the home and lists it for next year from an empty form, then uses last year's details.
  const res = await (await browser.newContext()).newPage();
  const resGuard = watchConsole(res);
  await politeTiles(res);
  await signIn(res, request, RESIDENT, `/me`);
  await expect(res).toHaveURL(/\/me$/);
  await res.goto(`${DEMO}/me?e=${next.id}`);
  const card = res.locator('.dash-home', { hasText: mine.address });
  await expect(card.locator('[data-f="meta"]')).toContainText(`Not listed for ${name}`);
  await expect(card.locator('[data-f="form"]')).toBeHidden();
  await card.getByRole('button', { name: `List for ${name}` }).click();
  const form = card.locator('[data-f="form"]');
  await expect(form).toBeVisible();
  await expect(form.locator('textarea[name="note"]')).toHaveValue('');
  for (const box of await form.locator('input[type="checkbox"]').all()) await expect(box).not.toBeChecked();
  await expect(form.locator('select[name="start"]')).toHaveValue(next.default_start);
  // The fictional demo listing of last time comes back by choice (examples/demo/homes.json), so later runs see the same demo.
  await card.getByRole('button', { name: 'Use last year’s details' }).click();
  await expect(form.locator('textarea[name="note"]')).toHaveValue('Teal pumpkin: allergy-friendly treats on the porch.');
  await expect(form.locator('select[name="start"]')).toHaveValue('17:00');
  await expect(form.locator('select[name="end"]')).toHaveValue('20:00');
  await card.getByRole('button', { name: `List for ${name}` }).click();
  await expect(res.locator('.toast', { hasText: `Listed for ${name}` })).toBeVisible();
  await expect(res.locator('.dash-home', { hasText: mine.address }).locator('[data-f="meta"]')).toContainText('Shown when the event opens');
  expect((await (await request.get(`${DEMO}/api/homes?e=${next.id}`)).json()).homes, 'not public before it opens').toEqual([]);

  // Next year's opens (its date confirmed) and becomes the default: the home is on the map again, for this event.
  await page.goto(DEMO + '/admin/events');
  page.once('dialog', (d) => d.accept());
  await row(next.id).getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is open`);
  await row(next.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is the default event now`);
  const reopened = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(reopened.map((h) => [h.id, h.event_id, h.start, h.end, h.candy, h.nonfood, h.low_noise, h.note]))
    .toEqual([[mine.id, next.id, '17:00', '20:00', true, true, true, 'Teal pumpkin: allergy-friendly treats on the porch.']]);

  // Christmas lights next to Halloween: a lights draft previews with its own theme, words and filters, opens and
  // becomes the default; the resident lists the same home for it from an empty form.
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evType').selectOption('holiday_lights');
  await expect(page.locator('#evEndField')).toBeVisible();
  await page.locator('#evName').fill('Christmas Lights');
  await page.locator('#evStart').fill('2026-12-01');
  await page.locator('#evEnd').fill('2027-01-03');
  await page.locator('#evFrom').fill('18:00');
  await page.locator('#evTo').fill('22:00');
  await page.locator('#evBest').fill('2026-12-19');
  await page.locator('#evQuiet').fill('Please keep it quiet after 10 PM');
  await page.locator('#evDriving').check();
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.locator('#flash')).toContainText('Draft saved');
  const lights = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events.find((e) => e.state === 'draft' && e.type === 'holiday_lights');
  expect([lights.starts_on, lights.ends_on, lights.settings.best_night, lights.settings.route_modes]).toEqual(['2026-12-01', '2027-01-03', '2026-12-19', ['walking', 'driving']]);
  await row(lights.id).getByRole('link', { name: 'Preview' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await expect(page.locator('.schedule-row')).toContainText('Dec 1, 2026 – Jan 3, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Best night for a neighborhood walk: Dec 19, 2026');
  await expect(page.getByRole('button', { name: 'Drive-by', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Candy', exact: true })).toHaveCount(0);
  await page.goto(DEMO + '/admin/events');
  page.once('dialog', (d) => d.accept());
  await row(lights.id).getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('#flash')).toContainText('“Christmas Lights” is open');
  await row(lights.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Christmas Lights” is the default event now');
  expect((await (await request.get(DEMO + '/api/event')).json()).event.type).toBe('holiday_lights');
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes, 'no lights homes yet').toEqual([]);
  const view = await (await browser.newContext()).newPage();
  await politeTiles(view);
  await view.goto(DEMO + '/');
  await expect(view.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  // Its own look, not the demo's Halloween branding (ADR 0012).
  await expect(view.locator('section.intro h1')).toHaveText('Holiday nights. Bright lights.');
  await expect(view.locator('.brand-icon')).toHaveText('✨');
  await expect(view.locator('#listCount')).toHaveText('0 showing');
  await expect(view.getByRole('navigation', { name: 'Events' }).getByRole('link')).toHaveCount(3);
  await view.context().close();

  await res.goto(DEMO + '/me');
  const lightsCard = res.locator('.dash-home', { hasText: mine.address });
  await lightsCard.getByRole('button', { name: 'List for Christmas Lights' }).click();
  const lightsForm = lightsCard.locator('[data-f="form"]');
  await expect(lightsForm.locator('textarea[name="note"]')).toHaveValue('');
  await expect(lightsCard.getByRole('button', { name: 'Use last year’s details' }), 'no earlier lights listing to copy').toBeHidden();
  await lightsForm.locator('label', { hasText: 'Inflatables' }).click();
  await lightsForm.locator('textarea[name="note"]').fill('Inflatable snowmen on the lawn.');
  await lightsCard.getByRole('button', { name: 'List for Christmas Lights' }).click();
  await expect(res.locator('.toast')).toContainText('Listed for Christmas Lights');
  const lit = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(lit.map((h) => [h.id, h.event_id, h.details.styles, h.note])).toEqual([[mine.id, lights.id, ['inflatables'], 'Inflatable snowmen on the lawn.']]);

  // A rummage sale: its draft previews with the sale's theme, days and filters; it opens and becomes the default, and
  // the resident lists the same home for it from an empty form.
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evType').selectOption('garage_sale');
  await expect(page.locator('#evTbd'), 'a sale starts with its dates to be announced').toBeChecked();
  await page.locator('#evTbd').uncheck();
  await page.locator('#evName').fill('Community Rummage Sale');
  await page.locator('#evStart').fill('2027-04-10');
  await page.locator('#evEnd').fill('2027-04-11');
  await page.locator('#evFrom').fill('08:00');
  await page.locator('#evTo').fill('14:00');
  await expect(page.locator('input[name="evCategory"]')).toHaveCount(10);
  await page.locator('label', { hasText: 'Electronics' }).locator('input').uncheck();
  await page.locator('#evEarly').fill('07:30');
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.locator('#flash')).toContainText('Draft saved');
  const sale = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events.find((e) => e.state === 'draft' && e.type === 'garage_sale');
  expect([sale.name, sale.settings.days, sale.settings.categories.includes('electronics'), sale.settings.early_bird_time])
    .toEqual(['Community Rummage Sale', ['2027-04-10', '2027-04-11'], false, '07:30']);
  await row(sale.id).getByRole('link', { name: 'Preview' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'garage_sale');
  await expect(page.locator('.side-panel b')).toHaveText('Having a sale?');
  await expect(page.locator('section.intro h1'), 'the sale\'s own look, not the demo\'s Halloween branding (ADR 0012)').toHaveText('Good finds. Close to home.');
  await expect(page.locator('.schedule-row')).toContainText('Sat–Sun, April 10–11, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Early birds: not before 7:30 AM');
  await expect(page.getByRole('button', { name: 'Kids & toys', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Drive-by', exact: true })).toHaveCount(0);
  await page.goto(DEMO + '/admin/events');
  page.once('dialog', (d) => d.accept());
  await row(sale.id).getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('#flash')).toContainText('“Community Rummage Sale” is open');
  await row(sale.id).getByRole('button', { name: 'Make default' }).click();
  await expect(page.locator('#flash')).toContainText('“Community Rummage Sale” is the default event now');
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes, 'the sale shows only its own homes').toEqual([]);
  await res.goto(DEMO + '/me');
  const saleCard = res.locator('.dash-home', { hasText: mine.address });
  await expect(saleCard.locator('[data-f="meta"]')).toContainText('Not listed for Community Rummage Sale');
  await saleCard.getByRole('button', { name: 'List for Community Rummage Sale' }).click();
  const saleForm = saleCard.locator('[data-f="form"]');
  await expect(saleForm.locator('textarea[name="note"]')).toHaveValue('');
  await expect(saleForm.locator('input[name="categories"][value="electronics"]')).toHaveCount(0); // the organizer left it out
  await saleForm.locator('label', { hasText: 'Toys' }).click();
  await saleForm.locator('input[name="sale_day"][value="2027-04-11"]').uncheck();
  await saleForm.locator('select[name="day_start"][data-day="2027-04-10"]').selectOption('09:00');
  await saleForm.locator('textarea[name="note"]').fill('Board games and puzzles.');
  await saleCard.getByRole('button', { name: 'List for Community Rummage Sale' }).click();
  await expect(res.locator('.toast')).toContainText('Listed for Community Rummage Sale');
  const sold = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(sold.map((h) => [h.id, h.event_id, h.event_type, h.details.categories, h.windows, h.note]))
    .toEqual([[mine.id, sale.id, 'garage_sale', ['toys'], [{ date: '2027-04-10', start: '09:00', end: '14:00' }], 'Board games and puzzles.']]);
  const shown2 = await (await browser.newContext()).newPage();
  await politeTiles(shown2);
  await shown2.goto(DEMO + '/');
  await expect(shown2.locator('#homeCards')).not.toContainText('Inflatable snowmen');
  await expect(shown2.locator('#homeCards')).not.toContainText('Teal pumpkin');
  await shown2.context().close();
  resGuard.assertClean();
  await res.context().close();

  // Back to a Halloween on the demo's date for the next runs: every event of this run is archived and a Halloween draft
  // with the demo's Halloween branding opens (bin/e2e.sh lists the fictional homes for it again).
  const branding = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events.find((e) => e.id === before.id).settings.branding;
  expect(branding.headline, 'the demo\'s Halloween has its own branding (examples/demo/community.yaml)').toBe('Spooky streets.');
  for (const id of [sale.id, lights.id, next.id, before.id]) {
    page.once('dialog', (d) => d.accept());
    await row(id).getByRole('button', { name: 'Archive' }).click();
    await expect(row(id)).toContainText('Archived');
  }
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evName').fill(name);
  await page.locator('#evStart').fill(before.date);
  await page.locator('#evFrom').fill(before.starts_at);
  await page.locator('#evTo').fill(before.ends_at);
  // The Branding section shows Halloween's defaults as placeholders; the demo's own values go in.
  await expect(page.locator('#evHeadline')).toHaveAttribute('placeholder', 'Little ghosts.');
  for (const [id, key] of [['#evEmoji', 'brand_emoji'], ['#evHeadline', 'headline'], ['#evHeadlineAccent', 'headline_accent'], ['#evPrimary', 'primary_color'],
    ['#evAccent', 'accent_color'], ['#evIntro', 'intro'], ['#evAbout', 'about']]) await page.locator(id).fill(branding[key] || '');
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.locator('#flash')).toContainText('Draft saved');
  const back = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events.find((e) => e.state === 'draft' && e.type === 'halloween');
  page.once('dialog', (d) => d.accept());
  await row(back.id).getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is open`);
  const restored = (await (await request.get(DEMO + '/api/event')).json()).event;
  expect([restored.type, restored.date, restored.is_default]).toEqual(['halloween', before.date, true]);
  const look = (await (await request.get(DEMO + '/api/community')).json()).branding;
  expect([look.brand_emoji, look.headline, look.primary_color], 'the demo\'s Halloween branding is back').toEqual([branding.brand_emoji, branding.headline, branding.primary_color]);
  guard.assertClean();
});
