<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

/**
 * The outcome of validating an invoice.
 *
 * A valid result always carries a decoded invoice, but that was previously only
 * a convention: the property is nullable, so every caller reading
 * `$result->invoice->paymentHash` after checking `$result->valid` was, as far
 * as the type system knew, dereferencing a possible null. isValid() states the
 * invariant in a form that is actually checked.
 */
final class ValidationResult {
  private function __construct(
    public readonly bool $valid,
    public readonly string $code,
    public readonly string $message,
    public readonly ?DecodedInvoice $invoice,
    public readonly int $expiresAt
  ) {
  }

  /**
   * Whether validation succeeded, and therefore whether $invoice is present.
   *
   * @phpstan-assert-if-true !null $this->invoice
   */
  public function isValid(): bool {
    return $this->valid;
  }

  public static function ok(DecodedInvoice $invoice, int $expiresAt): self {
    return new self(true, '', '', $invoice, $expiresAt);
  }

  public static function fail(string $code, string $message): self {
    return new self(false, $code, $message, null, 0);
  }
}
