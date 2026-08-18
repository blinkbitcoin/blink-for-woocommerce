<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\ClockInterface;

/**
 * A clock the test moves by hand.
 *
 * Expiry, lock TTLs and the retry schedule are all time-dependent, and the
 * only honest way to test the boundaries is to stand exactly on them.
 */
final class FakeClock implements ClockInterface {
  public function __construct(private int $now = 1700000000) {
  }

  public function now(): int {
    return $this->now;
  }

  public function freezeAt(int $timestamp): self {
    $this->now = $timestamp;

    return $this;
  }

  public function travel(int $seconds): self {
    $this->now += $seconds;

    return $this;
  }
}
