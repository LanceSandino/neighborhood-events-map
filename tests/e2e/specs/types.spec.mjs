// The three event types side by side on their fictional demo stacks (Halloween: examples/demo, Christmas lights:
// examples/demo-lights, garage sale: examples/demo-sale): the shell handles long names (the compact brand, the footer),
// notices take the type's colours, every width from 375 to 1440 px in light and dark, with larger text and more
// contrast, has no horizontal overflow, nothing animates with reduced motion, every public page passes axe in both
// themes, and a keyboard alone reaches every part of the map page, lists a home and acts in My house. The browser
// clock is pinned to a moment of each event; the keyboard test gives back the home it lists.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { DEMO, LIGHTS, SALE, MAILPIT, LIGHTS_MAILPIT, SALE_MAILPIT, atLocal, politeTiles, uniqueEmail, watchConsole } from './helpers.mjs';

const TZ = 'America/Chicago';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const TYPES = [
  { key: 'halloween', base: DEMO, mailpit: MAILPIT, when: atLocal('2026-10-30', '18:00', TZ), detail: 'input[name="candy"]', status: 'paused' },
  { key: 'holiday_lights', base: LIGHTS, mailpit: LIGHTS_MAILPIT, when: atLocal('2026-12-12', '19:30', TZ), detail: 'input[name="styles"]', status: 'off_tonight' },
  { key: 'garage_sale', base: SALE, mailpit: SALE_MAILPIT, when: atLocal('2027-04-10', '10:00', TZ), detail: 'input[name="categories"]', status: 'paused' },
];
const WIDTHS = [[375, 812], [390, 844], [430, 932], [768, 1024], [956, 440], [1180, 820], [1440, 1000]];

test.beforeEach(async ({ page }) => { await politeTiles(page); });

/** Opens a type's map at a moment of its event, with a clean device and optional appearance choices. */
async function openAt(page, t, path = '/', prefs = null) {
  await page.clock.install({ time: t.when });
  await page.goto(t.base + path);
  await page.evaluate((p) => { localStorage.clear(); if (p) localStorage.setItem('nhm:appearance', JSON.stringify(p)); }, prefs);
  await page.reload();
  if (path === '/') {
    await expect(page.locator('#listCount')).not.toHaveText('');
    // The browser has drawn the page (home.js adds each filter's count): cards carry their status chips from here on.
    await expect(page.locator('.quick-filters .fcount').first()).toBeAttached();
  }
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const smallTargets = (page) => page.evaluate(() => [...document.querySelectorAll('.controls button, .controls select, .filter-more button, .filter-more select, .filter-more input, .mobile-bottom a, .map-tools button, .home-card .save, .side-action')]
  .filter((e) => e.offsetParent && e.getBoundingClientRect().height < 44).map((e) => e.id || e.className || e.textContent.trim()));

/** Presses Tab (or Shift+Tab) until the focused element matches the selector; keyboard only, never a click. */
async function tabTo(page, selector, { back = false, max = 160 } = {}) {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
    if (await page.evaluate((s) => document.activeElement && document.activeElement.matches(s), selector)) return;
  }
  throw new Error(`${selector} is not reachable with ${back ? 'Shift+Tab' : 'Tab'}`);
}

