<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

final class SettlementOutcome {
  public function __construct(
    public readonly SettlementStatus $status,
    public readonly int $observedAt,
    public readonly string $reason = '',
    public readonly bool $terminal = false
  ) {}

  public function isPaid(): bool {
    return $this->status === SettlementStatus::Paid;
  }
}
