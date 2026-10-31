// Switching seasons on the fictional demo stack (runs last: project "season"; bin/e2e.sh lists the fictional homes
// again for the new event afterwards). An organizer prepares the next event in Organizer → Events, previews it and
// puts it on the map: the ended event's homes disappear at once, a stop list shared for it opens without its stops,
// and a resident lists their home for the new event in My house from an empty form. Then Christmas lights follow
// Halloween and a rummage sale follows the lights: the same resident lists the same home for each, three independent
// listings, and an ended season's listing is never public again. The demo then gets a Halloween event back.
import { test, expect } from '@playwright/test';
import { DEMO, SHOTS, watchConsole, politeTiles } from './helpers.mjs';

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

test('an organizer switches the map to the next event; last season disappears and residents list again from an empty form', async ({ page, browser, request }) => {
  const guard = watchConsole(page);
  const before = (await (await request.get(DEMO + '/api/event')).json()).event;
  const homes = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(homes.length).toBeGreaterThanOrEqual(14);
  const mine = homes.find((h) => h.address === '100 Pumpkin Patch Ln');
  expect(mine).toBeTruthy();

  // A stop list shared during the current event names it.
  const open = homes.filter((h) => h.status === 'active');
  const shared = `${DEMO}/?stops=${open[0].id}.${open[1].id}&e=${before.id}`;
  const visitor = await (await browser.newContext()).newPage();
  await politeTiles(visitor);
  await visitor.goto(shared);
  await expect(visitor.locator('#routeItems .route-item')).toHaveCount(2);
  expect(await visitor.locator('#btnShareRoute').getAttribute('data-url')).toContain(`e=${before.id}`);

  await signIn(page, request, ORGANIZER, '/admin/events');
  await expect(page).toHaveURL(/\/admin\/events$/);
  await expect(page.getByRole('heading', { name: 'Events', level: 1 })).toBeVisible();
  await expect(page.locator('#admin-events')).toContainText(`${before.name}`);
  // The next season keeps the demo's event name, so every later run (and its email subjects) reads the same.
  const name = before.name;
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evName').fill(name);
  await page.locator('#evStart').fill(before.date);
  await page.locator('#evFrom').fill(before.starts_at);
  await page.locator('#evTo').fill(before.ends_at);
  await page.locator('#evRules').fill('Park on one side of the street only');
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.locator('#flash')).toContainText('Draft saved');
  const events = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events;
  const draft = events.find((e) => e.state === 'draft' && e.starts_on === before.date && e.settings.rules?.[0] === 'Park on one side of the street only');
  expect(draft).toBeTruthy();
  const row = page.locator(`tr[data-event="${draft.id}"]`);
  await expect(row).toContainText('Draft');
  // Organizer → Events with the event on the map and the prepared draft (docs/design/screenshots, README).
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({ path: `${SHOTS}/admin-events-1440.png` });
  expect((await (await request.get(DEMO + '/api/event')).json()).event.id).toBe(before.id); // a draft is not public

  // The preview shows the draft's page without any home.
  await row.getByRole('link', { name: 'Preview' }).click();
  await expect(page.locator('.preview-banner')).toContainText(name);
  await expect(page.locator('.home-card')).toHaveCount(0);
  await page.goto(DEMO + '/admin/events');

  page.once('dialog', (d) => d.accept());
  await page.locator(`tr[data-event="${draft.id}"]`).getByRole('button', { name: 'Put on the map' }).click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is on the map now.`);
  await expect(page.locator(`tr[data-event="${before.id}"]`)).toContainText('Archived');

  // Last season's homes are gone at once; nothing was carried over.
  const after = (await (await request.get(DEMO + '/api/event')).json()).event;
  expect([after.id, after.name, after.active, after.rules]).toEqual([draft.id, name, true, ['Park on one side of the street only']]);
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes).toEqual([]);
  expect((await request.get(`${DEMO}/api/homes/${mine.id}`)).status()).toBe(404);
  await visitor.goto(DEMO + '/');
  await expect(visitor.locator('#listCount')).toHaveText('0 showing');

  // The stop list shared for the ended event opens without its stops, and says why.
  await visitor.goto(shared);
  await expect(visitor.locator('.toast')).toContainText('made for another event');
  await expect(visitor.locator('#routeItems .route-item')).toHaveCount(0);
  expect(visitor.url()).not.toContain('stops=');
  await visitor.context().close();

  // The resident keeps the home and lists it for the new event from an empty form (signed in once, in their own
  // browser, for this and the next seasons; the organizer stays signed in here).
  const res = await (await browser.newContext()).newPage();
  const resGuard = watchConsole(res);
  await politeTiles(res);
  await signIn(res, request, RESIDENT, '/me');
  await expect(res).toHaveURL(/\/me$/);
  const card = res.locator('.dash-home', { hasText: mine.address });
  await expect(card.locator('[data-f="meta"]')).toContainText(`Not listed for ${name}`);
  await expect(card.locator('[data-f="form"]')).toBeHidden();
  await card.getByRole('button', { name: `List for ${name}` }).click();
  const form = card.locator('[data-f="form"]');
  await expect(form).toBeVisible();
  await expect(form.locator('textarea[name="note"]')).toHaveValue('');
  for (const box of await form.locator('input[type="checkbox"]').all()) await expect(box).not.toBeChecked();
  await expect(form.locator('select[name="start"]')).toHaveValue(after.starts_at);
  // The resident enters the home's fictional demo listing again (examples/demo/homes.json), so later runs see the same demo.
  await form.locator('select[name="start"]').selectOption('17:00');
  await form.locator('select[name="end"]').selectOption('20:00');
  for (const label of ['Candy', 'Non-food treats', 'Sensory-friendly (quiet)']) await form.locator('label', { hasText: label }).first().click();
  await form.locator('textarea[name="note"]').fill('Teal pumpkin: allergy-friendly treats on the porch.');
  await card.getByRole('button', { name: `List for ${name}` }).click();
  await expect(res.locator('.toast')).toContainText(`Listed for ${name}`);
  await expect(res.locator('.dash-home', { hasText: mine.address }).locator('[data-f="meta"]')).toContainText('Shown on the map');
  const listed = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(listed.map((h) => [h.id, h.event_id, h.start, h.end, h.candy, h.nonfood, h.low_noise, h.note]))
    .toEqual([[mine.id, after.id, '17:00', '20:00', true, true, true, 'Teal pumpkin: allergy-friendly treats on the porch.']]);

  // Christmas lights after Halloween: a lights draft previews with its own theme, words and filters; once it is on
  // the map the Halloween homes are archived and invisible. The demo then gets a Halloween event again.
  await page.goto(DEMO + '/admin/events');
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
  await page.locator(`tr[data-event="${lights.id}"]`).getByRole('link', { name: 'Preview' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await expect(page.locator('.schedule-row')).toContainText('Dec 1, 2026 – Jan 3, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Best night for a neighborhood walk: Dec 19, 2026');
  await expect(page.getByRole('button', { name: 'Drive-by', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Candy', exact: true })).toHaveCount(0);
  await page.goto(DEMO + '/admin/events');
  page.once('dialog', (d) => d.accept());
  await page.locator(`tr[data-event="${lights.id}"]`).getByRole('button', { name: 'Put on the map' }).click();
  await expect(page.locator('#flash')).toContainText('“Christmas Lights” is on the map now.');
  expect((await (await request.get(DEMO + '/api/event')).json()).event.type).toBe('holiday_lights');
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes, 'the Halloween homes are archived and invisible').toEqual([]);
  expect((await request.get(`${DEMO}/api/homes/${mine.id}`)).status()).toBe(404);
  const view = await (await browser.newContext()).newPage();
  await politeTiles(view);
  await view.goto(DEMO + '/');
  await expect(view.locator('html')).toHaveAttribute('data-event-type', 'holiday_lights');
  await expect(view.locator('#listCount')).toHaveText('0 showing');

  // The resident lists the same home for the lights, from an empty form.
  await res.goto(DEMO + '/me');
  const lightsCard = res.locator('.dash-home', { hasText: mine.address });
  await lightsCard.getByRole('button', { name: 'List for Christmas Lights' }).click();
  const lightsForm = lightsCard.locator('[data-f="form"]');
  await expect(lightsForm.locator('textarea[name="note"]')).toHaveValue('');
  await lightsForm.locator('label', { hasText: 'Inflatables' }).click();
  await lightsForm.locator('textarea[name="note"]').fill('Inflatable snowmen on the lawn.');
  await lightsCard.getByRole('button', { name: 'List for Christmas Lights' }).click();
  await expect(res.locator('.toast')).toContainText('Listed for Christmas Lights');
  const lit = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(lit.map((h) => [h.id, h.event_id, h.details.styles, h.note])).toEqual([[mine.id, lights.id, ['inflatables'], 'Inflatable snowmen on the lawn.']]);

  // A rummage sale after the lights: its draft previews with the sale's theme, days and filters; on the map, the
  // lights listing leaves and the resident lists the same home for the sale from an empty form.
  await page.goto(DEMO + '/admin/events');
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evType').selectOption('garage_sale');
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
  await page.locator(`tr[data-event="${sale.id}"]`).getByRole('link', { name: 'Preview' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-event-type', 'garage_sale');
  await expect(page.locator('.side-panel b')).toHaveText('Having a sale?'); // the demo keeps its own headline (D-20261031-16)
  await expect(page.locator('.schedule-row')).toContainText('Sat–Sun, April 10–11, 2027');
  await expect(page.locator('.schedule-row')).toContainText('Early birds: not before 7:30 AM');
  await expect(page.getByRole('button', { name: 'Kids & toys', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Drive-by', exact: true })).toHaveCount(0);
  await page.goto(DEMO + '/admin/events');
  page.once('dialog', (d) => d.accept());
  await page.locator(`tr[data-event="${sale.id}"]`).getByRole('button', { name: 'Put on the map' }).click();
  await expect(page.locator('#flash')).toContainText('“Community Rummage Sale” is on the map now.');
  expect((await (await request.get(DEMO + '/api/homes')).json()).homes, 'the lights listing left with its season').toEqual([]);
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
  const shown = await (await browser.newContext()).newPage();
  await politeTiles(shown);
  await shown.goto(DEMO + '/');
  await expect(shown.locator('#homeCards')).not.toContainText('Inflatable snowmen');
  await expect(shown.locator('#homeCards')).not.toContainText('Teal pumpkin');
  await shown.context().close();
  resGuard.assertClean();
  await res.context().close();

  // Back to Halloween for the next runs (bin/e2e.sh lists the fictional homes for it again).
  await page.getByRole('button', { name: '+ Prepare an event' }).click();
  await page.locator('#evName').fill(name);
  await page.locator('#evStart').fill(before.date);
  await page.locator('#evFrom').fill(before.starts_at);
  await page.locator('#evTo').fill(before.ends_at);
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.locator('#flash')).toContainText('Draft saved');
  const back = (await (await page.request.get(DEMO + '/api/admin/events')).json()).events.find((e) => e.state === 'draft' && e.type === 'halloween');
  page.once('dialog', (d) => d.accept());
  await page.locator(`tr[data-event="${back.id}"]`).getByRole('button', { name: 'Put on the map' }).click();
  await expect(page.locator('#flash')).toContainText(`“${name}” is on the map now.`);
  expect((await (await request.get(DEMO + '/api/event')).json()).event.type).toBe('halloween');
  guard.assertClean();
});
