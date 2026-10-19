// Resident claim → email → POST confirm → dashboard → status update; competing claimant → dispute.
import { test, expect } from '@playwright/test';
import { DEMO, latestLink, mailTo, uniqueEmail, watchConsole } from './helpers.mjs';
import { politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

async function startClaim(page, house, email) {
  await page.goto(DEMO + '/#list-my-house');
  await page.getByRole('button', { name: /Can’t find your address/ }).click();
  await page.locator('#seHouse').fill(house);
  await page.locator('#seStreet').selectOption('MOONBEAM DR');
  await page.waitForTimeout(400);
  const box = await page.locator('#pinMap .leaflet-overlay-pane path').first().boundingBox();
  // A per-house spot in the lower-left part of the fictional outline (away from its cut corner),
  // so repeated runs never land within 4 m of an earlier test home.
  const n = Number(house);
  await page.mouse.click(box.x + box.width * (0.15 + (n % 29) / 100), box.y + box.height * (0.45 + (n % 23) / 100));
  await expect(page.locator('#pinMap .leaflet-marker-icon')).toHaveCount(1);
  await page.getByRole('button', { name: 'Next: your stop →' }).click();
  await page.locator('#regNote').fill('E2E test listing <script>alert(1)</script>');
  await page.getByRole('button', { name: 'Next: confirm by email →' }).click();
  await page.locator('#regEmail').fill(email);
  await page.getByRole('button', { name: /Email me the confirmation link/ }).click();
}

test('first verified claimant publishes immediately; second sees already-claimed and can request review', async ({ page, browser, request }) => {
  const guard = watchConsole(page);
  const house = String(1000 + (Date.now() % 8999));
  const alice = uniqueEmail('alice');
  const since = Date.now() - 1000;
  await startClaim(page, house, alice);
  await expect(page.locator('#registerSuccess')).toContainText('Check your email', { timeout: 60_000 });
  const mail = await latestLink(request, alice, { since });
  expect(mail.subject).toContain('Confirm your Maple Hollow Halloween listing');

  // A second neighbor starts a claim for the same address before Alice confirms (no reservation).
  const bobCtx = await browser.newContext();
  const bobPage = await bobCtx.newPage();
  const bob = uniqueEmail('bob');
  await startClaim(bobPage, house, bob);
  await expect(bobPage.locator('#registerSuccess')).toContainText('Check your email', { timeout: 60_000 });
  const bobMail = await latestLink(request, bob, { since });

  // Link scanners: GET twice does not sign in or consume.
  const scanner = await request.get(mail.url);
  expect(scanner.status()).toBe(200);
  await page.goto(mail.url);
  await expect(page.getByRole('heading', { name: 'Confirm and publish your house' })).toBeVisible();
  await expect(page.locator('body')).toContainText(`${house} Moonbeam Dr`);
  await page.getByRole('button', { name: 'Confirm sign-in and publish' }).click();
  await expect(page).toHaveURL(/\/me$/);
  await expect(page.locator('#claimOutcome')).toContainText('is on the map now');
  await expect(page.locator('#homes')).toContainText(`${house} Moonbeam Dr`);

  // Public API shows it immediately, escaped, no email.
  const homes = await (await request.get(DEMO + '/api/homes')).json();
  const mine = homes.homes.find((h) => h.address === `${house} Moonbeam Dr`);
  expect(mine).toBeTruthy();
  expect(JSON.stringify(homes)).not.toContain('@example.test');

  // Owner marks "Out of candy"; public map reflects it.
  await page.getByRole('button', { name: '🍬 Out of candy' }).click();
  await expect(page.locator('.toast')).toContainText('Status updated');
  const after = await (await request.get(DEMO + `/api/homes/${mine.id}`)).json();
  expect(after.home.status).toBe('out_of_candy');
  await page.goto(DEMO + '/');
  await page.locator('#search').fill(`${house} moonbeam`);
  await expect(page.locator('.home-card')).toHaveCount(1);
  await expect(page.locator('.home-card')).toContainText('Out of candy');
  await expect(page.locator('.home-card .card-note')).toContainText('<script>alert(1)</script>');

  // Replayed link fails.
  await page.goto(mail.url);
  await expect(page.getByRole('heading', { name: 'This link was already used' })).toBeVisible();

  // Bob confirms later: signed in, but the house stays Alice's.
  await bobPage.goto(bobMail.url);
  await bobPage.getByRole('button', { name: 'Confirm sign-in and publish' }).click();
  await expect(bobPage.locator('#claimOutcome')).toContainText('already claimed');
  await expect(bobPage.locator('#homes')).toContainText('You don’t manage any homes yet');

  // A third neighbor gets the conflict up front and files a review request (PoW in browser).
  const carolCtx = await browser.newContext();
  const carol = await carolCtx.newPage();
  await startClaim(carol, house, uniqueEmail('carol'));
  await expect(carol.locator('#claimedPanel')).toBeVisible({ timeout: 60_000 });
  await expect(carol.locator('#claimedPanel')).not.toContainText('alice');
  guard.assertClean();
  await bobCtx.close();
  await carolCtx.close();
});

test('report a listing from the map files a dispute that reaches the organizer inbox', async ({ page, request }) => {
  await page.goto(DEMO + '/');
  // Pick a random card: each address accepts only a few review requests per day (by design).
  const cards = page.locator('.home-card');
  await cards.nth(Math.floor(Math.random() * (await cards.count()))).click();
  await page.getByRole('button', { name: 'Report this listing' }).click();
  const reporter = uniqueEmail('reporter');
  await page.locator('#dEmail').fill(reporter);
  await page.locator('#dReason').fill('This is a fictional demo report written by the end-to-end test suite to verify that review requests are stored, '
    + 'protected by proof-of-work and emailed to the organizer through Mailpit without revealing who owns the listing.');
  await page.getByRole('button', { name: 'Send review request' }).click();
  await expect(page.locator('#disputeSuccess')).toContainText(/Request D-\d{6} sent/, { timeout: 60_000 });
  const inbox = await mailTo(request, 'organizer@maple-hollow.invalid');
  expect(inbox.length).toBeGreaterThan(0);
  expect(inbox[0].Subject).toMatch(/Review request D-\d{6}/);
});
