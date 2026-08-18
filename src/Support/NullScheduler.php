<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Used when no scheduler is available.
 *
 * Settlement then falls back to the customer's own browser poll, which is
 * worse but not broken -- so an unexpected environment degrades rather than
 * fatals during checkout.
 */
final class NullScheduler implements SchedulerInterface {
  public function isAvailable(): bool {
    return false;
  }

  public function scheduleSingle(int $timestamp, string $hook, array $args, string $group): void {}

  public function unscheduleAll(string $hook, array $args, string $group): void {}

  public function nextScheduled(string $hook, array $args, string $group): ?int {
    return null;
  }
}
