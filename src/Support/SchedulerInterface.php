<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Schedules background work.
 *
 * Exists so settlement does not depend on a browser tab staying open, and so
 * the scheduling decisions can be asserted without running a queue.
 */
interface SchedulerInterface {
  public function isAvailable(): bool;

  /** @param array<int|string,mixed> $args */
  public function scheduleSingle(
    int $timestamp,
    string $hook,
    array $args,
    string $group
  ): void;

  /** @param array<int|string,mixed> $args */
  public function unscheduleAll(string $hook, array $args, string $group): void;

  /** @param array<int|string,mixed> $args */
  public function nextScheduled(string $hook, array $args, string $group): ?int;
}
