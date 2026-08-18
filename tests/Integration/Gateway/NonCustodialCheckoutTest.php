<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Gateway;

use Blink\WC\Gateway\BlinkLnGateway;
use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\Tests\Support\Bolt11Encoder;
use Blink\WC\Tests\Support\IntegrationTestCase;

final class NonCustodialCheckoutTest extends IntegrationTestCase {
  private const METADATA = '[["text/plain","Blink order"]]';

  private BlinkLnGateway $gateway;

  public function set_up() {
    parent::set_up();
    $this->gateway = new BlinkLnGateway();
  }

  /** 10,000 sat = 10,000,000 msat = 100u. */
  private function bolt11(int $expirySeconds = 3600): string {
    return Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', $this->paymentHash)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', $expirySeconds)
      ->build();
  }

  private function queueSuccessfulInvoice(?string $bolt11 = null): void {
    // The conversion is provided by the substituted rate provider.
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'minSendable' => 1000,
      'maxSendable' => 100000000000,
      'commentAllowed' => 255,
      'metadata' => self::METADATA,
    ]);
    $this->http->queueJson([
      'pr' => $bolt11 ?? $this->bolt11(),
      'verify' => 'https://blink.sv/verify/' . $this->paymentHash,
    ]);
  }

  public function test_checkout_creates_an_invoice_and_sends_the_buyer_to_the_pay_page(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice();

    $result = $this->gateway->process_payment($order->get_id());

    $this->assertSame('success', $result['result']);
    $this->assertStringContainsString('order-pay', $result['redirect']);
  }

  public function test_the_created_invoice_is_stored_against_the_order(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice();

    $this->gateway->process_payment($order->get_id());

    $stored = $this->repository()->load($this->record($this->reload($order)));
    $this->assertNotNull($stored);
    $this->assertSame($this->paymentHash, $stored->paymentHash);
    $this->assertSame('shop@blink.sv', $stored->lnAddress);
    $this->assertSame(10000, $stored->satoshis);
  }

  /** Expiry follows the invoice, not the hour that was asked for. */
  public function test_the_stored_expiry_comes_from_the_invoice(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice($this->bolt11(900));

    $this->gateway->process_payment($order->get_id());

    $stored = $this->repository()->load($this->record($this->reload($order)));
    $this->assertSame(self::NOW + 900, $stored?->expiresAt);
  }

  public function test_checkout_schedules_background_settlement(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice();

    $gateway->process_payment($order->get_id());

    $this->assertNotNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      ),
      'an order must not depend on the pay page staying open'
    );
  }

  public function test_checkout_fails_visibly_when_the_address_refuses(): void {
    $order = $this->makeOrder();
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'wallet unavailable']);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Can't create the Lightning invoice");
    $this->gateway->process_payment($order->get_id());
  }

  public function test_checkout_refuses_an_invoice_for_the_wrong_amount(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice(
      Bolt11Encoder::create('lnbc1u')
        ->timestamp(self::NOW)
        ->tagHex('p', $this->paymentHash)
        ->tagHex('h', hash('sha256', self::METADATA))
        ->build()
    );

    $this->expectException(\Exception::class);
    $this->gateway->process_payment($order->get_id());
  }

  public function test_an_unknown_order_is_rejected_rather_than_dereferenced(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Could not load order');
    $this->gateway->process_payment(999999);
  }

  // -------------------------------------------------------------- reuse

  public function test_a_still_valid_invoice_is_reused_without_any_request(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $result = $this->gateway->process_payment($order->get_id());

    $this->assertSame('success', $result['result']);
    $this->assertSame(
      0,
      $this->http->requestCount(),
      'reuse must not put a third-party request on the checkout path'
    );
  }

  /**
   * The defect, from the customer's side. An order that failed elsewhere has
   * its checks torn down by the status hook; retrying on a still-valid invoice
   * takes the reuse path, which used to schedule nothing. Pay and close the
   * tab at that point and the payment was never credited.
   */
  public function test_retrying_a_failed_order_puts_settlement_back_in_place(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $gateway->process_payment($order->get_id());

    // The real hook removes the chain on the way to failed.
    $order->update_status('failed');
    $this->assertNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      ),
      'precondition: the failure really did tear the schedule down'
    );

    $gateway->process_payment($order->get_id());

    $this->assertSame(
      0,
      $this->http->requestCount(),
      'the invoice is still reused; nothing new is created'
    );
    $this->assertNotNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      ),
      'a reused invoice must be watched again'
    );
  }

  /**
   * The usual way back to a live invoice is a GET -- an emailed pay link or a
   * reloaded tab -- which never reaches process_payment() at all.
   */
  public function test_rendering_the_pay_page_puts_settlement_back_in_place(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $order->update_status('failed');

    ob_start();
    $gateway->renderPayPage($order->get_id());
    ob_end_clean();

    $this->assertNotNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      )
    );
  }

  public function test_an_invoice_close_to_expiry_is_replaced(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 30]);
    $this->queueSuccessfulInvoice();

    $this->gateway->process_payment($order->get_id());

    $this->assertGreaterThan(0, $this->http->requestCount());
  }

  public function test_an_order_edited_since_invoicing_gets_a_fresh_invoice(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $order->set_total('25.00');
    $order->save();
    $this->queueSuccessfulInvoice();

    $this->gateway->process_payment($order->get_id());

    $this->assertGreaterThan(0, $this->http->requestCount());
  }

  public function test_a_resolved_order_is_not_reused(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->repository()->markTerminal(
      $this->record($order),
      \Blink\WC\NonCustodial\SettlementStatus::Expired
    );
    $this->queueSuccessfulInvoice();

    $this->gateway->process_payment($order->get_id());

    $this->assertGreaterThan(0, $this->http->requestCount());
  }

  /**
   * The previous implementation made a verify request here and, because a
   * transport error surfaced as PENDING, treated an unreachable server as
   * proof the invoice was still good.
   */
  public function test_reuse_does_not_consult_the_verify_endpoint(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    $result = $this->gateway->process_payment($order->get_id());

    $this->assertSame('success', $result['result']);
    $this->assertSame(0, $this->http->requestCount());
  }

  // ------------------------------------------------------------- pay page

  public function test_the_pay_page_renders_the_invoice(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    $html = (string) ob_get_clean();

    $this->assertStringContainsString('blink-pay-qr', $html);
    $this->assertStringContainsString('lnbc100u1xyz', $html);
    $this->assertStringContainsString('10,000 sats', $html);
  }

  public function test_the_pay_page_ignores_a_custodial_order(): void {
    $order = $this->makeOrder();

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    $html = (string) ob_get_clean();

    $this->assertSame('', $html);
  }

  public function test_the_pay_page_explains_itself_when_the_invoice_is_missing(): void {
    $order = $this->makeOrder();
    $order->update_meta_data(
      InvoiceRepository::ACCOUNT_TYPE,
      InvoiceRepository::ACCOUNT_TYPE_NON_CUSTODIAL
    );
    $order->save();

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    $html = (string) ob_get_clean();

    $this->assertStringContainsString('Could not load the Lightning invoice', $html);
  }

  public function test_the_pay_page_ignores_an_unknown_order(): void {
    ob_start();
    $this->gateway->renderPayPage(999999);

    $this->assertSame('', (string) ob_get_clean());
  }

  /**
   * The deadline must reach the browser as a number. Passed through
   * wp_localize_script it arrived as a string, so the client's type check
   * never matched and the page polled indefinitely.
   */
  public function test_the_deadline_reaches_the_browser_as_a_number(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    ob_end_clean();

    $inline = wp_scripts()->get_data('blink-pay', 'before');
    $payload = is_array($inline) ? implode("\n", $inline) : (string) $inline;

    $this->assertMatchesRegularExpression('/"deadline":\s*\d+/', $payload);
    $this->assertStringNotContainsString('"deadline":"', $payload);
  }

  /**
   * The old 30-minute cap was shorter than the 60-minute invoice, so a
   * customer returning at minute 31 was told the invoice had expired while the
   * QR code on screen was still payable.
   */
  public function test_the_deadline_covers_the_whole_invoice_lifetime(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 3600]);

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    ob_end_clean();

    $inline = wp_scripts()->get_data('blink-pay', 'before');
    $payload = is_array($inline) ? implode("\n", $inline) : (string) $inline;
    preg_match('/"deadline":\s*(\d+)/', $payload, $m);

    $this->assertGreaterThanOrEqual(self::NOW + 3600, (int) $m[1]);
  }

  public function test_the_pay_page_ships_translated_strings(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    ob_start();
    $this->gateway->renderPayPage($order->get_id());
    ob_end_clean();

    $inline = wp_scripts()->get_data('blink-pay', 'before');
    $payload = is_array($inline) ? implode("\n", $inline) : (string) $inline;

    $this->assertStringContainsString('"i18n"', $payload);
    $this->assertStringContainsString('Copy invoice', $payload);
  }
}
