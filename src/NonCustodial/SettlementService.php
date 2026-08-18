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

  public const MAX_ATTEMPTS = 60;

  public const MAX_CONSECUTIVE_ERRORS = 8;

  public function __construct(
    private LnurlClientInterface $lnurl,
    private InvoiceRepository $repository,
    private PollBudget $budget,
    private LockInterface $lock,
    private ClockInterface $clock,
    private LoggerInterface $log
  ) {
  }

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
   * Checks the invoice, taking the single-flight lock for the duration.
   */
  public function poll(OrderRecord $order): SettlementOutcome {
    $terminal = $this->repository->terminalStatus($order);
    if ($terminal !== null) {
      return new SettlementOutcome(
        $terminal,
        $this->clock->now(),
        'already resolved',
        true
      );
    }

    $invoice = $this->repository->load($order);
    if ($invoice === null) {
      return $this->unknown('no invoice stored');
    }

    $address = $invoice->address();
    if ($address === null) {
      return $this->unknown('stored lightning address is unusable');
    }

    // Resolve expiry without a network call once the window has clearly passed.
    //
    // Only when the verify endpoint is actually answering, though: if the most
    // recent checks all failed, the payment status is genuinely unknown, and
    // cancelling on the clock alone would do exactly what this class exists to
    // prevent -- cancel an order the customer paid for while the endpoint
    // happened to be unreachable.
    $now = $this->clock->now();
    if (
      $invoice->expiresAt > 0 &&
      $now > $invoice->expiresAt + self::EXPIRY_GRACE_SECONDS
    ) {
      if ($this->repository->consecutiveErrors($order) === 0) {
        return $this->expire($order, 'invoice expired');
      }

      return $this->unknown('invoice expired, but its status was never confirmed');
    }

    if ($this->exhausted($order)) {
      return $this->unknown('polling budget for this order is exhausted');
    }

    if (!$this->budget->allowOutbound($address->host)) {
      return $this->unknown('outbound request budget reached');
    }

    $lockKey = 'verify_' . $order->id();
    $token = $this->lock->acquire($lockKey, self::LOCK_TTL);
    if ($token === null) {
      return $this->unknown('another request for this order is already in flight');
    }

    try {
      $result = $this->lnurl->verify($invoice->verifyUrl, $address);
      $this->repository->recordAttempt($order, !$result->state->isConclusive());

      // Written as a chain rather than a match: match on an enum still emits
      // an unhandled-case arm that can never run, which no test can cover.
      if ($result->state === VerifyState::Settled) {
        $outcome = $this->settle($order, $invoice, $result);
      } elseif ($result->state === VerifyState::Unsettled) {
        $outcome = new SettlementOutcome(SettlementStatus::Pending, $this->clock->now());
      } elseif ($result->state === VerifyState::NotFound) {
        $outcome = $this->onNotFound($order, $invoice, $result);
      } else {
        // Transport and policy failures tell us nothing about the payment.
        $outcome = new SettlementOutcome(
          SettlementStatus::Pending,
          $this->clock->now(),
          $result->detail
        );
      }
    } finally {
      // Released even when verify() throws, so a failure cannot wedge the
      // order for the whole lock lifetime.
      $this->lock->release($lockKey, $token);
    }

    $this->repository->cacheStatus($order, $outcome);

    return $outcome;
  }

  /**
   * A server that has forgotten the invoice before it expired is more likely
   * to be having a bad day than to be telling us the invoice is dead, so this
   * only expires an order once the window has passed anyway.
   */
  private function onNotFound(
    OrderRecord $order,
    StoredInvoice $invoice,
    VerifyResult $result
  ): SettlementOutcome {
    if ($invoice->expiresAt > 0 && $this->clock->now() > $invoice->expiresAt) {
      return $this->expire($order, 'verify endpoint no longer knows this invoice');
    }

    return new SettlementOutcome(
      SettlementStatus::Pending,
      $this->clock->now(),
      'not found before expiry: ' . $result->detail
    );
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
      $this->repository->markSettled($order, $result->preimage);
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
    $this->repository->markSettled($order, $result->preimage);
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
