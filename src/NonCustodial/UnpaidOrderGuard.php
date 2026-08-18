<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\ClockInterface;

/**
 * Decides whether WooCommerce may auto-cancel an unpaid order.
 *
 * WooCommerce holds stock for pending orders and cancels them once
 * `woocommerce_hold_stock_minutes` has passed -- 60 by default, against Blink
 * invoices that may be valid for 3600 seconds. That timer is stock management:
 * it knows nothing about whether a Lightning invoice is still payable, and
 * cancelling on it took the order's scheduled settlement checks with it, so a
 * customer who paid a live invoice and closed the tab was never credited.
 *
 * The veto is deliberately narrow. It applies only while this plugin still
 * expects a payment, and once the invoice is resolved WooCommerce's timer
 * resumes its normal job on the next run.
 *
 * The one case it will not release is an order nobody ever got an answer
 * about. A verify endpoint that was unreachable through the whole invoice
 * window says nothing about whether the customer paid, and cancelling on the
 * clock alone also tears down the settlement checks that would still have
 * found out. Such an order is held pending with a note instead, which is what
 * SettlementScheduler::tick() already tells the log it does. A shop manager
 * cancelling by hand is unaffected -- this filter only narrows the automatic
 * stock timer.
 */
final class UnpaidOrderGuard {
  /** Latches the held-for-review note so it is added at most once. */
  public const HOLD_NOTICE = '_blink_hold_notice';

  public function __construct(
    private InvoiceRepository $repository,
    private ClockInterface $clock
  ) {
  }

  /**
   * @param bool $wooCommerceWould whether WooCommerce intended to cancel.
   *
   * Only ever narrows that decision. An order this plugin has no claim on --
   * custodial, or with no invoice stored -- keeps whatever WooCommerce decided,
   * because forcing it either way would override an unrelated shop's rules.
   */
  public function mayCancel(OrderRecord $order, bool $wooCommerceWould): bool {
    if (!$wooCommerceWould) {
      return false;
    }

    if (!$this->repository->isNonCustodial($order)) {
      return true;
    }

    // Already resolved, so there is nothing left to wait for.
    if ($this->repository->terminalStatus($order) !== null) {
      return true;
    }

    $invoice = $this->repository->load($order);
    if ($invoice === null) {
      return true;
    }

    // An invoice with no recorded expiry cannot be shown to be dead, and
    // settlement's own ceilings are what bound it. Treat it as still payable.
    if ($invoice->expiresAt <= 0) {
      return false;
    }

    $windowClosed =
      $this->clock->now() > $invoice->expiresAt + SettlementService::EXPIRY_GRACE_SECONDS;
    if (!$windowClosed) {
      return false;
    }

    // Past the window, but only a conclusive answer makes that meaningful.
    //
    // attempts() is checked separately from the error count and is load
    // bearing: on a site where Action Scheduler never ran, consecutiveErrors()
    // is zero because nothing was ever recorded, not because anything
    // answered.
    $everAnswered =
      $this->repository->attempts($order) > 0 &&
      $this->repository->consecutiveErrors($order) === 0;

    if (!$everAnswered) {
      $this->noteHeld($order);

      return false;
    }

    return true;
  }

  /**
   * Explains the hold once.
   *
   * WooCommerce runs its unpaid-order sweep on a schedule, so an unlatched
   * note would be appended to the same order every time it runs.
   */
  private function noteHeld(OrderRecord $order): void {
    if ((string) $order->getMeta(self::HOLD_NOTICE) !== '') {
      return;
    }

    $order->setMeta(self::HOLD_NOTICE, (string) $this->clock->now());
    $order->addNote(
      'This order was not cancelled automatically: the Lightning invoice has ' .
        'expired but its payment status could never be confirmed, so it is not ' .
        'known whether the customer paid. Check the payment before cancelling ' .
        'or fulfilling this order.'
    );
    $order->save();
  }
}
