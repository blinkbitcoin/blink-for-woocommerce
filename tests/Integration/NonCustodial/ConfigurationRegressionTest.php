<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\NonCustodial;

use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\Services;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * An order in flight must keep settling against the configuration it was
 * created with.
 *
 * Settlement used to branch on the global account-type setting and derive the
 * SSRF domain from the current lightning address, so changing either one in
 * the WooCommerce settings screen permanently stranded every pending order --
 * in both directions.
 */
final class ConfigurationRegressionTest extends IntegrationTestCase {
  private function runDueAction(int $orderId): void {
    do_action(SettlementScheduler::HOOK, $orderId);
  }

  private function reconfigure(array $options): void {
    foreach ($options as $key => $value) {
      update_option($key, $value);
    }
    // Rebuild the graph the way a later request would.
    Services::replace(new Services());
  }

  public function test_a_pending_order_settles_after_the_shop_switches_to_custodial(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $this->reconfigure([
      'blink_account_type' => 'custodial',
      'blink_api_key' => 'some-key',
      'blink_wallet_type' => 'bitcoin'
    ]);

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame(
      'processing',
      $this->reload($order)->get_status(),
      'the order must settle against its own stored mode'
    );
  }

  public function test_a_pending_order_settles_after_the_address_changes(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, [
      'lnAddress' => 'old@pay.example.com',
      'verifyUrl' => 'https://pay.example.com/verify/' . $this->paymentHash
    ]);

    $this->reconfigure(['blink_ln_address' => 'new@blink.sv']);

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
    $this->assertStringContainsString(
      'pay.example.com',
      (string) $this->http->lastUrl(),
      'the poll must go to the address the invoice was created against'
    );
  }

  public function test_the_stored_address_still_governs_the_ssrf_check(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, [
      'lnAddress' => 'old@pay.example.com',
      // A verify URL from a different domain must still be refused, using the
      // stored address rather than whatever is configured now.
      'verifyUrl' => 'https://attacker.example/verify/' . $this->paymentHash
    ]);

    $this->reconfigure(['blink_ln_address' => 'new@attacker.example']);

    $this->runDueAction($order->get_id());

    $this->assertSame(0, $this->http->requestCount());
    $this->assertSame('pending', $this->reload($order)->get_status());
  }

  public function test_two_orders_on_different_addresses_settle_independently(): void {
    $first = $this->makeOrder();
    $this->storeInvoice($first, [
      'lnAddress' => 'a@blink.sv',
      'verifyUrl' => 'https://blink.sv/verify/' . $this->paymentHash
    ]);

    $second = $this->makeOrder();
    $this->storeInvoice($second, [
      'lnAddress' => 'b@pay.example.com',
      'verifyUrl' => 'https://pay.example.com/verify/' . $this->paymentHash
    ]);

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($first->get_id());
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($second->get_id());

    $this->assertSame('processing', $this->reload($first)->get_status());
    $this->assertSame('processing', $this->reload($second)->get_status());

    $urls = $this->http->urls();
    $this->assertStringContainsString('blink.sv', $urls[0]);
    $this->assertStringContainsString('pay.example.com', $urls[1]);
  }

  public function test_an_order_keeps_its_amount_after_the_shop_currency_changes(): void {
    $order = $this->makeOrder('10.00', 'USD');
    $this->storeInvoice($order);

    $this->reconfigure(['woocommerce_currency' => 'EUR']);

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    // The order's own currency is unchanged, so this is a normal settlement.
    $this->assertSame('processing', $this->reload($order)->get_status());
  }
}
