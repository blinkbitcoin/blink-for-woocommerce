import { expect, orderState, runScheduler, seedOrder, settle, test } from '../fixtures/blink';

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
  test('renders a scannable invoice from the payload it was given', async ({ page, request }) => {
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
      ].sort()
    );

    expect(payload.orderId).toBe(order.orderId);
    expect(payload.orderKey).toBe(order.orderKey);
    expect(typeof payload.deadline).toBe('number');

    // Alphanumeric QR mode needs uppercase, and it is what the encoder was fed.
    const encoded = await page.locator('#blink-pay-qr').getAttribute('data-uri');
    expect(encoded).toBe(payload.lightningUri);
    expect(encoded).toBe(encoded?.toUpperCase().replace('LIGHTNING:', 'lightning:'));

    // Every element the script looks up is present on the real page.
    for (const id of ['blink-pay-qr', 'blink-pay-bolt11', 'blink-pay-copy', 'blink-pay-status']) {
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
      .poll(async () => (await orderState(request, order.orderId)).status, { timeout: 30_000 })
      .toBe('processing');
  });

  test('reflects a payment made after the customer closed the page', async ({
    page,
    request,
    context,
  }) => {
    const order = await seedOrder(request);

    const payPage = await context.newPage();
    await payPage.goto(order.payUrl);
    await expect(payPage.locator('#blink-pay-qr svg')).toBeVisible();
    await payPage.close();

    await settle(request, order.paymentHash);
    expect(await runScheduler(request)).toBeGreaterThan(0);

    const state = await orderState(request, order.orderId);
    expect(state.status).toBe('processing');
    expect(state.terminal).toBe('PAID');

    // Returning to the pay page must not ask them to pay again. WooCommerce
    // itself refuses to render a payment form for an order that is no longer
    // payable, which is why renderPayPage()'s own redirect never fires -- the
    // receipt hook is not reached at all.
    await page.goto(order.payUrl);
    await expect(page.locator('#blink-pay-qr')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('cannot be paid for');
  });
});
