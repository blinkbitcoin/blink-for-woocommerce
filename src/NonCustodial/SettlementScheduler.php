<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\JitterInterface;
use Blink\WC\Support\LoggerInterface;
use Blink\WC\Support\SchedulerInterface;

/**
 * Keeps checking a non-custodial order after the customer's browser has gone.
 *
 * This is the piece the feature was missing entirely. Settlement had no
 * webhook and no scheduled job, so a buyer who scanned the QR code with a
 * phone and closed the laptop tab paid the merchant and then watched
 * WooCommerce cancel the order when hold-stock expired.
 *
 * The schedule escalates rather than polling at a fixed rate. A Lightning
 * payment settles in seconds, while the tab is almost always still open, so
 * the browser catches the common case for free; the scheduler exists for the
 * abandoned tab, where checking every few seconds for an hour would be pure
 * waste.
 */
final class SettlementScheduler {
  public const HOOK = 'blink_settle_noncustodial';
  public const GROUP = 'blink';

  /** Seconds after invoice creation at which checks fire, before jitter. */
  private const SCHEDULE = [20, 45, 90, 180, 300, 480, 720, 1020, 1380, 1800, 2400, 3000, 3480];

  /** Interval used once the schedule above is exhausted. */
  private const TAIL_INTERVAL = 300;

  public function __construct(
    private SchedulerInterface $scheduler,
    private SettlementService $settlement,
    private InvoiceRepository $repository,
    private ClockInterface $clock,
    private JitterInterface $jitter,
    private LoggerInterface $log
  ) {}

  /** @return array{int} */
  public static function argsFor(int $orderId): array {
    return [$orderId];
  }

  public function onInvoiceCreated(OrderRecord $order, StoredInvoice $invoice): void {
    // Drop anything left over from a previous invoice on this order, so an
    // abandoned attempt cannot keep polling a hash that no longer applies.
    $this->cancel($order->id());

    if (!$this->scheduler->isAvailable()) {
      $this->log->debug(
        'No background scheduler available; settlement will rely on the pay page only.'
      );

      return;
    }

    $this->scheduleAt($order->id(), $this->clock->now() + $this->jitter->apply(self::SCHEDULE[0]));
  }

  public function cancel(int $orderId): void {
    $this->scheduler->unscheduleAll(self::HOOK, self::argsFor($orderId), self::GROUP);
  }

  /**
   * One scheduled check.
   *
   * Returns whether another check was scheduled, which is what the integration
   * tests assert on rather than reaching into Action Scheduler.
   */
  public function tick(OrderRecord $order): bool {
    if (!$this->repository->isNonCustodial($order)) {
      return false;
    }

    if ($this->repository->terminalStatus($order) !== null) {
      return false;
    }

    $invoice = $this->repository->load($order);
    if ($invoice === null) {
      return false;
    }

    $outcome = $this->settlement->poll($order);

    if ($outcome->terminal) {
      return false;
    }

    if ($this->settlement->exhausted($order)) {
      $this->log->error(
        sprintf(
          'Order %d: giving up on background settlement after repeated failures. The order has ' .
            'been left pending for manual review.',
          $order->id()
        )
      );

      return false;
    }

    return $this->scheduleNext($order, $invoice);
  }

  private function scheduleNext(OrderRecord $order, StoredInvoice $invoice): bool {
    $now = $this->clock->now();
    $elapsed = $now - $invoice->createdAt;

    $next = null;
    foreach (self::SCHEDULE as $offset) {
      if ($offset > $elapsed) {
        $next = $offset;
        break;
      }
    }
    if ($next === null) {
      $next = $elapsed + self::TAIL_INTERVAL;
    }

    $timestamp = $invoice->createdAt + $this->jitter->apply($next);

    // Never schedule beyond the point where the order can still be resolved.
    $deadline = $invoice->expiresAt + SettlementService::EXPIRY_GRACE_SECONDS + 1;
    if ($timestamp > $deadline) {
      $timestamp = $deadline;
    }

    // Jitter can pull a timestamp behind the clock; a check due in the past
    // would be run immediately and burn an attempt for nothing.
    if ($timestamp <= $now) {
      $timestamp = $now + 1;
    }

    // No guard for timestamp past the deadline is needed here: once the clock
    // reaches it, poll() resolves the order as expired and this method is
    // never called.
    $this->scheduleAt($order->id(), $timestamp);

    return true;
  }

  private function scheduleAt(int $orderId, int $timestamp): void {
    $this->scheduler->scheduleSingle(
      $timestamp,
      self::HOOK,
      self::argsFor($orderId),
      self::GROUP
    );
  }
}
