// Switching seasons on the fictional demo stack (runs last: project "season"; bin/e2e.sh lists the fictional homes
// again for the new event afterwards). An organizer prepares the next event in Organizer → Events, previews it and
// puts it on the map: the ended event's homes disappear at once, a stop list shared for it opens without its stops,
// and a resident lists their home for the new event in My house from an empty form.
import { test, expect } from '@playwright/test';
import { DEMO, latestLink, watchConsole, politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const ORGANIZER = process.env.E2E_ORGANIZER || 'e2e-organizer@example.test';
const RESIDENT = 'demo-resident-01@example.invalid'; // the fictional demo's first home (examples/demo/homes.json)

async function signIn(page, request, email, next) {
  const since = Date.now() - 1000;
  await page.goto(`${DEMO}/login?next=${encodeURIComponent(next)}`);
  await page.locator('#loginEmail').fill(email);
  await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
  await expect(page.locator('#loginSuccess')).toBeVisible({ timeout: 60_000 });
  const mail = await latestLink(request, email, { since });
  await page.goto(mail.url);
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

  // The resident keeps the home and lists it for the new event from an empty form.
  await page.context().clearCookies();
  await signIn(page, request, RESIDENT, '/me');
  await expect(page).toHaveURL(/\/me$/);
  const card = page.locator('.dash-home', { hasText: mine.address });
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
  await expect(page.locator('.toast')).toContainText(`Listed for ${name}`);
  await expect(page.locator('.dash-home', { hasText: mine.address }).locator('[data-f="meta"]')).toContainText('Shown on the map');
  const listed = (await (await request.get(DEMO + '/api/homes')).json()).homes;
  expect(listed.map((h) => [h.id, h.event_id, h.start, h.end, h.candy, h.nonfood, h.low_noise, h.note]))
    .toEqual([[mine.id, after.id, '17:00', '20:00', true, true, true, 'Teal pumpkin: allergy-friendly treats on the porch.']]);
  guard.assertClean();
});
