<?php

declare(strict_types=1);

namespace Blink\WC\Support;

final class RandomJitter implements JitterInterface {
  public function __construct(private RandomSourceInterface $random) {}

  public function apply(int $seconds, float $factor = 0.25): int {
    if ($seconds <= 0) {
      return 0;
    }

    $factor = max(0.0, min(1.0, $factor));
    $spread = $seconds * $factor;
    // random() yields [0,1); map it onto [-spread, +spread].
    $offset = ($this->random->float() * 2 * $spread) - $spread;

    return max(1, (int) round($seconds + $offset));
  }
}
