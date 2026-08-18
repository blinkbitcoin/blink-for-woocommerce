<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\RateLimiterInterface;

final class ArrayRateLimiter implements RateLimiterInterface {
  /** @var array<string,int> */
  private array $counts = [];

  public int $gcCalls = 0;

  public function __construct(private ClockInterface $clock) {
  }

  public function hit(string $bucket, int $limit, int $windowSeconds): bool {
    if ($limit <= 0) {
      return false;
    }

    $windowSeconds = max(1, $windowSeconds);
    $key = $bucket . '_' . intdiv($this->clock->now(), $windowSeconds);
    $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

    return $this->counts[$key] <= $limit;
  }

  public function collectGarbage(): void {
    $this->gcCalls++;
    $this->counts = [];
  }
}
