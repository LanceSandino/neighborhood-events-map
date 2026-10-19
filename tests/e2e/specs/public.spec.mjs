// Public family experience on the fictional demo stack (14 seeded fictional homes, no zones).
import { test, expect } from '@playwright/test';
import { DEMO, VENTURA, SHOTS, watchConsole } from './helpers.mjs';
import { politeTiles } from './helpers.mjs';

test.beforeEach(async ({ page }) => { await politeTiles(page); });

const total = async (request) => (await (await request.get(DEMO + '/api/homes')).json()).homes.length;

test('filters affect list and pins together and persist after reload; favorites persist', async ({ page, request }) => {
  const guard = watchConsole(page);
  const n = await total(request);
  expect(n).toBeGreaterThanOrEqual(14);
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#listCount')).toHaveText(`${n} showing`);
  await expect(page.locator('.leaflet-marker-icon')).toHaveCount(n);
  await page.getByRole('button', { name: '💚 Low sensory' }).click();
  const shown = Number((await page.locator('#listCount').textContent()).split(' ')[0]);
  expect(shown).toBeLessThan(n);
  expect(shown).toBeGreaterThan(0);
  await expect(page.locator('.home-card')).toHaveCount(shown);
  await expect(page.locator('.leaflet-marker-icon .pin:not(.dim)')).toHaveCount(shown);
  await page.locator('.home-card .save').first().click();
  await expect(page.locator('#savedCount')).toHaveText('1');
  await page.reload();
  await expect(page.getByRole('button', { name: '💚 Low sensory' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#listCount')).toHaveText(`${shown} showing`);
  await expect(page.locator('#savedCount')).toHaveText('1');
  const stored = await page.evaluate(() => Object.keys(localStorage));
  expect(stored).toContain('nhm:maple-hollow-demo:2026');
  await page.locator('#btnReset').click({ force: true }).catch(() => {});
  guard.assertClean();
});

test('selecting a card opens the map preview; keyboard works; route sheet lists saved stops', async ({ page }) => {
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  const card = page.locator('.home-card').nth(2);
  await card.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#mapSelected')).toHaveClass(/open/);
  await expect(page.locator('#mapSelected b')).toHaveText(await card.locator('.card-line b').textContent());
  for (const i of [0, 3, 5]) await page.locator('.home-card .save').nth(i).click();
  await page.getByRole('button', { name: '🧭 Build my route →' }).click();
  await expect(page.locator('#routeModal')).toBeVisible();
  await expect(page.locator('#routeItems .route-item')).toHaveCount(3);
  await expect(page.locator('#routeNotice')).toContainText('not walking directions');
  await expect(page.locator('.leaflet-marker-icon .pin-number')).toHaveCount(3);
  await page.keyboard.press('Escape');
  await expect(page.locator('#routeModal')).toBeHidden();
});

test('map failure still leaves a readable list', async ({ page, request }) => {
  const n = await total(request);
  await page.route('**/vendor/leaflet/leaflet.js', (r) => r.abort());
  await page.goto(DEMO + '/');
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('#mapFallback')).toBeVisible();
  await expect(page.locator('.home-card')).toHaveCount(n);
});

for (const [w, h] of [[375, 812], [430, 932], [768, 1024], [1440, 1000]]) {
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
