<?php

declare(strict_types=1);

namespace Blink\WC\Support;

final class WpRandomSource implements RandomSourceInterface {
  private const PRECISION = 1000000;

  public function float(): float {
    return wp_rand(0, self::PRECISION - 1) / self::PRECISION;
  }
}
