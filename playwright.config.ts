import { defineConfig, devices } from '@playwright/test';

/**
 * The site under test is the wp-env "tests" instance on port 8889.
 *
 * That port matters: it makes the fake LNURL server reachable at
 * http://localhost:8889, which the plugin classifies as a local development
 * host. The address configured in these tests is therefore local too, so the
 * real local-dev branch of the URL policy is exercised rather than bypassed.
 */
export default defineConfig({
  testDir: './tests/e2e/specs',
  outputDir: './build/test-results',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: './build/playwright-report', open: 'never' }],
  ],
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8889',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
