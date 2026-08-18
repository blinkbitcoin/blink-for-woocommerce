import {
  createOrder,
  expect,
  failVerify,
  orderMeta,
  orderStatus,
  requestLog,
  runScheduler,
  settle,
  test,
  useLightningAddress,
} from '../fixtures/blink';

/**
 * The customer-facing flow, against a real WordPress with a real LNURL server
 * on the other end.
 */
test.describe('non-custodial checkout', () => {
  test.beforeEach(async () => {
    await useLightningAddress('ok');
  });

  test('the pay page shows a QR code and an invoice', async ({ page }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));

    await expect(page.locator('#blink-pay-qr svg')).toBeVisible();
    await expect(page.locator('#blink-pay-bolt11')).toContainText(/^lnbc/);
    await expect(page.locator('.blink-pay-amount')).toContainText('sats');
  });

  test('the customer is redirected once the payment settles', async ({ page }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));
    await expect(page.locator('#blink-pay-qr svg')).toBeVisible();

    await settle(page, await orderMeta(orderId, 'blink_id'));

    await expect(page).toHaveURL(/order-received/, { timeout: 30_000 });
    expect(await orderStatus(orderId)).toBe('processing');
  });

  /**
   * The defect this whole feature turned on: with no webhook and no scheduled
   * job, settlement only ever happened while the tab was open.
   */
  test('an order settles after the customer closes the page', async ({ page, context }) => {
    const orderId = await createOrder();
    const payPage = await context.newPage();
    await payPage.goto(await payUrl(orderId));
    await expect(payPage.locator('#blink-pay-qr svg')).toBeVisible();

    const paymentHash = await orderMeta(orderId, 'blink_id');
    await payPage.close();

    await settle(page, paymentHash);
    await runScheduler();

    expect(await orderStatus(orderId)).toBe('processing');
  });

  test('the invoice is bound to the order amount', async ({ page }) => {
    const orderId = await createOrder('25.00');
    await page.goto(await payUrl(orderId));

    const satoshis = Number(await orderMeta(orderId, '_blink_satoshis'));
    expect(satoshis).toBeGreaterThan(0);
    await expect(page.locator('.blink-pay-amount')).toContainText(
      satoshis.toLocaleString('en-US')
    );
  });

  test('reloading the page does not create a second invoice', async ({ page }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));
    const first = await orderMeta(orderId, 'blink_id');

    await page.reload();

    expect(await orderMeta(orderId, 'blink_id')).toBe(first);
  });

  test('many open tabs do not multiply outbound requests', async ({ page, context }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));
    const paymentHash = await orderMeta(orderId, 'blink_id');

    const tabs = await Promise.all([context.newPage(), context.newPage(), context.newPage()]);
    await Promise.all(tabs.map((tab) => tab.goto(payUrlFor(orderId))));
    await page.waitForTimeout(12_000);

    const verifies = (await requestLog(page)).filter((entry) =>
      entry.what === `verify:${paymentHash}`
    );

    // Four tabs polling every ~3s for 12s would be well over a dozen requests
    // without the cache and the single-flight lock.
    expect(verifies.length).toBeLessThanOrEqual(3);
  });

  test('a paid order is not re-processed', async ({ page }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));
    await settle(page, await orderMeta(orderId, 'blink_id'));
    await expect(page).toHaveURL(/order-received/, { timeout: 30_000 });

    await runScheduler();
    await runScheduler();

    expect(await orderStatus(orderId)).toBe('processing');
  });

  /**
   * A verify endpoint that is unreachable says nothing about whether the
   * customer paid, so the order must survive the outage.
   */
  test('an outage does not cancel the order, and it settles afterwards', async ({ page }) => {
    const orderId = await createOrder();
    await page.goto(await payUrl(orderId));
    const paymentHash = await orderMeta(orderId, 'blink_id');

    await failVerify(page, paymentHash, 'http-500');
    await runScheduler();
    await runScheduler();
    expect(await orderStatus(orderId)).toBe('pending');

    await page.request.post(`${process.env.WP_BASE_URL ?? 'http://localhost:8889'}/blink-e2e/control/reset`);
    await settle(page, paymentHash);
    await runScheduler();

    expect(await orderStatus(orderId)).toBe('processing');
  });
});

test.describe('addresses the plugin refuses', () => {
  /**
   * Each of these is a scenario the fake server plays; a failure here means a
   * check that should be fail-closed is letting something through.
   *
   * @see docs/security/ssrf-policy.md
   */
  const refused = [
    ['a verify URL on someone else’s domain', 'foreign-verify'],
    ['a plain-HTTP verify URL', 'insecure-callback'],
    ['an invoice for the wrong amount', 'wrong-amount'],
    ['an invoice that expires immediately', 'short-expiry'],
    ['an address that does not offer LNURL-pay', 'not-payrequest'],
    ['a server returning an error', 'error-body'],
    ['a server returning malformed JSON', 'malformed'],
    ['a server returning a redirect', 'redirect'],
    ['an amount below the address minimum', 'below-min'],
    ['an amount above the address maximum', 'above-max'],
  ] as const;

  for (const [description, scenario] of refused) {
    test(`checkout refuses ${description}`, async ({ page }) => {
      await useLightningAddress(scenario);
      const orderId = await createOrder();

      await page.goto(payUrlFor(orderId));

      // No invoice may be stored, whatever the page ends up showing.
      expect(await orderMeta(orderId, 'blink_id')).toBe('');
      expect(await orderStatus(orderId)).toBe('pending');
    });
  }
});

function payUrlFor(orderId: number): string {
  const base = process.env.WP_BASE_URL ?? 'http://localhost:8889';

  return `${base}/?page_id=0&order-pay=${orderId}&pay_for_order=true&key=`;
}

/** Builds the order-pay URL, including the order key WooCommerce requires. */
async function payUrl(orderId: number): Promise<string> {
  const { wpCli } = await import('../fixtures/blink');
  const url = await wpCli(
    `eval "echo wc_get_order(${orderId})->get_checkout_payment_url(true);"`
  );

  return url.trim();
}
