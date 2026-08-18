<?php
/**
 * Action Scheduler function signatures, for static analysis only.
 *
 * Action Scheduler ships inside WooCommerce but is absent from
 * php-stubs/woocommerce-stubs, so without these the adapter's calls read as
 * undefined functions. This file is never loaded at runtime -- it is listed in
 * phpstan.neon.dist's scanFiles.
 *
 * @see src/Support/ActionSchedulerAdapter.php
 */

declare(strict_types=1);

/**
 * @param array<int|string,mixed> $args
 */
function as_schedule_single_action(
  int $timestamp,
  string $hook,
  array $args = [],
  string $group = '',
  bool $unique = false,
  int $priority = 10
): int {
}

/**
 * @param array<int|string,mixed>|null $args
 */
function as_unschedule_all_actions(
  string $hook,
  ?array $args = [],
  string $group = ''
): void {
}

/**
 * @param array<int|string,mixed>|null $args
 * @return int|bool Timestamp of the next action, true if due now, false if none.
 */
function as_next_scheduled_action(string $hook, ?array $args = null, string $group = '') {
}
