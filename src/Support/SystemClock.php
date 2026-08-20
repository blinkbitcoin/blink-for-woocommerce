<?php

declare(strict_types=1);

namespace Blink\WC\Support;

final class SystemClock implements ClockInterface {
  public function now(): int {
    return time();
  }
}
