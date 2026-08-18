<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Gateway;

use Blink\WC\Gateway\BlinkLnGateway;
use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\Tests\Support\SubstitutesBlinkServices;
use Blink\WC\Tests\Support\TestTime;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use WP_Ajax_UnitTestCase;

/**
 * The public polling endpoint.
 *
 * It is nopriv by necessity -- guests check out -- so its authorisation is the
 * nonce, the order key, and a per-IP budget. These tests cover both what it
 * lets through and what it refuses.
 *
 * @group ajax
 */
final class AjaxCheckInvoiceTest extends WP_Ajax_UnitTestCase {
  use SubstitutesBlinkServices;

  /** Re-exposed from TestTime: a trait cannot hold a constant on PHP 8.1. */
  public const NOW = TestTime::NOW;

  private BlinkLnGateway $gateway;

  public function set_up() {
    parent::set_up();
    $this->substituteBlinkServices();

    // The plugin registered a gateway at load time, holding the services graph
    // from before the substitution. Replace those handlers with one wired to
    // the fakes, so a dispatch runs the endpoint exactly once.
    remove_all_actions('wp_ajax_blink_check_invoice');
    remove_all_actions('wp_ajax_nopriv_blink_check_invoice');
    $this->gateway = new BlinkLnGateway();
  }

  public function tear_down() {
    $this->restoreBlinkServices();
    unset(
      $_POST['nonce'],
      $_POST['order_id'],
      $_POST['order_key'],
      $_SERVER['REMOTE_ADDR']
    );
    parent::tear_down();
  }

  /** @return array{status:string,redirect:string|null} */
  private function call(\WC_Order $order, array $overrides = []): array {
    $_POST['nonce'] = $overrides['nonce'] ?? wp_create_nonce('blink-pay-nonce');
    $_POST['order_id'] = $overrides['order_id'] ?? $order->get_id();
    $_POST['order_key'] = $overrides['order_key'] ?? $order->get_order_key();
    $_SERVER['REMOTE_ADDR'] = $overrides['ip'] ?? '198.51.100.7';

    $this->_last_response = '';

    try {
      // Dispatched through the framework's handler so the response is captured
      // the way a real admin-ajax request would be.
      $this->_handleAjax('blink_check_invoice');
    } catch (WPAjaxDieContinueException | WPAjaxDieStopException $e) {
      // wp_send_json_* always terminates the request.
    }

    $decoded = json_decode($this->_last_response, true);

    return [
      'success' => $decoded['success'] ?? false,
      'status' => $decoded['data']['status'] ?? null,
      'redirect' => $decoded['data']['redirect'] ?? null,
      'message' => $decoded['data']['message'] ?? null,
    ];
  }

  public function test_a_pending_invoice_is_reported_as_pending(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => false]);

    $result = $this->call($order);

    $this->assertTrue($result['success']);
    $this->assertSame('PENDING', $result['status']);
    $this->assertNull($result['redirect']);
  }

  public function test_a_settled_invoice_is_reported_with_a_redirect(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $result = $this->call($order);

    $this->assertSame('PAID', $result['status']);
    $this->assertNotNull($result['redirect']);
    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  public function test_an_already_paid_order_short_circuits(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $order->update_status('processing');

    $result = $this->call($order);

    $this->assertSame('PAID', $result['status']);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function test_an_expired_invoice_is_reported_as_expired(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);

    $result = $this->call($order);

    $this->assertSame('EXPIRED', $result['status']);
    $this->assertSame('cancelled', $this->reload($order)->get_status());
  }

  // --------------------------------------------------------- authorisation

  public function test_a_bad_nonce_is_refused(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $result = $this->call($order, ['nonce' => 'not-a-nonce']);

    $this->assertFalse($result['success']);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function test_a_wrong_order_key_is_refused(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $result = $this->call($order, ['order_key' => 'wc_order_wrong']);

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid order.', $result['message']);
  }

  public function test_an_unknown_order_is_refused(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $result = $this->call($order, ['order_id' => 999999]);

    $this->assertFalse($result['success']);
  }

  public function test_a_missing_order_id_is_refused(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $result = $this->call($order, ['order_id' => 0]);

    $this->assertFalse($result['success']);
  }

  /**
   * Without this check any valid order id and key drove a settlement check,
   * custodial orders included.
   */
  public function test_a_custodial_order_is_refused(): void {
    $order = $this->makeOrder();

    $result = $this->call($order);

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid order.', $result['message']);
    $this->assertSame(0, $this->http->requestCount());
  }

  // ---------------------------------------------------------------- budget

  /**
   * The browser reads what the server last observed; it does not get to force
   * an outbound request on every poll.
   */
  public function test_a_fresh_observation_is_served_from_cache(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => false]);

    $this->call($order);
    $this->assertSame(1, $this->http->requestCount());

    $this->call($order);
    $this->call($order);

    $this->assertSame(1, $this->http->requestCount(), 'repeat polls must not re-fetch');
  }

  public function test_a_stale_observation_triggers_a_fresh_check(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => false]);
    $this->call($order);

    $this->clock->travel(SettlementService::CACHE_FRESH_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);
    $this->call($order);

    $this->assertSame(2, $this->http->requestCount());
  }

  /** Many tabs on one order collapse to one request per cache window. */
  public function test_many_tabs_on_one_order_produce_one_request(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    for ($i = 0; $i < 10; $i++) {
      $this->call($order);
    }

    $this->assertSame(1, $this->http->requestCount());
  }

  public function test_a_client_over_its_budget_still_receives_an_answer(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    $result = null;
    for ($i = 0; $i < 40; $i++) {
      $this->clock->travel(SettlementService::CACHE_FRESH_SECONDS + 1);
      $result = $this->call($order);
    }

    $this->assertTrue($result['success'], 'an exhausted budget is not an error');
    $this->assertSame('PENDING', $result['status']);
  }

  /** Only REMOTE_ADDR is trusted, so a spoofed header cannot lift the budget. */
  public function test_the_client_address_can_be_adjusted_for_proxied_sites(): void {
    $seen = [];
    add_filter('blink_client_ip', function ($ip) use (&$seen) {
      $seen[] = $ip;

      return $ip;
    });
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => false]);

    $this->call($order, ['ip' => '203.0.113.9']);

    $this->assertSame(['203.0.113.9'], $seen);
  }
}
