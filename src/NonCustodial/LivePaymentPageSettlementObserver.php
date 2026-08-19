<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Preserves the established browser fallback used by BrowserOnly and Hybrid.
 *
 * The page still cannot bypass the cache, request budget, or the settlement
 * service's per-order lock. It merely supplies another trigger for the same
 * verification implementation used by the background worker.
 */
final class LivePaymentPageSettlementObserver implements
  PaymentPageSettlementObserverInterface {
  public function __construct(
    private SettlementService $settlement,
    private PollBudget $budget
  ) {}

  public function observe(OrderRecord $order, string $clientIp): SettlementOutcome {
    $useCached =
      $this->settlement->isCacheFresh($order) || !$this->budget->allowIp($clientIp);
    $outcome = $useCached
      ? $this->settlement->cached($order)
      : $this->settlement->poll($order);

    // An inconclusive live attempt must not erase the last real observation.
    return $outcome->status === SettlementStatus::Unknown
      ? $this->settlement->cached($order)
      : $outcome;
  }
}
