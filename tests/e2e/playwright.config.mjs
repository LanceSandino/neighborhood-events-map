// Playwright config: runs against already-running Docker stacks (see bin/e2e.sh).
import { defineConfig, devices } from '@playwright/test';

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
  // The stacks are reached over plain HTTP on host.docker.internal; treat them as secure origins so
  // HTTPS-only browser features (geolocation, clipboard) behave as they do on the real HTTPS site.
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: 'chromium', launchOptions: { args: [
    `--unsafely-treat-insecure-origin-as-secure=${process.env.E2E_DEMO_URL || 'http://host.docker.internal:8088'},${process.env.E2E_VENTURA_URL || 'http://host.docker.internal:8087'}`,
  ] } } }],
});
