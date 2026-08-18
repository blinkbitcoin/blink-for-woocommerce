<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

final class ValidationResult {
  private function __construct(
    public readonly bool $valid,
    public readonly string $code,
    public readonly string $message,
    public readonly ?DecodedInvoice $invoice,
    public readonly int $expiresAt
  ) {
  }

  public static function ok(DecodedInvoice $invoice, int $expiresAt): self {
    return new self(true, '', '', $invoice, $expiresAt);
  }

  public static function fail(string $code, string $message): self {
    return new self(false, $code, $message, null, 0);
  }
}
