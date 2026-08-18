<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\Tests\Support\IntegrationTestCase;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Settlement without a browser.
 *
 * This is the defect the whole feature turned on: with no webhook and no
 * scheduled job, a buyer who scanned the QR code with a phone and closed the
 * laptop paid the merchant and then watched WooCommerce cancel the order.
 */
final class BackgroundSettlementTest extends IntegrationTestCase {
  /** Runs the scheduler's hook the way Action Scheduler would. */
  private function runDueAction(int $orderId): void {
    do_action(SettlementScheduler::HOOK, $orderId);
  }

  public function test_an_order_settles_with_no_browser_involved(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'settled'));
  }

  public function test_settlement_survives_several_unpaid_checks(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $this->http->queueJson(['settled' => false]);
    $this->runDueAction($order->get_id());
    $this->assertSame('pending', $this->reload($order)->get_status());

    $this->clock->travel(60);
    $this->http->queueJson(['settled' => false]);
    $this->runDueAction($order->get_id());
    $this->assertSame('pending', $this->reload($order)->get_status());

    $this->clock->travel(60);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  public function test_an_expired_invoice_is_cancelled_once_the_window_passes(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);

    $this->runDueAction($order->get_id());

    $this->assertSame('cancelled', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'expired'));
  }

  /**
   * The customer story behind the shared-counter bug.
   *
   * A pay page left open makes far more checks than the background job ever
   * will -- three a minute against roughly fifteen for the whole invoice. When
   * both spent the same budget, twenty minutes of watching exhausted it, the
   * scheduler stopped rescheduling, and a payment made after the customer
   * closed the tab was never noticed.
   */
  public function test_a_watched_pay_page_does_not_end_background_settlement(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    $settlement = $this->services()->settlement();
    for ($i = 0; $i < SettlementService::MAX_ATTEMPTS + 10; $i++) {
      $this->clock->travel(20);
      $settlement->poll($this->record($order));
    }

    // The customer pays and closes the tab; only the background job is left.
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  public function test_a_delayed_first_check_recovers_payment_after_the_deadline(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'settled'));
  }

  /**
   * The behaviour that matters most: a verify endpoint that is unreachable
   * around expiry must never cause a paid order to be cancelled.
   */
  public function test_an_unreachable_endpoint_never_cancels_an_order(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 120]);
    $this->http->alwaysRespond(HttpResponse::transportFailure('connection refused'));

    for ($i = 0; $i < 5; $i++) {
      $this->clock->travel(45);
      $this->runDueAction($order->get_id());
    }

    $this->assertSame(
      'pending',
      $this->reload($order)->get_status(),
      'the order must be left for a human, not cancelled'
    );
  }

  /**
   * The reviewer's scenario, end to end: verification is down while the
   * invoice expires, then the payment turns out to have landed.
   */
  public function test_a_payment_made_during_an_outage_is_still_recovered(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 300]);

    $this->clock->travel(280);
    $this->http->queue(HttpResponse::transportFailure('connection refused'));
    $this->runDueAction($order->get_id());
    $this->assertSame('pending', $this->reload($order)->get_status());

    $this->clock->travel(40);
    $this->http->queue(new HttpResponse(503, ''));
    $this->runDueAction($order->get_id());
    $this->assertSame('pending', $this->reload($order)->get_status());

    $this->clock->travel(20);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  public function test_a_settled_order_is_not_touched_again(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->alwaysRespond(
      new HttpResponse(
        200,
        (string) json_encode(['settled' => true, 'preimage' => $this->preimage])
      )
    );

    $this->runDueAction($order->get_id());
    $requests = $this->http->requestCount();
    $this->runDueAction($order->get_id());

    $this->assertSame($requests, $this->http->requestCount());
    $this->assertSame(1, $this->countNotesContaining($order, 'settled'));
  }

  public function test_a_custodial_order_is_ignored_by_the_scheduler(): void {
    $order = $this->makeOrder();

    $this->runDueAction($order->get_id());

    $this->assertSame(0, $this->http->requestCount());
    $this->assertSame('pending', $this->reload($order)->get_status());
  }

  public function test_an_unknown_order_id_is_harmless(): void {
    $this->runDueAction(999999);

    $this->assertSame(0, $this->http->requestCount());
  }

  public function test_a_settled_payment_with_a_bad_preimage_does_not_complete_the_order(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => true, 'preimage' => str_repeat('cd', 32)]);

    $this->runDueAction($order->get_id());

    $this->assertSame('pending', $this->reload($order)->get_status());
  }

  public function test_an_order_edited_after_invoicing_is_held_for_review(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $order->set_total('99.00');
    $order->save();
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $this->runDueAction($order->get_id());

    $this->assertSame('on-hold', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'total changed'));
  }

  // ------------------------------------------------------------- scheduling

  public function test_creating_an_invoice_schedules_a_check(): void {
    $scheduler = $this->useFakeScheduler();
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);

    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);

    $this->assertCount(1, $scheduler->scheduled);
    $this->assertSame([$order->get_id()], $scheduler->scheduled[0]['args']);
  }

  public function test_resolving_an_order_stops_further_checks(): void {
    $scheduler = $this->useFakeScheduler();
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);
    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertNull(
      $scheduler->nextScheduled(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      )
    );
  }

  /**
   * Makes the order eligible for WooCommerce's unpaid-order timer and runs it.
   *
   * The timer selects pending orders created through checkout whose last
   * modification is older than woocommerce_hold_stock_minutes, so the order has
   * to be aged before it will be considered at all.
   */
  private function ordersAreInTheirOwnTable(): bool {
    return class_exists(OrderUtil::class) &&
      OrderUtil::custom_orders_table_usage_is_enabled();
  }

  private function runTheStockTimerAgainst(\WC_Order $order): void {
    global $wpdb;

    update_option('woocommerce_manage_stock', 'yes');
    update_option('woocommerce_hold_stock_minutes', '1');

    $order->set_created_via('checkout');
    $order->save();

    // Aged in the database rather than through set_date_modified(), which the
    // order's own save() overwrites with the current time.
    //
    // Which table depends on where WooCommerce keeps orders: get_unpaid_orders()
    // reads post_modified_gmt from the posts table, or date_updated_gmt from
    // wc_orders under HPOS. Writing only to the posts table left the order
    // un-aged on every HPOS run, so the assertion below -- the one that exists
    // to stop these tests passing vacuously -- failed instead.
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    if ($this->ordersAreInTheirOwnTable()) {
      $wpdb->update(
        $wpdb->prefix . 'wc_orders',
        ['date_updated_gmt' => $past],
        ['id' => $order->get_id()]
      );
      wp_cache_flush();
    } else {
      $wpdb->update(
        $wpdb->posts,
        ['post_modified' => $past, 'post_modified_gmt' => $past],
        ['ID' => $order->get_id()]
      );
      clean_post_cache($order->get_id());
    }

    // Without this the timer would simply not consider the order, and every
    // assertion about what it does or does not cancel would pass vacuously.
    $this->assertContains(
      (string) $order->get_id(),
      array_map(
        'strval',
        \WC_Data_Store::load('order')->get_unpaid_orders(
          strtotime('-1 MINUTES', current_time('timestamp'))
        )
      ),
      'the order must actually be eligible for the stock timer'
    );

    wc_cancel_unpaid_orders();
  }

  /**
   * WooCommerce holds stock for an hour by default and Blink invoices last up
   * to an hour, so the stock timer and the invoice expire at almost the same
   * moment -- and on any shop with a shorter hold-stock setting the timer wins.
   * Cancelling took the order's settlement checks with it, so a customer who
   * paid a still-valid QR code and closed the tab was never credited.
   */
  public function test_the_stock_timer_does_not_cancel_a_payable_invoice(): void {
    $scheduler = $this->useFakeScheduler();
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);
    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);
    $scheduler->unscheduled = [];

    $this->runTheStockTimerAgainst($order);

    $this->assertSame('pending', $this->reload($order)->get_status());
    $this->assertSame([], $scheduler->unscheduled, 'the checks must survive');

    // And the payment the customer goes on to make is still credited.
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  /**
   * The other half of the same rule: the reprieve is bounded by the invoice, so
   * an order whose window has closed goes back to being ordinary stock
   * management. This also proves the test above is not passing because the
   * timer failed to consider the order at all.
   */
  public function test_the_stock_timer_still_cancels_once_the_invoice_is_dead(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    // The endpoint answered, and answered that this was never paid.
    $this->repository()->recordAttempt($this->record($order), false);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->runTheStockTimerAgainst($order);

    $this->assertSame('cancelled', $this->reload($order)->get_status());
  }

  /**
   * An expired invoice the verify endpoint never answered for is not the same
   * thing as an unpaid one. Cancelling on the clock alone also tore down the
   * checks that would still have found out, so a customer who paid during a
   * provider outage lost both the payment and the order.
   */
  public function test_an_invoice_nobody_could_check_is_held_rather_than_cancelled(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    // Every check failed to reach the endpoint.
    $this->repository()->recordAttempt($this->record($order), true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->runTheStockTimerAgainst($order);

    $reloaded = $this->reload($order);
    $this->assertSame('pending', $reloaded->get_status());
    $this->assertNotEmpty(
      array_filter(
        wc_get_order_notes(['order_id' => $order->get_id()]),
        static fn($note): bool => str_contains($note->content, 'could never be confirmed')
      ),
      'the merchant is told why the order is still sitting there'
    );
  }

  /**
   * The same order, once the provider comes back and the payment is seen.
   */
  public function test_a_held_order_is_still_credited_when_the_endpoint_returns(): void {
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order, ['expiresAt' => self::NOW + 60]);
    $this->repository()->recordAttempt($this->record($order), true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->runTheStockTimerAgainst($order);
    $this->assertSame('pending', $this->reload($order)->get_status());

    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->runDueAction($order->get_id());

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  /**
   * A cancellation reaching a live invoice is now a shop manager's decision
   * rather than the stock timer's, and a deliberate one is respected.
   */
  public function test_a_manual_status_change_cancels_scheduled_checks(): void {
    $scheduler = $this->useFakeScheduler();
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);
    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);

    $order->update_status('cancelled');

    $this->assertNotEmpty($scheduler->unscheduled);
  }

  public function test_action_scheduler_is_available_in_this_environment(): void {
    // Guards the assumption the design rests on: WooCommerce ships it.
    $this->assertTrue($this->services()->scheduler()->isAvailable());
  }

  public function test_the_real_scheduler_enqueues_an_action(): void {
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);

    $this->services()
      ->settlementScheduler()
      ->onInvoiceCreated($this->record($order), $invoice);

    $next = as_next_scheduled_action(
      SettlementScheduler::HOOK,
      [$order->get_id()],
      SettlementScheduler::GROUP
    );

    $this->assertNotFalse($next);
  }

  public function test_the_real_scheduler_can_unschedule_by_order(): void {
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);
    $scheduler = $this->services()->settlementScheduler();
    $scheduler->onInvoiceCreated($this->record($order), $invoice);

    $scheduler->cancel($order->get_id());

    $this->assertFalse(
      as_next_scheduled_action(
        SettlementScheduler::HOOK,
        [$order->get_id()],
        SettlementScheduler::GROUP
      )
    );
  }

  public function test_the_cached_status_is_readable_after_a_check(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);
    $this->http->queueJson(['settled' => false]);

    $this->runDueAction($order->get_id());

    $cached = $this->repository()->cachedStatus($this->record($this->reload($order)));
    $this->assertSame(SettlementStatus::Pending, $cached?->status);
  }
}
