<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\Tests\Support\IntegrationTestCase;

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

    $this->runDueAction($order->get_id());

    $this->assertSame('cancelled', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'expired'));
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
      new HttpResponse(200, (string) json_encode(['settled' => true, 'preimage' => $this->preimage]))
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
    $this->services()->settlementScheduler()->onInvoiceCreated($this->record($order), $invoice);

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
   * An order paid or cancelled by any other route should stop costing
   * outbound requests.
   */
  public function test_a_status_change_elsewhere_cancels_scheduled_checks(): void {
    $scheduler = $this->useFakeScheduler();
    $order = $this->makeOrder();
    $invoice = $this->storeInvoice($order);
    $this->services()->settlementScheduler()->onInvoiceCreated($this->record($order), $invoice);

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
