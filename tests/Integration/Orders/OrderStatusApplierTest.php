<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Orders;

use Blink\WC\Helpers\OrderStates;
use Blink\WC\Orders\OrderStatusApplier;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The one class the custodial and non-custodial paths share.
 *
 * Its custodial behaviour was moved here unchanged, so these tests double as
 * the regression net for that move.
 */
final class OrderStatusApplierTest extends IntegrationTestCase {
  private OrderStatusApplier $applier;

  public function set_up() {
    parent::set_up();
    $this->applier = new OrderStatusApplier();
  }

  public function test_paid_moves_the_order_to_the_mapped_state(): void {
    $order = $this->makeOrder();

    $result = $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame('PAID', $result);
    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  public function test_paid_adds_an_order_note_naming_its_source(): void {
    $order = $this->makeOrder();

    $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame(1, $this->countNotesContaining($order, 'settled (via webhook)'));
  }

  /**
   * The money moved and cannot be sent back automatically, so the order has to
   * reflect it. What the merchant is not told automatically is whether the
   * goods are still there.
   */
  public function test_paying_a_cancelled_order_credits_it_and_flags_the_stock(): void {
    $order = $this->makeOrder();
    $order->update_status('cancelled');

    $this->applier->apply($order, 'PAID', 'scheduler');

    $this->assertSame('processing', $this->reload($order)->get_status());
    $this->assertSame(
      1,
      $this->countNotesContaining($order, 'arrived after the order was cancelled')
    );
  }

  public function test_paying_a_pending_order_does_not_mention_cancellation(): void {
    $order = $this->makeOrder();

    $this->applier->apply($order, 'PAID', 'scheduler');

    $this->assertSame(
      0,
      $this->countNotesContaining($order, 'arrived after the order was cancelled')
    );
  }

  public function test_expired_moves_the_order_to_the_mapped_state(): void {
    $order = $this->makeOrder();

    $result = $this->applier->apply($order, 'EXPIRED', 'scheduler');

    $this->assertSame('EXPIRED', $result);
    $this->assertSame('cancelled', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'expired (via scheduler)'));
  }

  public function test_pending_changes_nothing(): void {
    $order = $this->makeOrder();

    $result = $this->applier->apply($order, 'PENDING', 'webhook');

    $this->assertSame('PENDING', $result);
    $this->assertSame('pending', $this->reload($order)->get_status());
    $this->assertSame([], $this->noteContents($order));
  }

  public function test_review_holds_the_order_and_explains_why(): void {
    $order = $this->makeOrder();

    $result = $this->applier->apply($order, 'REVIEW', 'scheduler');

    $this->assertSame('REVIEW', $result);
    $this->assertSame('on-hold', $this->reload($order)->get_status());
    $this->assertSame(1, $this->countNotesContaining($order, 'total changed'));
  }

  public function test_a_merchant_mapping_of_ignore_leaves_the_status_alone(): void {
    update_option('blink_order_states', [
      OrderStates::PAID => OrderStates::IGNORE,
      OrderStates::EXPIRED => OrderStates::IGNORE,
    ]);
    $order = $this->makeOrder();

    $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame('pending', $this->reload($order)->get_status());
    $this->assertSame(
      1,
      $this->countNotesContaining($order, 'settled'),
      'the note is still added'
    );
  }

  public function test_a_custom_mapping_is_honoured(): void {
    update_option('blink_order_states', [
      OrderStates::PAID => 'completed',
      OrderStates::EXPIRED => 'failed',
    ]);
    $order = $this->makeOrder();

    $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame('completed', $this->reload($order)->get_status());
  }

  // ------------------------------------------------------------ protection

  public function test_protection_is_off_by_default(): void {
    $order = $this->makeOrder();
    $order->set_status('processing');
    $order->save();

    $this->assertFalse($this->applier->isProtected($order));
  }

  public function test_a_protected_order_in_progress_is_not_touched(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->set_status('processing');
    $order->save();

    $result = $this->applier->apply($order, 'EXPIRED', 'webhook');

    $this->assertSame('PENDING', $result);
    $this->assertSame('processing', $this->reload($order)->get_status());
    $this->assertSame(
      1,
      $this->countNotesContaining($order, 'already processing or completed')
    );
  }

  public function test_a_protected_completed_order_reports_paid(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->set_status('completed');
    $order->save();

    $this->assertSame('PAID', $this->applier->apply($order, 'PAID', 'webhook'));
  }

  public function test_protection_does_not_apply_to_a_pending_order(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();

    $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame('processing', $this->reload($order)->get_status());
  }

