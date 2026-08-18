<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Support;

use Blink\WC\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase {
  public function testReturnsTheCurrentUnixTimestamp(): void {
    $before = time();
    $now = (new SystemClock())->now();
    $after = time();

    $this->assertGreaterThanOrEqual($before, $now);
    $this->assertLessThanOrEqual($after, $now);
  }
}
