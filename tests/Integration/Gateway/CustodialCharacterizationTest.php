<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Gateway;

use Blink\WC\Gateway\BlinkLnGateway;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * Pins the custodial flow's behaviour so the refactor cannot quietly change it.
 *
 * The non-custodial work shares three seams with custodial orders --
 * OrderStatusApplier, the invoice lookup, and process_payment's guards -- and
 * these are the assertions that say what "unchanged" means.
 */
final class CustodialCharacterizationTest extends IntegrationTestCase {
  private BlinkLnGateway $gateway;

  public function set_up() {
    parent::set_up();
    update_option('blink_account_type', 'custodial');
    update_option('blink_api_key', 'test-key');
    update_option('blink_wallet_type', 'bitcoin');
    delete_option('blink_ln_address');

    \Blink\WC\Services::replace(new \Blink\WC\Services());
    $this->gateway = new BlinkLnGateway();
  }

  public function tear_down() {
    delete_option('blink_api_key');
    delete_option('blink_wallet_type');
    parent::tear_down();
  }

  public function test_a_custodial_shop_is_not_treated_as_non_custodial(): void {
    $this->assertFalse((new \Blink\WC\Helpers\BlinkApiHelper())->isNonCustodial());
  }

  /**
   * The account type falls back to custodial when nothing is stored, which is
   * the state every site upgrading from an earlier release is in.
   */
  public function test_a_site_with_no_stored_account_type_is_custodial(): void {
    delete_option('blink_account_type');

    $this->assertFalse((new \Blink\WC\Helpers\BlinkApiHelper())->isNonCustodial());
  }

  /**
   * Custodial orders must not acquire any non-custodial marker, or the pay
   * page and poll endpoint would start claiming them.
   */
  public function test_a_custodial_order_carries_no_non_custodial_meta(): void {
    $order = $this->makeOrder();

    foreach (
      [
        InvoiceRepository::ACCOUNT_TYPE,
        InvoiceRepository::VERIFY_URL,
        InvoiceRepository::LN_ADDRESS,
        InvoiceRepository::EXPIRES_AT,
      ] as $key
    ) {
      $this->assertEmpty($order->get_meta($key), $key . ' must not be set');
    }
  }

  public function test_the_pay_page_does_not_claim_a_custodial_order(): void {
    $order = $this->makeOrder();
    $order->update_meta_data(InvoiceRepository::PAYMENT_HASH, 'abc123');
    $order->save();

    ob_start();
    $this->gateway->renderPayPage($order->get_id());

    $this->assertSame('', (string) ob_get_clean());
  }

  public function test_an_unconfigured_shop_refuses_to_take_payment(): void {
    delete_option('blink_api_key');
    \Blink\WC\Services::replace(new \Blink\WC\Services());
    $gateway = new BlinkLnGateway();
    $order = $this->makeOrder();

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Can't process order");
    $gateway->process_payment($order->get_id());
  }

  // --------------------------------------------------------------- webhook

  private function postWebhook(array $payload): void {
    $GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode($payload);
  }

  public function test_the_webhook_looks_orders_up_by_the_legacy_meta_key(): void {
    // Renaming blink_id would break the webhook and every pre-existing order.
    $order = $this->makeOrder();
    $order->update_meta_data('blink_id', 'hash-1');
    $order->save();

    $found = wc_get_orders([
      'limit' => 2,
      'meta_key' => 'blink_id',
      'meta_value' => 'hash-1',
    ]);

    $this->assertCount(1, $found);
    $this->assertSame($order->get_id(), $found[0]->get_id());
    $this->assertSame('blink_id', InvoiceRepository::PAYMENT_HASH);
  }

  /**
   * An order created by version 0.1.3 has only these three keys. It must still
   * be recognised after the upgrade.
   */
  public function test_a_legacy_order_is_still_recognisable(): void {
    $order = $this->makeOrder();
    $order->update_meta_data('blink_id', 'legacy-hash');
    $order->update_meta_data('blink_payment_request', 'lnbc1legacy');
    $order->update_meta_data('blink_redirect', 'https://pay.blink.sv/checkout/legacy-hash');
    $order->save();

    $reloaded = $this->reload($order);

    $this->assertSame('legacy-hash', $reloaded->get_meta('blink_id'));
    $this->assertFalse(
      $this->repository()->isNonCustodial($this->record($reloaded)),
      'a legacy order must not be mistaken for a non-custodial one'
    );
  }

  public function test_the_poll_endpoint_refuses_a_custodial_order(): void {
    // Verified through the repository check the endpoint performs, since the
    // endpoint itself terminates the request.
    $order = $this->makeOrder();
    $order->update_meta_data('blink_id', 'hash-1');
    $order->save();

    $this->assertFalse($this->repository()->isNonCustodial($this->record($order)));
  }

  public function test_the_gateway_still_registers_its_hooks(): void {
    $this->assertNotFalse(has_action('woocommerce_api_blink_default'));
    $this->assertNotFalse(has_action('woocommerce_receipt_blink_default'));
    $this->assertNotFalse(has_action('wp_ajax_nopriv_blink_check_invoice'));
  }

  public function test_the_gateway_identity_is_unchanged(): void {
    $this->assertSame('blink_default', $this->gateway->id);
    $this->assertSame('Blink (Lightning)', $this->gateway->method_title);
  }
}
