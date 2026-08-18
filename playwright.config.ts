import { defineConfig, devices } from '@playwright/test';

/**
 * The site is a plain WordPress install served by PHP's built-in server, set
 * up by bin/install-e2e-site.sh. No Docker.
 *
 * The port matters: it makes the fake LNURL server reachable at
 * http://localhost:8889, which the plugin classifies as a local development
 * host. The address configured in these tests is therefore local too, so the
 * real local-dev branch of the URL policy is exercised rather than bypassed.
 */
export default defineConfig({
  testDir: './tests/e2e/specs',
  outputDir: './build/test-results',
  // Tight enough that a hang fails fast instead of burning a minute twice.
  timeout: 30_000,
  expect: { timeout: 10_000 },
  // Safe now that the specs sharing mutable shop settings are gone: each seeds
  // its own order and touches nothing global.
  fullyParallel: true,
  workers: 4,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: './build/playwright-report', open: 'never' }],
  ],
  // Starts the site itself, so a cold or wrong-state server fails immediately
  // rather than as a wall of timeouts.
  webServer: {
    command: 'bin/serve-e2e-site.sh',
    url: process.env.WP_BASE_URL ?? 'http://localhost:8889',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8889',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
