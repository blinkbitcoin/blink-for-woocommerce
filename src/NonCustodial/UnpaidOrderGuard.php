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
 * The veto is deliberately narrow and self-releasing. It applies only while
 * this plugin still expects a payment, and once the invoice is resolved or its
 * window has closed WooCommerce's timer resumes its normal job on the next run.
 * No order escapes stock management permanently.
 */
final class UnpaidOrderGuard {
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

    return $this->clock->now() >
      $invoice->expiresAt + SettlementService::EXPIRY_GRACE_SECONDS;
  }
}
