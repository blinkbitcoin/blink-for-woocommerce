<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/** Read-only queue health used by administration and diagnostics. */
final class SchedulerHealth {
  public function __construct(
    public readonly bool $available,
    public readonly bool $hasFailedActions = false,
    public readonly bool $hasOverdueActions = false
  ) {}

  public function healthy(): bool {
    return $this->available && !$this->hasFailedActions && !$this->hasOverdueActions;
  }
}
