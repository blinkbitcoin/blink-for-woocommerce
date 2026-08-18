<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * LUD-06 pay metadata for a Lightning address.
 */
final class PayMetadata {
  public function __construct(
    public readonly string $callback,
    public readonly int $minSendable,
    public readonly int $maxSendable,
    public readonly int $commentAllowed,
    public readonly string $metadata
  ) {
  }

  public function permits(int $amountMsat): bool {
    if ($this->minSendable > 0 && $amountMsat < $this->minSendable) {
      return false;
    }

    return !($this->maxSendable > 0 && $amountMsat > $this->maxSendable);
  }
}
