<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Adapter onto Action Scheduler, which ships inside WooCommerce.
 *
 * WooCommerce is a hard requirement of this plugin, so Action Scheduler is
 * effectively always present. It is preferred over WP-Cron because WP-Cron
 * only advances when someone visits the site, which is precisely the situation
 * background settlement exists to survive.
 *
 * The methods below call the Action Scheduler API unguarded. Absence is
 * handled once, at selection time: Services::scheduler() hands back a
 * NullScheduler unless isAvailable() is true, so an instance of this class
 * only ever exists where the functions do. Repeating the check in every method
 * added branches that no test could reach, because a loaded function cannot be
 * unloaded.
 */
final class ActionSchedulerAdapter implements SchedulerInterface {
  public function isAvailable(): bool {
    return function_exists('as_schedule_single_action') &&
      function_exists('as_unschedule_all_actions') &&
      function_exists('as_next_scheduled_action');
  }

  public function scheduleSingle(
    int $timestamp,
    string $hook,
    array $args,
    string $group
  ): void {
    as_schedule_single_action($timestamp, $hook, $args, $group);
  }

  public function unscheduleAll(string $hook, array $args, string $group): void {
    as_unschedule_all_actions($hook, $args, $group);
  }

  public function nextScheduled(string $hook, array $args, string $group): ?int {
    $next = as_next_scheduled_action($hook, $args, $group);

    // Action Scheduler returns true for an action due now, false for none.
    if ($next === false) {
      return null;
    }
    if ($next === true) {
      return 0;
    }

    return (int) $next;
  }
}
