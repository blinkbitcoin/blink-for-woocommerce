<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Spreads retry timings so that many shops -- or many orders on one shop --
 * do not hit the same LNURL server in lockstep.
 */
interface JitterInterface {
  /**
   * Returns $seconds scaled by a random factor within +/- $factor.
   *
   * The result is never negative and never zero for a positive input, so a
   * jittered delay can not collapse into a busy loop.
   */
  public function apply(int $seconds, float $factor = 0.25): int;
}
