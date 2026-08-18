<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\ClockInterface;

/**
 * Whether the invoice an order already carries may be shown again.
 *
 * Checkout can be reached repeatedly for the same order -- a reloaded tab, an
 * emailed pay link, a customer who went away and came back -- and each arrival
 * has to choose between the stored invoice and a fresh one. Reusing the wrong
 * one shows a customer a QR code that cannot be paid, or one made out for an
 * amount the order no longer has.
 *
 * Deliberately decided from stored state alone. An earlier version made a
 * synchronous verify request here, which put a third-party HTTP call on the
 * checkout submit path and, because a transport error surfaced as PENDING,
 * treated an unreachable server as "this invoice is fine".
 *
 * It lives here rather than on the gateway because the gateway is a WordPress
 * adapter, measured for lines only: a branch added to it is invisible to the
 * coverage gate. See docs/future-work.md §7.
 */
final class InvoiceReusePolicy {
  /**
   * Below this, there is not enough of the invoice's life left to be worth
   * showing: the customer would be racing its expiry.
   */
  public const MIN_SECONDS_LEFT = 120;

  public function __construct(
    private InvoiceRepository $repository,
    private ClockInterface $clock
  ) {
  }

  public function isReusable(OrderRecord $order): bool {
    if ($this->repository->terminalStatus($order) !== null) {
      return false;
    }

    $invoice = $this->repository->load($order);
    if ($invoice === null) {
      return false;
    }

    // Leave enough time for the customer to actually pay it. An expiry of 0
    // means the invoice never carried one, which is not a reason to replace it.
    $now = $this->clock->now();
    if ($invoice->expiresAt > 0 && $invoice->expiresAt - $now < self::MIN_SECONDS_LEFT) {
      return false;
    }

    // An order edited since the invoice was made needs a new one.
    return $this->repository->totalsUnchanged($order, $invoice);
  }
}
