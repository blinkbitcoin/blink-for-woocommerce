<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\NonCustodial\OrderRecord;
use Blink\WC\NonCustodial\SettlementOutcome;
use Blink\WC\NonCustodial\SettlementOutcomeApplier;

final class RecordingOutcomeApplier implements SettlementOutcomeApplier {
  /** @var list<array{orderId:int,outcome:SettlementOutcome}> */
  public array $applied = [];

  public function applyOutcome(OrderRecord $order, SettlementOutcome $outcome): void {
    $this->applied[] = ['orderId' => $order->id(), 'outcome' => $outcome];
  }
}
