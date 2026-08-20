import { test as base, expect, type APIRequestContext } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

/**
 * Helpers for driving the shop and the fake LNURL server.
 *
 * Normal setup and assertions go over HTTP to control endpoints inside the
 * test site. The one exception is the background-settlement acceptance test:
 * it invokes Action Scheduler through WP-CLI to prove the customer page is not
 * what executes settlement.
 */

export const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8889';

export interface SeededOrder {
  ok: boolean;
  orderId: number;
  orderKey: string;
  payUrl: string;
  paymentHash: string;
  satoshis: number;
  status: string;
  error?: string;
}

export interface OrderState {
  status: string;
  paymentHash: string;
  satoshis: number;
  terminal: string;
  notes: string[];
}

export const SettlementMode = {
  BrowserOnly: 'browser_only',
  Hybrid: 'hybrid',
  WorkerOnly: 'worker_only',
} as const;

export type SettlementMode = (typeof SettlementMode)[keyof typeof SettlementMode];

export const WooOrderStatus = {
  Pending: 'pending',
  Processing: 'processing',
} as const;

export const TerminalSettlementStatus = {
  Paid: 'PAID',
} as const;

const SETTLEMENT_MODE_FIELD = 'blink_e2e_settlement_mode';
const SETTLEMENT_ACTION_HOOK = 'blink_settle_noncustodial';

/**
 * Creates an order and takes it through the real gateway.
 *
 * The previous harness created a bare order and never ran process_payment, so
 * no invoice existed, the pay page rendered nothing, and the specs asserting
 * "no invoice was created" passed for entirely the wrong reason.
 */
export async function seedOrder(
  request: APIRequestContext,
  total = '10.00',
  identifier = 'ok',
): Promise<SeededOrder> {
  const params = new URLSearchParams({ total, identifier });
  const response = await request.post(`${BASE}/blink-e2e/control/order?${params}`);
  expect(response.ok()).toBeTruthy();

  const order = (await response.json()) as SeededOrder;
  expect(order.ok, `seeding failed: ${order.error ?? 'unknown'}`).toBeTruthy();
  expect(order.paymentHash, 'the gateway did not create an invoice').not.toBe('');

  return order;
}

export async function orderState(
  request: APIRequestContext,
  orderId: number,
): Promise<OrderState> {
  const response = await request.get(
    `${BASE}/blink-e2e/control/order-state?id=${orderId}`,
  );
  expect(response.ok()).toBeTruthy();

  return (await response.json()) as OrderState;
}

/** Marks an invoice paid on the fake LNURL server. */
export async function settle(
  request: APIRequestContext,
  paymentHash: string,
): Promise<void> {
  const response = await request.post(
    `${BASE}/blink-e2e/control/settle?hash=${paymentHash}`,
  );
  expect(response.ok()).toBeTruthy();
}

export async function setSettlementMode(
  request: APIRequestContext,
  mode: SettlementMode,
): Promise<void> {
  const response = await request.post(
    `${BASE}/blink-e2e/control/settings?${SETTLEMENT_MODE_FIELD}=${mode}`,
  );
  expect(response.ok()).toBeTruthy();
  expect(
    ((await response.json()) as Record<typeof SETTLEMENT_MODE_FIELD, SettlementMode>)[
      SETTLEMENT_MODE_FIELD
    ],
  ).toBe(mode);
}

export async function resetHarness(request: APIRequestContext): Promise<void> {
  const response = await request.post(`${BASE}/blink-e2e/control/reset`);
  expect(response.ok()).toBeTruthy();
}

/** Runs due settlement work through Action Scheduler's real WP-CLI runner. */
export async function runScheduler(): Promise<void> {
  const wpCoreDir =
    process.env.WP_CORE_DIR ?? `${process.env.TMPDIR ?? '/tmp'}/wordpress`;
  await promisify(execFile)(
    process.env.WP_CLI_BIN ?? 'wp',
    [
      'action-scheduler',
      'run',
      `--hooks=${SETTLEMENT_ACTION_HOOK}`,
      '--batches=0',
      `--path=${wpCoreDir}`,
    ],
    { timeout: 20_000 },
  );
}

export const test = base.extend({});

export { expect };
