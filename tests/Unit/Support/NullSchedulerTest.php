<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Support;

use Blink\WC\Support\NullScheduler;
use PHPUnit\Framework\TestCase;

/**
 * The fallback used when no background scheduler exists.
 *
 * Its whole contract is that it does nothing quietly, so that an unexpected
 * environment degrades to browser-driven settlement rather than fatalling in
 * the middle of a customer's checkout.
 */
final class NullSchedulerTest extends TestCase {
  private NullScheduler $scheduler;

  protected function setUp(): void {
    parent::setUp();
    $this->scheduler = new NullScheduler();
  }

  public function testItReportsItselfUnavailable(): void {
    $this->assertFalse($this->scheduler->isAvailable());
    $this->assertFalse($this->scheduler->health('hook', 'blink', time())->healthy());
  }

  public function testSchedulingIsAcceptedAndDiscarded(): void {
    $this->scheduler->scheduleSingle(1700000000, 'hook', [1], 'blink');

    $this->assertNull($this->scheduler->nextScheduled('hook', [1], 'blink'));
  }

  public function testUnschedulingIsHarmless(): void {
    $this->scheduler->unscheduleAll('hook', [1], 'blink');

    $this->assertNull($this->scheduler->nextScheduled('hook', [1], 'blink'));
  }
}
