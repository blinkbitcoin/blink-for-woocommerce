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
  private function bolt11(
    int $expirySeconds = 3600,
    ?string $paymentHash = null
  ): string {
    return Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', $paymentHash ?? $this->paymentHash)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', $expirySeconds)
      ->build();
  }

  private function queueSuccessfulInvoice(
    ?string $bolt11 = null,
    ?string $paymentHash = null
  ): void {
    $hash = $paymentHash ?? $this->paymentHash;
    // The conversion is provided by the substituted rate provider.
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'minSendable' => 1000,
      'maxSendable' => 100000000000,
      'commentAllowed' => 255,
      'metadata' => self::METADATA
    ]);
    $this->http->queueJson([
      'pr' => $bolt11 ?? $this->bolt11(3600, $hash),
      'verify' => 'https://blink.sv/verify/' . $hash
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

  public function test_checkout_accepts_a_blink_length_invoice_expiry(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice($this->bolt11(86400));

    $result = $this->gateway->process_payment($order->get_id());

    $this->assertSame('success', $result['result']);
    $stored = $this->repository()->load($this->record($this->reload($order)));
    $this->assertSame(self::NOW + 86400, $stored?->expiresAt);
  }

  public function test_checkout_refuses_an_invoice_above_the_supported_expiry(): void {
    $order = $this->makeOrder();
    $this->queueSuccessfulInvoice($this->bolt11(86401));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Can't create the Lightning invoice");
    $this->gateway->process_payment($order->get_id());
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
   * WooCommerce sets 'failed' when *another* gateway attempt on the same order
   * errors, which says nothing about the Lightning invoice. Tearing the chain
   * down there lost the payment of a customer who had the pay page open in one
   * tab, tried a card in another, then paid the BOLT11 from their phone.
   */
  public function test_a_failure_on_another_gateway_leaves_settlement_running(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $gateway->process_payment($order->get_id());

    $order->update_status('failed');

    $this->assertNotNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      ),
      'the Lightning invoice is still payable, so it is still watched'
    );
  }

  /** A shop manager's decision, by contrast, does stop the checks. */
  public function test_a_manual_cancellation_stops_settlement(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $gateway->process_payment($order->get_id());

    $order->update_status('cancelled');

    $this->assertNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      )
    );
  }

  /**
   * Retrying on a still-valid invoice takes the reuse path, which creates no
   * invoice and so used to schedule nothing of its own. Pay and close the tab
   * at that point and the payment was never credited.
   */
  public function test_retrying_an_unwatched_order_puts_settlement_back_in_place(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $gateway->process_payment($order->get_id());

    // However the chain came to be gone -- a resolved status, a purged queue.
    $scheduler->unscheduleAll(
      SettlementScheduler::HOOK,
      [$order->get_id()],
      SettlementScheduler::GROUP
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
    $scheduler->unscheduleAll(
      SettlementScheduler::HOOK,
      [$order->get_id()],
      SettlementScheduler::GROUP
    );

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

  /**
   * The order was cleared before the replacement was built, so a failure here
   * left it with no payment hash, no verify URL and no account type -- while
   * the previous invoice stayed payable. A customer paying it could never be
   * credited, and the leftover settlement chain no-oped because tick() bails
   * on an order that no longer looks non-custodial.
   */
  public function test_a_failed_replacement_leaves_the_previous_invoice_intact(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 30]);
    // The LNURL server is unreachable, so no replacement can be built.
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    try {
      $this->gateway->process_payment($order->get_id());
      $this->fail('the checkout should have reported the failure');
    } catch (\Exception $e) {
      $this->assertStringContainsString('Lightning invoice', $e->getMessage());
    }

    $stored = $this->repository()->load($this->record($this->reload($order)));
    $this->assertNotNull($stored, 'the payable invoice must still be reachable');
    $this->assertSame($this->paymentHash, $stored->paymentHash);
    $this->assertSame(
      'https://blink.sv/verify/' . $this->paymentHash,
      $stored->verifyUrl
    );
    $this->assertTrue(
      $this->repository()->isNonCustodial($this->record($this->reload($order))),
      'losing this marker is what silences background settlement'
    );
  }

  public function test_a_failed_replacement_leaves_the_settlement_chain_running(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 30]);
    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated(
        $this->record($order),
        $this->repository()->load($this->record($order))
      );
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    try {
      $gateway->process_payment($order->get_id());
    } catch (\Exception $e) {
      // Expected: the replacement could not be built.
    }

    $this->assertNotNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      )
    );
  }

  /** One order-level chain watches both the new and replaced invoice. */
  public function test_a_successful_replacement_keeps_one_schedule_for_all_invoices(): void {
    $scheduler = $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 30]);
    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated(
        $this->record($order),
        $this->repository()->load($this->record($order))
      );
    $newHash = str_repeat('cd', 32);
    $this->queueSuccessfulInvoice(null, $newHash);

    $gateway->process_payment($order->get_id());

    $this->assertCount(
      1,
      array_filter(
        $scheduler->scheduled,
        static fn(array $a): bool => $a['hook'] === SettlementScheduler::HOOK
      ),
      'one order-level chain must watch both invoices'
    );
    $reloaded = $this->record($this->reload($order));
    $this->assertSame($newHash, $this->repository()->load($reloaded)?->paymentHash);
    $this->assertSame(
      [$this->paymentHash],
      array_map(
        static fn($invoice): string => $invoice->paymentHash,
        $this->repository()->outstanding($reloaded)
      )
    );
  }

  public function test_background_settlement_credits_a_replaced_invoice(): void {
    $this->useFakeScheduler();
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 30]);
    $newHash = str_repeat('cd', 32);
    $this->queueSuccessfulInvoice(null, $newHash);
    $gateway->process_payment($order->get_id());
    $this->http->queueJson([
      'settled' => true,
      'preimage' => $this->preimage
    ]);

    $this->services()->settlementScheduler()->tick($this->record($order));

    $reloaded = $this->reload($order);
    $this->assertSame('processing', $reloaded->get_status());
    $this->assertSame($this->paymentHash, $reloaded->get_transaction_id());
  }

  /**
   * A merchant switching the global setting must not strand orders already in
   * flight. Taking the custodial branch here queried the LNURL payment hash
   * through the Blink API, where a null answer reads as "reuse this" and sends
   * the buyer to a Blink-hosted checkout that does not exist.
   */
  public function test_an_existing_order_is_routed_by_its_stored_account_type(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    update_option('blink_account_type', 'custodial');
    update_option('blink_api_key', 'test-key');
    update_option('blink_wallet_type', 'bitcoin');
    $gateway = new BlinkLnGateway();

    $result = $gateway->process_payment($order->get_id());

    $this->assertSame('success', $result['result']);
    $this->assertStringContainsString('order-pay', $result['redirect']);
    $this->assertSame(
      0,
      $this->http->requestCount(),
      'the stored invoice is still payable, so nothing had to be asked'
    );
  }

  /** A released custodial invoice has blink_id but no account-type marker. */
  public function test_a_legacy_custodial_invoice_survives_a_non_custodial_setting_change(): void {
    $gateway = new class extends BlinkLnGateway {
      protected function validInvoiceExists(\WC_Order $order): bool {
        return true;
      }
    };
    $order = $this->makeOrder();
    $order->update_meta_data(InvoiceRepository::PAYMENT_HASH, 'custodial-hash');
    $order->update_meta_data(InvoiceRepository::PAYMENT_REQUEST, 'lnbc1custodial');
    $order->update_meta_data(
      'blink_redirect',
      'https://pay.blink.sv/checkout/custodial-hash'
    );
    $order->save();

    $result = $gateway->process_payment($order->get_id());
    $reloaded = $this->reload($order);

    $this->assertSame('success', $result['result']);
    $this->assertStringContainsString('/checkout/custodial-hash', $result['redirect']);
    $this->assertSame(
      'custodial-hash',
      $reloaded->get_meta(InvoiceRepository::PAYMENT_HASH)
    );
    $this->assertSame('', $reloaded->get_meta(InvoiceRepository::ACCOUNT_TYPE));
    $this->assertSame('', $reloaded->get_meta(InvoiceRepository::VERIFY_URL));
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
