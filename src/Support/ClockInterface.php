<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Reads the current time.
 *
 * Production code must never call time() directly: invoice expiry, lock TTLs
 * and the retry schedule all branch on the clock, and those branches can only
 * be tested deterministically if the clock can be moved.
 */
interface ClockInterface {
  /** Current Unix timestamp in seconds. */
  public function now(): int;
}
