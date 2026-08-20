<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Counts events in a fixed window.
 *
 * Used to bound outbound LNURL traffic: the poll endpoint is public by
 * necessity (guests check out), so without a budget it can be used to
 * amplify requests against a third-party server.
 */
interface RateLimiterInterface {
  /** Records one hit and reports whether it stayed within the budget. */
  public function hit(string $bucket, int $limit, int $windowSeconds): bool;

  public function collectGarbage(): void;
}
