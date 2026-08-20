<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Support;

use Blink\WC\Support\SchedulerHealth;
use PHPUnit\Framework\TestCase;

final class SchedulerHealthTest extends TestCase {
  public function testOnlyAnAvailableQueueWithoutProblemsIsHealthy(): void {
    $this->assertTrue((new SchedulerHealth(true))->healthy());
    $this->assertFalse((new SchedulerHealth(false))->healthy());
    $this->assertFalse((new SchedulerHealth(true, true, false))->healthy());
    $this->assertFalse((new SchedulerHealth(true, false, true))->healthy());
  }
}
