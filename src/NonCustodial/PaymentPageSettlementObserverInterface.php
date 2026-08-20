<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/** The payment page's narrow view of settlement orchestration. */
interface PaymentPageSettlementObserverInterface {
  public function observe(OrderRecord $order, string $clientIp): SettlementOutcome;
}
