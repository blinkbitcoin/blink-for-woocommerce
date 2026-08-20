<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\SchedulerInterface;
use Blink\WC\Support\SchedulerHealth;

/**
 * Records scheduling decisions without running anything.
 *
 * Scheduling and execution are separate concerns: this proves the plugin asked
 * for the right work at the right time, while the integration suite proves
 * Action Scheduler actually runs it.
 */
final class FakeScheduler implements SchedulerInterface {
  /** @var list<array{timestamp:int,hook:string,args:array,group:string}> */
  public array $scheduled = [];

  /** @var list<array{hook:string,args:array,group:string}> */
  public array $unscheduled = [];

  public function __construct(private bool $available = true) {}

  public function isAvailable(): bool {
    return $this->available;
  }

  public function health(
    string $hook,
    string $group,
    int $overdueBefore
  ): SchedulerHealth {
    return new SchedulerHealth($this->available);
  }

  public function scheduleSingle(
    int $timestamp,
    string $hook,
    array $args,
    string $group
  ): void {
    if (!$this->available) {
      return;
    }
    $this->scheduled[] = compact('timestamp', 'hook', 'args', 'group');
  }

  public function unscheduleAll(string $hook, array $args, string $group): void {
    $this->unscheduled[] = compact('hook', 'args', 'group');
    $this->scheduled = array_values(
      array_filter(
        $this->scheduled,
        static fn(array $a): bool => !(
          $a['hook'] === $hook &&
          $a['args'] === $args &&
          $a['group'] === $group
        )
      )
    );
  }

  public function nextScheduled(string $hook, array $args, string $group): ?int {
    $times = [];
    foreach ($this->scheduled as $action) {
      if (
        $action['hook'] === $hook &&
        $action['args'] === $args &&
        $action['group'] === $group
      ) {
        $times[] = $action['timestamp'];
      }
    }

    return $times === [] ? null : min($times);
  }

  public function scheduledTimestamps(): array {
    return array_map(static fn(array $a): int => $a['timestamp'], $this->scheduled);
  }
}
