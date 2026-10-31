// Playwright config: runs against already-running Docker stacks (see bin/e2e.sh).
import { defineConfig, devices } from '@playwright/test';

// The stacks are reached over plain HTTP on host.docker.internal; treat them as secure origins so
// HTTPS-only browser features (geolocation, clipboard) behave as they do on the real HTTPS site.
const chromium = { ...devices['Desktop Chrome'], channel: 'chromium', launchOptions: { args: [
  `--unsafely-treat-insecure-origin-as-secure=${process.env.E2E_DEMO_URL || 'http://host.docker.internal:8088'},${process.env.E2E_VENTURA_URL || 'http://host.docker.internal:8087'}`,
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
  projects: [{ name: 'chromium', testIgnore: /season\.spec\.mjs/, use: chromium },
  // Safari's engine (WebKit) on an iPhone-sized screen for the main visitor flows.
  { name: 'webkit-iphone', testMatch: /public\.spec\.mjs/, use: { ...devices['iPhone 15 Pro'] } },
  // Switching the demo's event ends its season, so it runs after every other test; bin/e2e.sh lists the fictional
  // homes again for the new event afterwards.
  { name: 'season', testMatch: /season\.spec\.mjs/, dependencies: ['chromium', 'webkit-iphone'], use: chromium }],
});
