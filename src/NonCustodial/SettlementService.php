<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\LockInterface;
use Blink\WC\Support\LoggerInterface;

/**
 * Decides whether a non-custodial order has been paid.
 *
 * Two rules shape everything here:
 *
 * 1. Never expire an order on uncertainty. A timeout, a 5xx or a rejected URL
 *    says nothing about whether the customer paid, so it can only ever produce
 *    Pending. Only an observed unpaid state past the expiry window, or a
 *    definitive "no such invoice" after expiry, may expire an order. Losing
 *    track of a payment is recoverable; cancelling an order the customer paid
 *    for is not.
 *
 * 2. Only one verify request per order at a time. The lock's lifetime exceeds
 *    the HTTP timeout of the request it guards, so a slow response cannot let
 *    a second caller through while the first is still waiting.
 */
final class SettlementService {
  /** Must exceed the HTTP timeout of the request it guards. */
  public const LOCK_TTL = 30;

  /** How long a cached observation is served before a fresh check is made. */
  public const CACHE_FRESH_SECONDS = 20;

  /** Grace after expiry, for clock skew and a last look. */
  public const EXPIRY_GRACE_SECONDS = 90;

  /**
   * A backstop, not a working budget.
   *
   * The scheduler's own timetable -- thirteen offsets plus a five-minute tail,
   * against invoices capped at an hour -- tops out around fifteen checks, so a
   * healthy order comes nowhere near this. It is here to bound a future path
   * that reschedules faster than intended. It is deliberately not a defence
   * against frequent polling: that belongs to PollBudget and the status cache.
   */
  public const MAX_ATTEMPTS = 60;

  public const MAX_CONSECUTIVE_ERRORS = 8;

  public function __construct(
    private LnurlClientInterface $lnurl,
    private InvoiceRepository $repository,
    private PollBudget $budget,
    private LockInterface $lock,
    private ClockInterface $clock,
    private LoggerInterface $log
  ) {}

  /** The last observation, without any network access. */
  public function cached(OrderRecord $order): SettlementOutcome {
    return $this->repository->cachedStatus($order) ??
      new SettlementOutcome(
        SettlementStatus::Pending,
        $this->clock->now(),
        'no observation yet'
      );
  }

  public function isCacheFresh(OrderRecord $order): bool {
    $cached = $this->repository->cachedStatus($order);

    return $cached !== null &&
      $this->clock->now() - $cached->observedAt < self::CACHE_FRESH_SECONDS;
  }

  /**
   * Checks the invoice on behalf of the customer's pay page.
   *
   * Reports status and nothing more: it may not spend the budget that decides
   * when the background job gives up. The two callers ask different questions
   * -- "what is the status right now?" versus "have I done enough work on this
   * order?" -- and answering the second with the first's traffic is what let a
   * pay page left open kill background settlement mid-invoice.
   */
  public function poll(OrderRecord $order): SettlementOutcome {
    return $this->check($order, false);
  }

  /**
   * Checks the invoice on behalf of the scheduler.
   *
   * The only kind of check that counts towards giving up.
   */
  public function pollAsBackgroundCheck(OrderRecord $order): SettlementOutcome {
    // The ceiling belongs here rather than in check(): these counters exist to
    // decide when the *background job* gives up. Applying them to every caller
    // meant a run of transport errors also silenced the customer's pay page,
    // and since only a successful foreground check clears the error count, it
    // could never recover -- a customer paying after the provider came back
    // watched a page spin forever and nobody credited the order.
    if ($this->exhausted($order)) {
      return $this->unknown('polling budget for this order is exhausted');
    }

    return $this->check($order, true);
  }

  /**
   * Checks the invoice, taking the single-flight lock for the duration.
   */
  private function check(OrderRecord $order, bool $isBackgroundCheck): SettlementOutcome {
    $terminal = $this->repository->terminalStatus($order);
    if ($terminal !== null) {
      return new SettlementOutcome(
        $terminal,
        $this->clock->now(),
        'already resolved',
        true
      );
    }

    $current = $this->repository->load($order);
    $invoices = $this->repository->tracked($order);
    if ($current === null || $invoices === []) {
      return $this->unknown('no invoice stored');
    }

    $lockKey = 'verify_' . $order->id();
    $token = $this->lock->acquire($lockKey, self::LOCK_TTL);
    if ($token === null) {
      return $this->unknown('another request for this order is already in flight');
    }

    $madeRequest = false;
    $hadConclusiveResponse = false;
    $hasUnresolvedInvoice = false;
    $currentIsUnpayable = false;
    $detail = '';
    $outcome = null;

    try {
      // Replaced invoices come first: they expire first and are the invoices
      // most likely to be open in an older browser tab.
      foreach ($invoices as $invoice) {
        $address = $invoice->address();
        if ($address === null) {
          $hasUnresolvedInvoice = true;
          $detail = 'stored lightning address is unusable';
          continue;
        }

        if (!$this->budget->allowOutbound($address->host)) {
          $hasUnresolvedInvoice = true;
          $detail = 'outbound request budget reached';
          break;
        }

        $madeRequest = true;
        $result = $this->lnurl->verify($invoice->verifyUrl, $address);
        $hadConclusiveResponse = $hadConclusiveResponse || $result->state->isConclusive();

        if ($result->state === VerifyState::Settled) {
          $outcome = $this->settle($order, $invoice, $result);
          break;
        }

        if ($this->isDefinitivelyUnpayable($invoice, $result)) {
          if (hash_equals($current->paymentHash, $invoice->paymentHash)) {
            $currentIsUnpayable = true;
          } else {
            $this->repository->removeOutstanding($order, $invoice->paymentHash);
          }
          continue;
        }

        $hasUnresolvedInvoice = true;
        if ($result->detail !== '') {
          $detail = $result->detail;
        }
      }

      if ($outcome === null) {
        if (!$madeRequest) {
          $outcome = $this->unknown($detail);
        } elseif (
          $currentIsUnpayable &&
          !$hasUnresolvedInvoice &&
          $this->repository->outstanding($order) === []
        ) {
          $outcome = $this->expire($order, 'all invoices expired and remain unpaid');
        } else {
          $outcome = new SettlementOutcome(
            SettlementStatus::Pending,
            $this->clock->now(),
            $detail
          );
        }
      }
    } finally {
      // Released even when verify() throws, so a failure cannot wedge the
      // order for the whole lock lifetime.
      $this->lock->release($lockKey, $token);
    }

    // One scheduler tick is one attempt even when an order has several
    // invoices. Replacement must not consume the backstop budget faster.
    if ($madeRequest) {
      if ($isBackgroundCheck) {
        $this->repository->recordAttempt($order, !$hadConclusiveResponse);
      } elseif ($hadConclusiveResponse) {
        $this->repository->recordEndpointReachable($order);
      }
    }

    $this->repository->cacheStatus($order, $outcome);

    return $outcome;
  }

