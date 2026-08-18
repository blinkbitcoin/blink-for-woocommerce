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
      $this->addNote(
        $order,
        sprintf('Invoice expired (via %s).', $context),
        $dedupeNotes
      );

      return 'EXPIRED';
    }

    if ($status === 'PAID') {
      // Read before the status is written, so it describes what the order was
      // when the payment arrived.
      $wasCancelled = $order->has_status('cancelled');

      $this->updateStatus($order, $states[OrderStates::PAID]);
      $this->addNote(
        $order,
        sprintf('Invoice payment settled (via %s).', $context),
        $dedupeNotes
      );

      // A Lightning payment cannot be reversed automatically, so crediting the
      // order is right even here -- but the stock reservation was released when
      // it was cancelled, and those units may have been sold since. That is a
      // merchant's call, not something to silently paper over.
      if ($wasCancelled) {
        $this->addNote(
          $order,
          'This payment arrived after the order was cancelled. Stock was released at ' .
            'cancellation, so check availability before fulfilling.',
          $dedupeNotes
        );
      }

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
   * Without this, _date_paid and transaction_id are never set and
   * woocommerce_payment_complete never fires, so shipping, accounting and
   * subscription plugins never learn the order was paid.
   *
   * Skipping the whole thing unless the paid state was unmapped made it dead
   * on a default install, where the shipped mapping sends PAID to
   * wc-processing. Only the *status* half belongs to the merchant, so that is
   * the only half the mapping decides:
   *
   * - unmapped, so nobody has chosen a status: WC_Order::payment_complete()
   *   does everything, including picking processing or completed.
   * - mapped: apply() has already set the merchant's status, so the same
   *   bookkeeping is done by hand. payment_complete() cannot be used here --
   *   for a mapping like on-hold it would run and then move the order off it,
   *   and for one like processing it would silently do nothing at all, which
   *   is the bug. Stock on this path is reduced by WooCommerce's own hooks on
   *   the transition apply() made.
   *
   * TODO: the custodial path has the same gap and should be brought in line
   * once the change can be tested against a live Blink account.
   */
  public function completePayment(\WC_Order $order, string $transactionId): void {
    // Another gateway already carried this order; do not stamp our payment
    // over it. This mirrors apply(), which declines the same orders.
    if ($this->isProtected($order)) {
      return;
    }

    $states = $this->configuredStates();
    if ($states[OrderStates::PAID] === OrderStates::IGNORE) {
      $order->payment_complete($transactionId);

      return;
    }

    if ($transactionId !== '' && $order->get_transaction_id() === '') {
      $order->set_transaction_id($transactionId);
    }
    if (!$order->get_date_paid('edit')) {
      $order->set_date_paid(time());
    }
    $order->save();

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired here because payment_complete() declines to.
    do_action('woocommerce_payment_complete', $order->get_id(), $transactionId);
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

    Logger::debug(
      'Updating order status from ' . $order->get_status() . ' to ' . $status
    );
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
    foreach (
      wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 50])
      as $existing
    ) {
      if (trim($existing->content) === trim($note)) {
        return true;
      }
    }

    return false;
  }
}
