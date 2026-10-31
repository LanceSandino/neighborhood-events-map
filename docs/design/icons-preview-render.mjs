/**
 * @module docs/design/icons-preview-render
 * @description Renders the icon review sheet (docs/design/icons-preview.html) to full-page PNGs at 1440 px wide, in
 *     light and dark (prefers-color-scheme), with a tiny static server over the repository root so the sheet's
 *     <use href="…/icons.svg#…"> references load as they would on the site. Design tooling only; the app does not use it.
 *     Run it in the Playwright image with the e2e packages installed in the working directory, for example:
 *       docker run --rm -v "$PWD":/work -w /work/tests/e2e mcr.microsoft.com/playwright:v1.64.1-noble \
 *         /bin/bash -c "npm ci --no-audit --no-fund >/dev/null && node /work/docs/design/icons-preview-render.mjs /work"
 *     (playwright is resolved from the working directory). Optional third and later arguments: "page=<path>" to render
 *     another page, "zoom=<selector>|<selector>" to also save close-ups of elements at "scale=<n>" (default 3) for checking small sizes.
 * @dependencies playwright (from the e2e packages), node:http, node:fs, node:path
 */
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { extname, join, normalize } from 'node:path';

const root = process.argv[2] || process.cwd();
const opts = Object.fromEntries(process.argv.slice(3).map((a) => a.split(/=(.*)/s).slice(0, 2)));
const page = opts.page || 'docs/design/icons-preview.html';
const out = opts.out || 'docs/design/icons-preview';
const { chromium } = createRequire(join(process.cwd(), '/'))('playwright');

const TYPES = { '.html': 'text/html', '.css': 'text/css', '.svg': 'image/svg+xml', '.js': 'text/javascript', '.png': 'image/png', '.woff2': 'font/woff2' };
const server = createServer(async (req, res) => {
  const path = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^(\.\.[/\\])+/, '');
  try {
    const body = await readFile(join(root, path));
    res.writeHead(200, { 'Content-Type': TYPES[extname(path)] || 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404); res.end();
  }
});
await new Promise((ok) => server.listen(0, '127.0.0.1', ok));
const base = `http://127.0.0.1:${server.address().port}/`;

const browser = await chromium.launch();
try {
  for (const scheme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: scheme, deviceScaleFactor: 1 });
    const tab = await context.newPage();
    const missing = [];
    tab.on('response', (r) => { if (r.status() >= 400) missing.push(r.url()); });
    await tab.goto(base + page, { waitUntil: 'networkidle' });
    await tab.evaluate(() => document.fonts.ready);
    await tab.screenshot({ path: join(root, `${out}-${scheme}.png`), fullPage: true });
    if (opts.zoom) {
      const zoomed = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: scheme, deviceScaleFactor: Number(opts.scale || 3) });
      const z = await zoomed.newPage();
      await z.goto(base + page, { waitUntil: 'networkidle' });
      for (const [i, sel] of opts.zoom.split('|').entries()) {
        await z.locator(sel).first().screenshot({ path: join(root, `${out}-${scheme}-zoom${i}.png`) });
      }
      await zoomed.close();
    }
    if (missing.length) console.error(`missing: ${missing.join(', ')}`);
    console.log(`${scheme}: ${await tab.evaluate(() => document.documentElement.scrollHeight)} px tall`);
    await context.close();
  }
} finally {
  await browser.close();
  server.close();
}
