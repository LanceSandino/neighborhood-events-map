// Drawn art (D-20261105-01 to -03): the C9 garland hangs only on Christmas lights pages and stays decoration (no taps
// blocked, no sideways scrolling, a dark variant, no motion), pages show no emoji and draw their icons, the switcher's
// tabs are never clipped by "Past events", and an organizer gives an event a logo under Events and removes it again.
import { test, expect } from '@playwright/test';
import { DEMO, LIGHTS, SALE, LIGHTS_MAILPIT, politeTiles, watchConsole } from './helpers.mjs';

const ORGANIZER = process.env.E2E_ORGANIZER || 'e2e-organizer@example.test';
const WIDTHS = [375, 430, 768, 1440];
const GLYPHS = /[×←-⇿−⌀-⏿①-⓿■-➿️\u{1F000}-\u{1FAFF}]/u;
const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#123456"/></svg>';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

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

test('the garland is on Christmas lights pages only, decorative, in light and dark, and never makes the page scroll sideways', async ({ page }) => {
  const guard = watchConsole(page);
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    for (const scheme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: scheme });
      await page.goto(LIGHTS + '/');
      const garland = page.locator('.garland');
      await expect(garland).toBeVisible();
      await expect(garland).toHaveAttribute('aria-hidden', 'true');
      const look = await garland.evaluate((el) => {
        const s = getComputedStyle(el);
        return { events: s.pointerEvents, image: s.backgroundImage, animation: s.animationName, transition: s.transitionDuration, repeat: s.backgroundRepeat };
      });
      expect(look.events, 'never blocks a tap').toBe('none');
      expect(look.animation, 'nothing moves').toBe('none');
      expect(look.image).toContain(scheme === 'dark' ? 'garland-lights-dark.svg' : 'garland-lights.svg');
      const box = await garland.boundingBox();
      for (const control of ['.topline', '.event-switch-bar', 'main']) {
        const other = await page.locator(control).first().boundingBox();
        if (other) expect(box.y + box.height <= other.y + 0.5 || other.y + other.height <= box.y + 0.5, `${control} is not under the garland at ${width}`).toBe(true);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `no sideways scroll at ${width} ${scheme}`).toBe(0);
    }
  }
  await page.goto(DEMO + '/');
  await expect(page.locator('.garland')).toHaveCount(0);
  await page.goto(SALE + '/');
  await expect(page.locator('.garland')).toHaveCount(0);
  guard.assertClean();
});

test('the icons load and the pages carry no emoji, for every type', async ({ page }) => {
  const guard = watchConsole(page);
  const svg = page.waitForResponse((r) => /icons\/icons\.svg$/.test(new URL(r.url()).pathname));
  await page.goto(LIGHTS + '/');
  expect((await svg).status()).toBe(200);
  for (const base of [DEMO, LIGHTS, SALE]) {
    for (const path of ['/', '/about', '/login', '/privacy']) {
      await page.goto(base + path);
      const text = await page.evaluate(() => { const c = document.body.cloneNode(true); c.querySelectorAll('script').forEach((s) => s.remove()); return c.textContent; });
      expect(text.match(GLYPHS), `${base}${path}`).toBeNull();
    }
    await page.goto(base + '/');
    const size = await page.locator('.brand-icon svg, .brand-icon img').first().boundingBox();
    expect(size.width, 'the brand mark has a size').toBeGreaterThan(20);
    const pin = page.locator('.pin svg.icon').first();
    await expect(pin).toBeVisible();
    await expect(page.locator('.home-card .card-symbol svg').first()).toBeVisible();
    await page.locator('.home-card .save').first().evaluate((b) => b.getAttribute('aria-label')).then((label) => expect(label).toMatch(/my stops/));
  }
  guard.assertClean();
});

test('at phone width the tabs are never clipped because of "Past events"', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(LIGHTS + '/');
  const past = page.locator('.past-link');
  await expect(past).toBeVisible();
  const bar = await page.locator('.event-switch').boundingBox();
  const link = await past.boundingBox();
  expect(link.y, 'Past events has its own line when both do not fit').toBeGreaterThanOrEqual(bar.y + bar.height - 1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
});

test('an organizer\'s My house and console pages do not scroll sideways on a phone', async ({ page, request }) => {
  await signIn(page, request, ORGANIZER, '/me');
  await expect(page.locator('#adminLink')).toBeVisible();
  for (const width of [375, 390]) {
    await page.setViewportSize({ width, height: 800 });
    for (const path of ['/me', '/admin/addresses', '/admin', '/admin/events', '/admin/community']) {
      await page.goto(LIGHTS + path);
      await page.waitForLoadState('networkidle');
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${path} at ${width}`).toBe(0);
    }
    await page.goto(LIGHTS + '/me');
    await expect(page.locator('#btnLogout')).toBeInViewport({ ratio: 1 });
  }
});

test('an organizer gives an event a logo under Events, its page shows it, and removing it brings the drawn mark back', async ({ page, request }) => {
  const guard = watchConsole(page);
  await signIn(page, request, ORGANIZER, '/admin/events');
  const open = (await (await request.get(LIGHTS + '/api/event')).json()).event;
  await page.locator(`[data-edit="${open.id}"]`).click();
  await expect(page.locator('#evEmoji')).toHaveCount(0);
  await page.waitForLoadState('networkidle'); // one upload at a time per network (nginx), as for the other uploads
  if (await page.locator('#evLogoRemove').count()) { // a run that failed halfway left its logo
    await page.locator('#evLogoRemove').click();
    await expect(page.locator('#flash')).toContainText('Logo removed');
  }
  await page.locator('#evLogo').setInputFiles({ name: 'event-logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from(LOGO) });
  await expect(page.locator('#flash')).toContainText('Logo saved');
  await expect(page.locator('#evLogoField img')).toBeVisible();
  await page.goto(LIGHTS + '/');
  const img = page.locator('.brand-icon img');
  await expect(img).toHaveAttribute('src', new RegExp(`/branding/event-${open.id}/logo\\.svg\\?v=[0-9a-f]{10}$`));
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/branding\/event-/);
  expect(await img.evaluate((el) => el.complete && el.naturalWidth > 0), 'the image draws under the strict policy').toBe(true);
  await page.goto(LIGHTS + '/admin/events');
  await page.locator(`[data-edit="${open.id}"]`).click();
  await page.waitForLoadState('networkidle');
  await page.locator('#evLogoRemove').click();
  await expect(page.locator('#flash')).toContainText('Logo removed');
  await expect(page.locator('#evLogoField img')).toHaveCount(0);
  await page.goto(LIGHTS + '/');
  await expect(page.locator('.brand-icon svg use')).toHaveAttribute('href', /#brand-lights$/);
  guard.assertClean();
});
