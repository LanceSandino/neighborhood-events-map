// Automated WCAG 2.1 A/AA checks (axe-core) on every page type, in light and dark appearance.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { DEMO, VENTURA, politeTiles } from './helpers.mjs';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const pages = [
  ['map', DEMO + '/'],
  ['register sheet', DEMO + '/#list-my-house'],
  ['route sheet', DEMO + '/#route'],
  ['sign in', DEMO + '/login'],
  ['privacy', DEMO + '/privacy'],
  ['terms', DEMO + '/terms'],
  ['accessibility', DEMO + '/accessibility'],
  ['real-plat map (listings closed)', VENTURA + '/'],
];

for (const scheme of ['light', 'dark']) {
  for (const [name, url] of pages) {
    test(`no WCAG 2.1 AA violations: ${name} (${scheme})`, async ({ browser }) => {
      const ctx = await browser.newContext({ colorScheme: scheme, viewport: { width: 1280, height: 900 } });
      const page = await ctx.newPage();
      await politeTiles(page);
      await page.goto(url);
      await page.waitForTimeout(800);
      // Leaflet's own map panes are a visual duplicate of the list (documented limitation).
      const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('.leaflet-pane').exclude('.leaflet-control-container').analyze();
      const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
      expect(summary, summary.join('\n')).toEqual([]);
      await ctx.close();
    });
  }
}
