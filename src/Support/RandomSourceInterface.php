<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Source of randomness, injected so retry jitter is reproducible under test.
 */
interface RandomSourceInterface {
  /** A float in the half-open range [0, 1). */
  public function float(): float;
}
