<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/** WorkerOnly's read path: it is structurally incapable of making HTTP calls. */
final class CachedPaymentPageSettlementObserver implements
  PaymentPageSettlementObserverInterface {
  public function __construct(private SettlementService $settlement) {}

  public function observe(OrderRecord $order, string $clientIp): SettlementOutcome {
    return $this->settlement->cached($order);
  }
}
