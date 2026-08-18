import { test as base, expect, type APIRequestContext } from '@playwright/test';

/**
 * Helpers for driving the shop and the fake LNURL server.
 *
 * Everything goes over HTTP to control endpoints inside the test site. The
 * previous version shelled out to a WP-CLI container for every read and write
 * -- ten call sites, each paying process startup and a full WordPress
 * bootstrap, eight to twelve times per spec.
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
): Promise<SeededOrder> {
  const response = await request.post(`${BASE}/blink-e2e/control/order?total=${total}`);
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

/** Runs whatever settlement work is due, as the queue would. */
export async function runScheduler(request: APIRequestContext): Promise<number> {
  const response = await request.post(`${BASE}/blink-e2e/control/run-scheduler`);
  expect(response.ok()).toBeTruthy();

  return ((await response.json()) as { ran: number }).ran;
}

export const test = base.extend({});

export { expect };
