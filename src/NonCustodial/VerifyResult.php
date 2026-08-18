<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

final class VerifyResult {
  public function __construct(
    public readonly VerifyState $state,
    public readonly ?string $preimage = null,
    public readonly ?string $paymentRequest = null,
    public readonly string $detail = ''
  ) {
  }

  public static function of(VerifyState $state, string $detail = ''): self {
    return new self($state, null, null, $detail);
  }
}
