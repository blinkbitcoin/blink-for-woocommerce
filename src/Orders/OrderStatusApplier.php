<?php

declare(strict_types=1);

namespace Blink\WC\Orders;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\OrderStates;

/**
 * Applies a Blink invoice status to a WooCommerce order.
 *
 * Extracted from BlinkLnGateway::processOrderStatus() so that the custodial
 * webhook and the non-custodial settlement path share one implementation of
 * the merchant's order-state mapping rather than growing two that drift.
 *
 * This class performs no network I/O: the caller has already decided what the
 * invoice status is. That separation is what lets settlement be tested without
 * WooCommerce and this be tested without an LNURL server.
 */
final class OrderStatusApplier {
  /**
   * @param 'PAID'|'EXPIRED'|'PENDING'|'REVIEW' $status
   * @param string $context Free text used in the order note, e.g. 'webhook'.
   * @param bool $dedupeNotes When true, repeated observations of the same
   *   state add at most one note. The non-custodial path polls repeatedly and
   *   would otherwise append a note on every check; the custodial webhook
   *   passes false so its behaviour is unchanged.
   *
   * @return 'PAID'|'EXPIRED'|'PENDING'|'REVIEW'
   */
  public function apply(
    \WC_Order $order,
    string $status,
    string $context = 'webhook',
    bool $dedupeNotes = false
  ): string {
    if ($this->isProtected($order)) {
      $this->addNote(
        $order,
        sprintf(
          'Blink update received via %s, but the order is already processing or completed, ' .
            'skipping to update order status. Please manually check if everything is alright.',
          $context
        ),
        $dedupeNotes
      );

      // The custodial webhook's original answer is preserved. The
      // non-custodial poller needs to know the order is done so the customer
      // is redirected instead of watching a page spin forever.
      if ($dedupeNotes) {
        return 'PAID';
      }

      return $order->has_status('completed') ? 'PAID' : 'PENDING';
    }

    $states = $this->configuredStates();

    if ($status === 'EXPIRED') {
      $this->updateStatus($order, $states[OrderStates::EXPIRED]);
      $this->addNote($order, sprintf('Invoice expired (via %s).', $context), $dedupeNotes);

      return 'EXPIRED';
    }

    if ($status === 'PAID') {
      $this->updateStatus($order, $states[OrderStates::PAID]);
      $this->addNote($order, sprintf('Invoice payment settled (via %s).', $context), $dedupeNotes);

      return 'PAID';
    }

    if ($status === 'REVIEW') {
      $order->update_status('on-hold');
      $this->addNote(
        $order,
        sprintf(
          'Payment received, but the order total changed after the invoice was created ' .
            '(via %s). Review this order before fulfilling it.',
          $context
        ),
        $dedupeNotes
      );

      return 'REVIEW';
    }

    return 'PENDING';
  }

  public function isProtected(\WC_Order $order): bool {
    return get_option('blink_protect_order_status', 'no') === 'yes' &&
      $order->has_status(['processing', 'completed']);
  }

  /**
   * Applies WooCommerce's own payment bookkeeping.
   *
   * Called only when the merchant has left the paid state unmapped, so an
   * explicit mapping still wins. Without this, _date_paid and transaction_id
   * are never set and woocommerce_payment_complete never fires, so shipping,
   * accounting and subscription plugins never learn the order was paid.
   *
   * TODO: the custodial path has the same gap and should be brought in line
   * once the change can be tested against a live Blink account.
   */
  public function completePaymentIfUnmapped(\WC_Order $order, string $transactionId): void {
    $states = $this->configuredStates();
    if ($states[OrderStates::PAID] !== OrderStates::IGNORE) {
      return;
    }

    $order->payment_complete($transactionId);
  }

  /** @return array<string,string> */
  private function configuredStates(): array {
    $configured = get_option('blink_order_states');
    if (!$configured) {
      $configured = (new OrderStates())->getDefaultOrderStateMappings();
    }

    return $configured;
  }

  private function updateStatus(\WC_Order $order, string $status): void {
    if ($status === OrderStates::IGNORE) {
      return;
    }

    Logger::debug('Updating order status from ' . $order->get_status() . ' to ' . $status);
    $order->update_status($status);
  }

  /**
   * Adds an order note, optionally at most once for the same text.
   *
   * Without deduplication the non-custodial poller appends the same note on
   * every check, which for a protected order that never resolves means an
   * order note every few seconds for as long as the page is open.
   */
  private function addNote(\WC_Order $order, string $note, bool $dedupe): void {
    if ($dedupe && $this->hasNote($order, $note)) {
      return;
    }

    $order->add_order_note($note);
  }

  private function hasNote(\WC_Order $order, string $note): bool {
    foreach (wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 50]) as $existing) {
      if (trim($existing->content) === trim($note)) {
        return true;
      }
    }

    return false;
  }
}
