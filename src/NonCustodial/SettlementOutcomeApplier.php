<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Applies a settled outcome to whatever the order lives in.
 *
 * Keeps the scheduler and the settlement service free of WooCommerce while
 * still letting background settlement actually move the order, which is the
 * entire point of running it.
 */
interface SettlementOutcomeApplier {
  public function applyOutcome(OrderRecord $order, SettlementOutcome $outcome): void;
}