  private function isDefinitivelyUnpayable(
    StoredInvoice $invoice,
    VerifyResult $result
  ): bool {
    if ($invoice->expiresAt <= 0) {
      return false;
    }

    if ($result->state === VerifyState::Unsettled) {
      return $this->clock->now() > $invoice->expiresAt + self::EXPIRY_GRACE_SECONDS;
    }

    return $result->state === VerifyState::NotFound &&
      $this->clock->now() > $invoice->expiresAt;
  }

  private function settle(
    OrderRecord $order,
    StoredInvoice $invoice,
    VerifyResult $result
  ): SettlementOutcome {
    // A preimage is proof: it must hash to the payment hash the invoice named.
    // Servers are not required to return one, so its absence is noted rather
    // than treated as failure, but a wrong one is refused outright.
    if ($result->preimage !== null && $result->preimage !== '') {
      if (!$this->preimageMatches($result->preimage, $invoice->paymentHash)) {
        $this->log->error(
          sprintf(
            'Order %d: verify reported settled but the preimage does not hash to the payment ' .
              'hash. Refusing to complete the order.',
            $order->id()
          )
        );

        return new SettlementOutcome(
          SettlementStatus::Pending,
          $this->clock->now(),
          'preimage mismatch'
        );
      }
    } else {
      $this->log->debug(
        sprintf(
          'Order %d: settled without a preimage; nothing to verify against.',
          $order->id()
        )
      );
    }

    // An order edited between invoice creation and payment would otherwise be
    // completed for an amount nobody agreed to.
    if (!$this->repository->totalsUnchanged($order, $invoice)) {
      $this->log->error(
        sprintf(
          'Order %d: total changed after the invoice was created; holding for review.',
          $order->id()
        )
      );
      $this->repository->markSettled($order, $invoice->paymentHash, $result->preimage);
      $this->repository->markTerminal($order, SettlementStatus::Review);

      return new SettlementOutcome(
        SettlementStatus::Review,
        $this->clock->now(),
        'order total changed after invoice creation',
        true
      );
    }

    // The latch: the scheduler tick and the browser can both see this payment.
    // Reaching here twice is impossible -- poll() returns early once the order
    // is terminal -- but the latch still guards against a caller that skips it.
    $this->repository->markSettled($order, $invoice->paymentHash, $result->preimage);
    $this->repository->markTerminal($order, SettlementStatus::Paid);

    return new SettlementOutcome(
      SettlementStatus::Paid,
      $this->clock->now(),
      'settled',
      true
    );
  }

  private function preimageMatches(string $preimage, string $paymentHash): bool {
    $binary = @hex2bin($preimage);
    if ($binary === false) {
      return false;
    }

    return hash_equals(strtolower($paymentHash), hash('sha256', $binary));
  }

  private function expire(OrderRecord $order, string $reason): SettlementOutcome {
    $this->repository->markTerminal($order, SettlementStatus::Expired);
    $outcome = new SettlementOutcome(
      SettlementStatus::Expired,
      $this->clock->now(),
      $reason,
      true
    );
    $this->repository->cacheStatus($order, $outcome);

    return $outcome;
  }

  /**
   * Whether this order has used up its allowance.
   *
   * Hitting either ceiling stops polling but deliberately leaves the order
   * alone: a shop whose verify endpoint is down should end up with orders
   * needing attention, not with orders wrongly cancelled.
   */
  public function exhausted(OrderRecord $order): bool {
    return $this->repository->attempts($order) >= self::MAX_ATTEMPTS ||
      $this->repository->consecutiveErrors($order) >= self::MAX_CONSECUTIVE_ERRORS;
  }

  private function unknown(string $reason): SettlementOutcome {
    return new SettlementOutcome(SettlementStatus::Unknown, $this->clock->now(), $reason);
  }
}
