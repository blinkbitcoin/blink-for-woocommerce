import { test as base, expect, type Page } from '@playwright/test';

/**
 * Helpers for driving the shop and the fake LNURL server.
 *
 * Everything goes through WP-CLI or the fake server's control endpoints rather
 * than the admin UI, so a spec fails because the payment flow broke rather than
 * because a settings screen was restyled.
 */

export const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8889';

/** Runs a WP-CLI command inside the wp-env test container. */
export async function wpCli(args: string): Promise<string> {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const run = promisify(execFile);

  const { stdout } = await run(
    'npx',
    ['wp-env', 'run', 'tests-cli', '--', 'wp', ...args.split(' ')],
    { maxBuffer: 10 * 1024 * 1024 }
  );

  return stdout.trim();
}

export async function setOption(name: string, value: string): Promise<void> {
  await wpCli(`option update ${name} ${value}`);
}

/** Points the shop at one of the fake server's scenarios. */
export async function useLightningAddress(scenario: string): Promise<void> {
  await setOption('blink_account_type', 'non_custodial');
  await setOption('blink_ln_address', `${scenario}@localhost:8889`);
  await setOption('blink_env', 'blink');
  await setOption('blink_debug', 'yes');
}

export async function resetFakeServer(page: Page): Promise<void> {
  await page.request.post(`${BASE}/blink-e2e/control/reset`);
}

export async function settle(page: Page, paymentHash: string): Promise<void> {
  const response = await page.request.post(
    `${BASE}/blink-e2e/control/settle?hash=${paymentHash}`
  );
  expect(response.ok()).toBeTruthy();
}

export async function failVerify(
  page: Page,
  paymentHash: string,
  mode: 'http-500' | 'not-found' | 'timeout' = 'http-500'
): Promise<void> {
  await page.request.post(`${BASE}/blink-e2e/control/fail?hash=${paymentHash}&mode=${mode}`);
}

export async function requestLog(page: Page): Promise<Array<{ what: string }>> {
  const response = await page.request.get(`${BASE}/blink-e2e/control/requests`);
  const body = await response.json();

  return body.requests ?? [];
}

/** Creates a pending order priced in a way the fake server will accept. */
export async function createOrder(total = '10.00'): Promise<number> {
  const id = await wpCli(
    `wc shop_order create --status=pending --user=1 --porcelain --currency=USD`
  );
  const orderId = Number(id.split('\n').pop());
  await wpCli(`wc shop_order update ${orderId} --user=1 --total=${total}`);

  return orderId;
}

export async function orderStatus(orderId: number): Promise<string> {
  return (await wpCli(`wc shop_order get ${orderId} --user=1 --field=status`)).trim();
}

export async function orderMeta(orderId: number, key: string): Promise<string> {
  return (await wpCli(`post meta get ${orderId} ${key}`)).trim();
}

/** Runs any settlement checks that are due, the way the queue would. */
export async function runScheduler(): Promise<void> {
  await wpCli('action-scheduler run --group=blink');
}

export const test = base.extend({
  page: async ({ page }, use) => {
    await resetFakeServer(page);
    await use(page);
  },
});

export { expect };
