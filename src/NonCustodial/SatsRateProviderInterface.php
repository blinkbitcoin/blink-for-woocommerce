<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Converts an order total into satoshis.
 *
 * Behind an interface so invoice creation can be tested without a GraphQL
 * call, and so the rate source can be replaced without touching the flow.
 */
interface SatsRateProviderInterface {
  /**
   * @return int|null satoshis, or null when no rate could be obtained.
   */
  public function toSatoshis(float $amount, string $currency): ?int;
}
