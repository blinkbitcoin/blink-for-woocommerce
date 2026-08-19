import {
  expect,
  orderState,
  resetHarness,
  runScheduler,
  seedOrder,
  SettlementMode,
  setSettlementMode,
  settle,
  TerminalSettlementStatus,
  test,
  WooOrderStatus,
} from '../fixtures/blink';

/**
 * The browser layer.
 *
 * Deliberately small. Eighteen specs used to live here, and an audit found
 * every one duplicated a PHP or JavaScript test -- while the seam a browser
 * suite actually exists for went untested. These three cover only what cannot
 * be proven without a real page: that the scripts load and execute in the
 * right order, that the vendored QR library renders, and that a real poll
 * against admin-ajax navigates the customer.
 */
test.describe('the pay page', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
    await setSettlementMode(request, SettlementMode.Hybrid);
  });

  test.afterEach(async ({ request }) => {
    await setSettlementMode(request, SettlementMode.Hybrid);
  });

  test('renders a scannable invoice from the payload it was given', async ({
    page,
    request,
  }) => {
    const order = await seedOrder(request);

    await page.goto(order.payUrl);

    // The vendored qrcode library is stubbed in every other suite; this is the
    // only place it actually runs.
    await expect(page.locator('#blink-pay-qr svg')).toBeVisible();

    // The payload reached the browser, and its shape matches what the script
    // reads. A key rename here breaks the page while leaving PHP tests green.
    const payload = await page.evaluate(() => window.BlinkPay);
    expect(payload).toBeTruthy();
    expect(Object.keys(payload).sort()).toEqual(
      [
        'ajaxUrl',
        'deadline',
        'i18n',
        'lightningUri',
        'nonce',
        'orderId',
        'orderKey',
        'paymentRequest',
        'pollInterval',
        'redirectUrl',
      ].sort(),
    );

    expect(payload.orderId).toBe(order.orderId);
    expect(payload.orderKey).toBe(order.orderKey);
    expect(typeof payload.deadline).toBe('number');

    // The seeded order is 10.00 and the harness fixes the rate at 100,000
    // sat per unit, so the invoice is for exactly 1,000,000 sat -- "10m" in
    // BOLT11's notation. This is the assertion that keeps the suite hermetic:
    // if the rate provider ever reaches the real Blink API again, the amount
    // moves with the market and this fails.
    expect(payload.paymentRequest).toMatch(/^lnbc10m1/);

    // Alphanumeric QR mode needs uppercase, and it is what the encoder was fed.
    const encoded = await page.locator('#blink-pay-qr').getAttribute('data-uri');
    expect(encoded).toBe(payload.lightningUri);
    expect(encoded).toBe(encoded?.toUpperCase().replace('LIGHTNING:', 'lightning:'));

    // Every element the script looks up is present on the real page.
    for (const id of [
      'blink-pay-qr',
      'blink-pay-bolt11',
      'blink-pay-copy',
      'blink-pay-status',
    ]) {
      await expect(page.locator(`#${id}`)).toHaveCount(1);
    }
  });

  test('redirects the customer when the payment arrives', async ({ page, request }) => {
    const order = await seedOrder(request);
    await page.goto(order.payUrl);
    await expect(page.locator('#blink-pay-qr svg')).toBeVisible();

    // From here the browser does the work: its own poll, with the nonce the
    // page minted, against the real admin-ajax endpoint.
    await settle(request, order.paymentHash);

    await expect(page).toHaveURL(/order-received|order-pay.*key=/, { timeout: 30_000 });
    await expect
      .poll(async () => (await orderState(request, order.orderId)).status, {
        timeout: 30_000,
      })
      .toBe(WooOrderStatus.Processing);
  });

  test('reproduces browser-only failure and proves the worker-only fix', async ({
    page,
    request,
    context,
  }) => {
    test.setTimeout(75_000);

    await setSettlementMode(request, SettlementMode.BrowserOnly);
    const browserOnlyOrder = await seedOrder(request);
    const browserOnlyPage = await context.newPage();
    await browserOnlyPage.goto(browserOnlyOrder.payUrl);
    await expect(browserOnlyPage.locator('#blink-pay-qr svg')).toBeVisible();
    await browserOnlyPage.close();
    await settle(request, browserOnlyOrder.paymentHash);

    await setSettlementMode(request, SettlementMode.WorkerOnly);
    const workerOnlyOrder = await seedOrder(request, '11.00');
    const workerOnlyPage = await context.newPage();
    await workerOnlyPage.goto(workerOnlyOrder.payUrl);
    await expect(workerOnlyPage.locator('#blink-pay-qr svg')).toBeVisible();
    await workerOnlyPage.close();
    await settle(request, workerOnlyOrder.paymentHash);

    // The first action is jittered between 15 and 25 seconds after invoice
    // creation. Wait until it is due, then let the real WP-CLI queue runner
    // discover and execute it without reopening either customer page.
    await new Promise((resolve) => setTimeout(resolve, 26_000));

    // DISABLE_WP_CRON is set in this test site. Time passing and status reads
    // therefore cannot accidentally execute the queue and hide the reported
    // failure: both closed-page orders are still pending before the store-side
    // runner is invoked.
    expect((await orderState(request, browserOnlyOrder.orderId)).status).toBe(
      WooOrderStatus.Pending,
    );
    expect((await orderState(request, workerOnlyOrder.orderId)).status).toBe(
      WooOrderStatus.Pending,
    );

    await runScheduler();

    const reproduced = await orderState(request, browserOnlyOrder.orderId);
    expect(reproduced.status).toBe(WooOrderStatus.Pending);
    expect(reproduced.terminal).toBe('');

    const fixed = await orderState(request, workerOnlyOrder.orderId);
    expect(fixed.status).toBe(WooOrderStatus.Processing);
    expect(fixed.terminal).toBe(TerminalSettlementStatus.Paid);

    // Returning to the pay page must not ask them to pay again. WooCommerce
    // itself refuses to render a payment form for an order that is no longer
    // payable, which is why renderPayPage()'s own redirect never fires -- the
    // receipt hook is not reached at all.
    await page.goto(workerOnlyOrder.payUrl);
    await expect(page.locator('#blink-pay-qr')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('cannot be paid for');
  });
});