for (const t of TYPES) {
  test(`${t.key}: the brand handles the names, notices take the type's colours, a long footer wraps`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await openAt(page, t);
    const brand = await page.evaluate(() => {
      const name = document.querySelector('.brand-name');
      const sub = document.querySelector('.brand-sub');
      const lh = parseFloat(getComputedStyle(name).lineHeight);
      return { long: document.querySelector('.brand').classList.contains('long'), size: getComputedStyle(name).fontSize,
        lines: Math.round(name.getBoundingClientRect().height / lh), subLines: Math.round(sub.getBoundingClientRect().height / parseFloat(getComputedStyle(sub).lineHeight || 15)) };
    });
    if (t.key === 'halloween') expect(brand, 'Maple Hollow / Halloween fits: the brand as it always was').toMatchObject({ long: false, size: '17px', lines: 2 });
    else {
      expect(brand.long, 'a name longer than the column').toBe(true);
      expect(brand.size).toBe('15px');
      expect(brand.lines, 'Christmas Lights on its own line; a long sale name wraps evenly').toBeLessThanOrEqual(t.key === 'holiday_lights' ? 2 : 3);
    }
    // The route's notices: the type's tokens on the lights and the sale, app.css's peach for Halloween (unchanged).
    const notice = await page.evaluate(() => { const n = document.querySelector('#routeNotice') || document.querySelector('.notice'); return n ? getComputedStyle(n).backgroundColor : null; });
    const peach = 'rgb(255, 242, 231)';
    if (t.key === 'halloween') expect(notice).toBe(peach); else expect(notice).not.toBe(peach);
    if (t.key !== 'halloween') expect(await page.locator('.route-rules').evaluate((n) => getComputedStyle(n).backgroundColor)).not.toBe(peach);
    // A phone page never widens for a long community and event name in the footer.
    await page.setViewportSize({ width: 375, height: 812 });
    await page.evaluate(() => { document.querySelector('.footer-id').firstChild.textContent = 'Maple Hollow Neighborhood Association Community Rummage and Holiday Lights Night · '; });
    expect(await overflow(page)).toBeLessThanOrEqual(0);
  });

  for (const scheme of ['light', 'dark']) {
    for (const [w, h] of WIDTHS) {
      test(`${t.key}: no horizontal overflow at ${w}px (${scheme})`, async ({ browser }) => {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme });
        const page = await ctx.newPage();
        await politeTiles(page);
        await openAt(page, t);
        expect(await overflow(page)).toBeLessThanOrEqual(0);
        await page.locator('#btnFilters').click();
        expect(await overflow(page), 'with the Filters panel open').toBeLessThanOrEqual(0);
        await ctx.close();
      });
    }
  }

  for (const [w, h] of WIDTHS) {
    test(`${t.key}: larger text and more contrast at ${w}px: no overflow${t.key === 'halloween' ? '' : ', 44px targets'}`, async ({ browser }) => {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, contrast: 'more' });
      const page = await ctx.newPage();
      await politeTiles(page);
      await openAt(page, t, '/', { text: 'large', contrast: 'more' });
      expect(await page.evaluate(() => [document.documentElement.dataset.text, document.documentElement.dataset.contrast])).toEqual(['large', 'more']);
      await page.locator('#btnFilters').click();
      expect(await overflow(page)).toBeLessThanOrEqual(0);
      // Halloween's filter chips, time picker and card hearts stay 40 px so its pages stay as they were (a known
      // limitation, docs/quality/event-types-acceptance.md); the lights and the sale have 44 px targets.
      if (t.key !== 'halloween') expect(await smallTargets(page), `${w}px small targets`).toEqual([]);
      await ctx.close();
    });
  }

  test(`${t.key}: reduced motion: nothing animates, no smooth scrolling`, async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await politeTiles(page);
    await openAt(page, t);
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto');
    expect(await page.evaluate(() => [...document.querySelectorAll('*')].filter((e) => getComputedStyle(e).animationName !== 'none').length), 'nothing animates').toBe(0);
    await ctx.close();
  });

  for (const scheme of ['light', 'dark']) {
    // The map, register sheet, route and About pages of the lights and the sale are checked in their own specs, and
    // every Halloween page in a11y.spec.mjs; here: the other public pages of the lights and the sale, and the
    // selected-home panel of every type.
    const pages = [['selected home', '/']].concat(t.key === 'halloween' ? [] : [['sign in', '/login'], ['privacy', '/privacy'], ['terms', '/terms'], ['accessibility', '/accessibility'], ['changelog', '/changelog']]);
    for (const [name, path] of pages) {
      test(`${t.key}: no WCAG 2.1 AA violations: ${name} (${scheme})`, async ({ browser }) => {
        const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
        const page = await ctx.newPage();
        await politeTiles(page);
        await page.clock.install({ time: t.when });
        await page.goto(t.base + path);
        await page.waitForTimeout(800);
        if (name === 'selected home') {
          await page.locator('#homeCards .home-card').first().click();
          await expect(page.locator('#mapSelected')).toHaveClass(/open/);
        }
        const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
        const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
        expect(summary, summary.join('\n')).toEqual([]);
        await ctx.close();
      });
    }
  }

  test(`${t.key}: keyboard only: filters, list, map pins, selected home, saved stops, route, listing a home and My house`, async ({ page, request }) => {
    test.setTimeout(240_000);
    const guard = watchConsole(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await openAt(page, t);
    const total = (await page.locator('#listCount').textContent()).trim();
    // Skip link first, then search.
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => document.activeElement.className)).toContain('skip-link');
    await tabTo(page, '#search');
    const street = (await page.locator('#homeCards .home-card .card-line b').first().textContent()).trim().split(' ').slice(1, 2)[0];
    await page.keyboard.type(street);
    await expect(page.locator('#listCount')).not.toHaveText(total);
    await page.keyboard.press('Control+A');
    await page.keyboard.press('Backspace');
    await expect(page.locator('#listCount')).toHaveText(total);
    // A quick filter on and off with Space; the Filters panel with Enter, a filter in it, Reset.
    await tabTo(page, '.quick-filters [data-filter]:not([data-filter="saved"])');
    const quick = page.locator('.quick-filters [data-filter]:focus');
    await page.keyboard.press('Space');
    await expect(quick).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Space');
    await expect(quick).toHaveAttribute('aria-pressed', 'false');
    await tabTo(page, '#btnFilters');
    await page.keyboard.press('Enter');
    await expect(page.locator('#btnFilters')).toHaveAttribute('aria-expanded', 'true');
    await tabTo(page, '#moreFilters .mini-filter');
    await page.keyboard.press('Space');
    await expect(page.locator('#moreFilters .mini-filter:focus')).toHaveAttribute('aria-pressed', 'true');
    await tabTo(page, '#btnReset');
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-filter][aria-pressed="true"]')).toHaveCount(0);
    // A card opens the selected home; its "Save" is reachable and saves the stop.
    await tabTo(page, '#homeCards .home-card');
    const address = await page.evaluate(() => document.activeElement.getAttribute('aria-label'));
    await page.keyboard.press('Enter');
    await expect(page.locator('#mapSelected')).toHaveClass(/open/);
    await expect(page.locator('#mapSelected')).toContainText(address);
    await tabTo(page, '#mapSelected [data-save]');
    await page.keyboard.press('Enter');
    await expect(page.locator('#routeItems')).toContainText(address);
    // A map pin is a keyboard stop too: Enter on it selects that home.
    await tabTo(page, '.leaflet-marker-icon', { back: true });
    const pinned = await page.evaluate(() => document.activeElement.getAttribute('title'));
    await page.keyboard.press('Enter');
    await expect(page.locator('#mapSelected')).toContainText(pinned);
    // The route's controls are reachable; the saved stop is on it.
    await tabTo(page, '.route-pane button');
    // List a home: the sheet opens on Enter, the address and its pin by keyboard (arrows move the map, Enter drops
    // the pin at its centre), the type's details, the email; Escape is not needed because the form is sent.
    await tabTo(page, '.top-add');
    await page.keyboard.press('Enter');
    await expect(page.locator('#registerModal')).toHaveClass(/open/);
    await tabTo(page, '#btnSelfEntry');
    await page.keyboard.press('Enter');
    await tabTo(page, '#seHouse');
    const house = String(5000 + Math.floor(Math.random() * 4000));
    await page.keyboard.type(house);
    await tabTo(page, '#seStreet');
    await page.keyboard.press('ArrowDown');
    const streetName = await page.locator('#seStreet').inputValue();
    expect(streetName).not.toBe('');
    await tabTo(page, '#pinMap');
    // The pin goes in the empty south-east of the outline (70–90 % across and down, where no demo home is; a
    // different spot each run, so homes earlier runs added are not hit): zoom in twice ("+", the map doubles around
    // its centre each time), then arrow keys (80 px a press, one at a time: Leaflet ignores a key while it pans).
    const outline = await page.locator('#pinMap .leaflet-overlay-pane path').first().boundingBox();
    const view = await page.locator('#pinMap').boundingBox();
    for (const _ of [1, 2]) { await page.keyboard.press('+'); await page.waitForTimeout(500); }
    const steps = (centre, from, size) => Math.round((4 * (from + size * (0.7 + Math.random() * 0.2) - centre)) / 80);
    for (const [key, n] of [['ArrowRight', steps(view.x + view.width / 2, outline.x, outline.width)], ['ArrowDown', steps(view.y + view.height / 2, outline.y, outline.height)]]) {
      for (let i = 0; i < n; i++) { await page.keyboard.press(key); await page.waitForTimeout(350); }
    }
    await page.waitForTimeout(400);
    await page.keyboard.press('Enter');
    await expect(page.locator('#pinMap .leaflet-marker-icon')).toHaveCount(1);
    await tabTo(page, '#registerForm .primary[data-next="2"]');
    await page.keyboard.press('Enter');
    await expect(page.locator('#registerForm fieldset[data-step="2"]')).toBeVisible();
    await tabTo(page, `#registerForm ${t.detail}`);
    const before = await page.locator(`#registerForm ${t.detail}:focus`).isChecked();
    await page.keyboard.press('Space');
    await expect(page.locator(`#registerForm ${t.detail}:focus`)).toBeChecked({ checked: !before });
    if (before) await page.keyboard.press('Space'); // the type's default was on: keep it on
    await tabTo(page, '#registerForm .primary[data-next="3"]');
    await page.keyboard.press('Enter');
    await tabTo(page, '#regEmail');
    const email = uniqueEmail(`keys-${t.key}`);
    await page.keyboard.type(email);
    const since = Date.now() - 1000;
    await tabTo(page, '#btnSendLink');
    await page.keyboard.press('Enter');
    await expect(page.locator('#registerSuccess')).toContainText('Check your email', { timeout: 90_000 });
    let link = null;
    for (let i = 0; i < 40 && !link; i++) {
      const found = await (await request.get(`${t.mailpit}/api/v1/search?query=${encodeURIComponent('to:' + email)}`)).json();
      const msg = (found.messages || []).find((m) => Date.parse(m.Created) >= since);
      link = msg && (await (await request.get(`${t.mailpit}/api/v1/message/${msg.ID}`)).json()).Text.match(/\/auth\/confirm\?t=[A-Za-z0-9_-]{43}/)?.[0];
      if (!link) await page.waitForTimeout(500);
    }
    expect(link, 'a sign-in link').toBeTruthy();
    await page.goto(t.base + link);
    await tabTo(page, 'form[action="/auth/confirm"] button[type="submit"]');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/me$/);
    // My house: a status, a note saved, then the home given back — every step with the keyboard.
    const mine = new RegExp(`^${house} `, 'i');
    const home = page.locator('.dash-home', { has: page.locator('h2', { hasText: mine }) });
    await expect(home).toBeVisible();
    // Names the article for the selectors below (no interaction); a save draws the home again, so it is named again.
    const at = async (sel) => { await home.evaluate((el) => { el.dataset.test = 'mine'; }); return `.dash-home[data-test="mine"] ${sel}`; };
    await tabTo(page, await at(`[data-status="${t.status}"]`));
    await page.keyboard.press('Enter');
    await expect(home.locator(`[data-status="${t.status}"]`)).toHaveAttribute('aria-pressed', 'true');
    expect(await page.evaluate(() => document.activeElement.dataset.status), 'the keyboard stays on the status after the home is drawn again').toBe(t.status);
    await tabTo(page, await at('textarea[name="note"]'));
    await page.keyboard.press('Control+A');
    await page.keyboard.type('Reached with the keyboard.');
    await tabTo(page, await at('[data-f="save"]'));
    await page.keyboard.press('Enter');
    await expect(page.locator('.toast', { hasText: 'Saved.' })).toBeVisible();
    expect(await page.evaluate(() => document.activeElement.dataset.f)).toBe('save');
    page.once('dialog', (d) => d.accept('RELEASE'));
    await tabTo(page, await at('[data-f="release"]'), { back: true });
    await page.keyboard.press('Enter');
    await expect(page.locator('.toast', { hasText: 'Home released.' })).toBeVisible();
    guard.assertClean();
  });
}