  /**
   * The polling path needs to know the order is finished so the customer is
   * redirected; the webhook keeps its original, more cautious answer.
   */
  public function test_a_polling_caller_learns_a_protected_order_is_done(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->set_status('processing');
    $order->save();

    $this->assertSame('PAID', $this->applier->apply($order, 'PAID', 'ajax-poll', true));
    $this->assertSame('PENDING', $this->applier->apply($order, 'PAID', 'webhook', false));
  }

  // ------------------------------------------------------------------ notes

  /**
   * The poller runs every few seconds. Without deduplication a protected order
   * accumulated an order note on every single check.
   */
  public function test_repeated_polls_add_at_most_one_note(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->set_status('processing');
    $order->save();

    for ($i = 0; $i < 12; $i++) {
      $this->applier->apply($order, 'PAID', 'ajax-poll', true);
    }

    $this->assertSame(
      1,
      $this->countNotesContaining($order, 'already processing or completed')
    );
  }

  /** The webhook's note behaviour is deliberately unchanged. */
  public function test_the_webhook_path_still_adds_a_note_every_time(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->set_status('processing');
    $order->save();

    $this->applier->apply($order, 'PAID', 'webhook');
    $this->applier->apply($order, 'PAID', 'webhook');

    $this->assertSame(
      2,
      $this->countNotesContaining($order, 'already processing or completed')
    );
  }

  // -------------------------------------------------------- payment_complete

  public function test_payment_complete_runs_when_the_paid_state_is_unmapped(): void {
    update_option('blink_order_states', [
      OrderStates::PAID => OrderStates::IGNORE,
      OrderStates::EXPIRED => OrderStates::IGNORE,
    ]);
    $order = $this->makeOrder();

    $this->applier->completePayment($order, 'txn-123');

    $reloaded = $this->reload($order);
    $this->assertSame('txn-123', $reloaded->get_transaction_id());
    $this->assertNotNull($reloaded->get_date_paid());
  }

  /**
   * The regression. On a default install PAID maps to wc-processing, so the
   * old gate never ran and _date_paid, the transaction id and the
   * woocommerce_payment_complete hook were all skipped for every order.
   */
  public function test_payment_complete_runs_under_the_shipped_default_mapping(): void {
    delete_option('blink_order_states');
    $order = $this->makeOrder();
    $fired = [];
    add_action(
      'woocommerce_payment_complete',
      function ($orderId, $transactionId = '') use (&$fired): void {
        $fired[] = [$orderId, $transactionId];
      },
      10,
      2
    );

    $this->applier->completePayment($order, 'txn-123', null, 'blink', true);

    $reloaded = $this->reload($order);
    $this->assertSame('processing', $reloaded->get_status());
    $this->assertSame('txn-123', $reloaded->get_transaction_id());
    $this->assertNotNull($reloaded->get_date_paid());
    $this->assertCount(1, $fired);
    $this->assertSame([$order->get_id(), 'txn-123'], $fired[0]);
  }

  public function test_payment_complete_leaves_an_explicit_merchant_mapping_alone(): void {
    update_option('blink_order_states', [
      OrderStates::PAID => 'on-hold',
      OrderStates::EXPIRED => 'cancelled',
    ]);
    $order = $this->makeOrder();

    $this->applier->completePayment($order, 'txn-123', null, 'blink', true);

    $reloaded = $this->reload($order);
    $this->assertSame(
      'on-hold',
      $reloaded->get_status(),
      'the bookkeeping must not overrule the merchant\'s mapping'
    );
    $this->assertSame('txn-123', $reloaded->get_transaction_id());
    $this->assertNotNull($reloaded->get_date_paid());
  }

  public function test_mapped_payment_complete_hook_fires_only_once(): void {
    update_option('blink_order_states', [
      OrderStates::PAID => 'on-hold',
      OrderStates::EXPIRED => 'cancelled',
    ]);
    $order = $this->makeOrder();
    $fired = [];
    add_action(
      'woocommerce_payment_complete',
      function ($orderId, $transactionId = '') use (&$fired): void {
        $fired[] = [$orderId, $transactionId];
      },
      10,
      2
    );

    $this->applier->completePayment($order, 'txn-123', null, 'blink', true);

    $order = $this->reload($order);
    $this->applier->completePayment($order, 'txn-123', null, 'blink', true);

    $this->assertSame('on-hold', $this->reload($order)->get_status());
    $this->assertSame([[$order->get_id(), 'txn-123']], $fired);
  }

  public function test_payment_complete_declines_a_protected_order(): void {
    update_option('blink_protect_order_status', 'yes');
    $order = $this->makeOrder();
    $order->update_status('completed');

    $this->applier->completePayment($order, 'txn-123');

    $this->assertSame('', $this->reload($order)->get_transaction_id());
  }
}
