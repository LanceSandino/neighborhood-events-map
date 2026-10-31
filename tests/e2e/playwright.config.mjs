// Playwright config: runs against already-running Docker stacks (see bin/e2e.sh): the Ventura example, the fictional
// Halloween demo, the fictional Christmas lights demo (E2E_LIGHTS_URL) and the fictional garage sale demo (E2E_SALE_URL).
import { defineConfig, devices } from '@playwright/test';

// The stacks are reached over plain HTTP on host.docker.internal; treat them as secure origins so
// HTTPS-only browser features (geolocation, clipboard) behave as they do on the real HTTPS site.
const chromium = { ...devices['Desktop Chrome'], channel: 'chromium', launchOptions: { args: [
  `--unsafely-treat-insecure-origin-as-secure=${process.env.E2E_DEMO_URL || 'http://host.docker.internal:8088'},${process.env.E2E_VENTURA_URL || 'http://host.docker.internal:8087'},${process.env.E2E_LIGHTS_URL || 'http://host.docker.internal:8089'},${process.env.E2E_SALE_URL || 'http://host.docker.internal:8090'}`,
] } };

export default defineConfig({
  testDir: './specs',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  workers: 1,
  reporter: [['list']],
  outputDir: './test-results',
  use: {
    baseURL: process.env.E2E_DEMO_URL || 'http://host.docker.internal:8088',
    trace: 'retain-on-failure',
    serviceWorkers: 'block',
  },
  projects: [{ name: 'chromium', testIgnore: /(season|events)\.spec\.mjs/, use: chromium },
  // Safari's engine (WebKit) on an iPhone-sized screen for the main visitor flows.
  { name: 'webkit-iphone', testMatch: /public\.spec\.mjs/, use: { ...devices['iPhone 15 Pro'] } },
  // Closing the demo's event ends its season, and the several-events spec closes and archives events on the lights
  // demo, so they run after every other test; bin/e2e.sh lists the fictional homes again for the default events and
  // brings the lights demo's next Halloween back afterwards.
  { name: 'season', testMatch: /(season|events)\.spec\.mjs/, dependencies: ['chromium', 'webkit-iphone'], use: chromium }],
});
