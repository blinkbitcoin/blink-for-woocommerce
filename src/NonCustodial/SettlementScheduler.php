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
 * Checks settle onto a short fixed interval after an initial fast pair. With
 * Action Scheduler's normal one-minute runner granularity, the interval keeps
 * a healthy store inside the two-minute observation target.
 */
final class SettlementScheduler {
  public const HOOK = 'blink_settle_noncustodial';
  public const GROUP = 'blink';

  /** Seconds after invoice creation at which checks fire, before jitter. */
  private const SCHEDULE = [20, 45];

  /** Interval used once the schedule above is exhausted. */
  private const TAIL_INTERVAL = 45;

  /**
   * The closest another check may ever be scheduled.
   *
   * Long enough to outlive the single-flight lock, so a check refused because
   * one was already in flight finds the lock free on its next run.
   */
  private const MIN_RETRY_INTERVAL = 60;

  public function __construct(
    private SchedulerInterface $scheduler,
    private SettlementService $settlement,
    private InvoiceRepository $repository,
    private SettlementOutcomeApplier $outcomeApplier,
    private ClockInterface $clock,
    private JitterInterface $jitter,
    private LoggerInterface $log,
    private SettlementModeProviderInterface $modeProvider
  ) {}

  /** @return array{int} */
  public static function argsFor(int $orderId): array {
    return [$orderId];
  }

  public function onInvoiceCreated(OrderRecord $order, StoredInvoice $invoice): void {
    // There is one chain per order. Replacing its first action keeps the new
    // invoice on a fresh timetable; the settlement service still checks every
    // payable predecessor stored against the order.
    $this->cancel($order->id());

    if (!$this->modeProvider->mode()->usesBackgroundWorker()) {
      return;
    }

    if (!$this->scheduler->isAvailable()) {
      $this->log->debug(
        'No background scheduler available; settlement will rely on the pay page only.'
      );

      return;
    }

    $this->scheduleAt(
      $order->id(),
      $this->clock->now() + $this->jitter->apply(self::SCHEDULE[0])
    );
  }

  /**
   * Guarantees a payable invoice is being watched, without disturbing a check
   * that already exists.
   *
   * Anything that resolves an order -- a status change, a settled payment --
   * tears the chain down, and until now only creating a *new* invoice ever
   * built one again. An order that went to `failed` and was then retried on a
   * still-valid invoice had nobody watching it, so a customer who paid and
   * closed the tab was never credited.
   *
   * Unlike onInvoiceCreated() this must not cancel first. That method replaces
   * a schedule made obsolete by a new invoice; here the existing schedule is
   * still correct, and re-arming unconditionally would push the next check
   * further away every time the customer reloaded the pay page.
   */
  public function ensureScheduled(OrderRecord $order): void {
    if (!$this->modeProvider->mode()->usesBackgroundWorker()) {
      $this->cancel($order->id());

      return;
    }

    if (!$this->scheduler->isAvailable()) {
      $this->log->debug(
        'No background scheduler available; settlement will rely on the pay page only.'
      );

      return;
    }

    if ($this->repository->terminalStatus($order) !== null) {
      return;
    }

    // Compared against null rather than tested for truth: nextScheduled()
    // reports a check that is due right now as 0, and treating that as "no
    // check" would queue a duplicate on every page load.
    if (
      $this->scheduler->nextScheduled(
        self::HOOK,
        self::argsFor($order->id()),
        self::GROUP
      ) !== null
    ) {
      return;
    }

    // Deliberately the same offset a brand new invoice gets, not the
    // createdAt-relative position in SCHEDULE: a customer who has just come
    // back to pay should be checked on promptly rather than dropped straight
    // into the five-minute tail.
    $this->scheduleAt(
      $order->id(),
      $this->clock->now() + $this->jitter->apply(self::SCHEDULE[0])
    );
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
    if (!$this->modeProvider->mode()->usesBackgroundWorker()) {
      $this->cancel($order->id());

      return false;
    }

    if (!$this->repository->isNonCustodial($order)) {
      return false;
    }

    if ($this->repository->terminalStatus($order) !== null) {
      return false;
    }

    $invoice = $this->repository->load($order);
    $tracked = $this->repository->tracked($order);
    if ($invoice === null || $tracked === []) {
      return false;
    }

    // Every other reason a check cannot be made is transient and worth
    // retrying. This one is not: the address is stored with the invoice and
    // will not become parseable, so polling it would repeat forever without
    // ever recording an attempt to exhaust.
    $hasUsableAddress = false;
    foreach ($tracked as $trackedInvoice) {
      if ($trackedInvoice->address() !== null) {
        $hasUsableAddress = true;
        break;
      }
    }
    if (!$hasUsableAddress) {
      $this->log->error(
        sprintf(
          'Order %d: the stored lightning address is unusable, so this order cannot be settled ' .
            'in the background. It has been left pending for manual review.',
          $order->id()
        )
      );

      return false;
    }

    // Arm the successor first. An unexpected exception after this point must
    // not silently kill the only mechanism watching an abandoned pay page.
    // Normal terminal and exhausted outcomes cancel it below.
    $this->scheduleNext($order, $invoice);

    $outcome = $this->settlement->pollAsBackgroundCheck($order);

    // Applying the outcome is what makes background settlement worth running:
    // without it the order would be resolved in meta but never actually move.
    $this->outcomeApplier->applyOutcome($order, $outcome);

    if ($outcome->terminal) {
      // Drop any remaining checks; the order will never change again.
      $this->cancel($order->id());

      return false;
    }

    if ($this->settlement->exhausted($order)) {
      $this->cancel($order->id());
      $this->log->error(
        sprintf(
          'Order %d: giving up on background settlement after repeated failures. The order has ' .
            'been left pending for manual review.',
          $order->id()
        )
      );

      return false;
    }

    return true;
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

    // Jitter belongs on the interval, not on the offset from createdAt. Applied
    // to the full age of the invoice, its spread grows on every check and can
    // push the worker outside its promised observation window.
    $timestamp = $invoice->createdAt + $elapsed + $this->jitter->apply($next - $elapsed);

    // Keep the chain alive for the longest-lived invoice. A replacement can
    // have a shorter lifetime than its predecessor, and neither may be
    // forgotten while it can still settle.
    $deadline = 0;
    foreach ($this->repository->tracked($order) as $trackedInvoice) {
      if ($trackedInvoice->expiresAt <= 0) {
        $deadline = 0;
        break;
      }

      $deadline = max(
        $deadline,
        $trackedInvoice->expiresAt + SettlementService::EXPIRY_GRACE_SECONDS + 1
      );
    }
    if ($deadline > 0 && $timestamp > $deadline) {
      $timestamp = $deadline;
    }

    // Jitter can pull a timestamp behind the clock, and past the deadline the
    // clamp above puts it there every time. Either way the next check has to
    // be a real interval away: a check due one second from now runs
    // immediately, and for an order the service cannot resolve -- a held lock,
    // a spent outbound budget -- that is an unbounded once-a-second loop
    // against a shop's Action Scheduler rather than a retry.
    if ($timestamp <= $now) {
      $timestamp = $now + self::MIN_RETRY_INTERVAL;
    }

    // No guard for a timestamp past the deadline is needed here. A conclusive
    // final answer resolves the order; an uncertain answer remains recoverable
    // and is retried at the interval above until the service gives up.
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
