<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

/**
 * The fields of a BOLT11 invoice this plugin actually needs.
 */
final class DecodedInvoice {
  /** BOLT11's default expiry when the invoice carries no `x` tag. */
  public const DEFAULT_EXPIRY_SECONDS = 3600;

  public function __construct(
    public readonly string $network,
    public readonly ?int $amountMsat,
    public readonly int $timestamp,
    public readonly int $expirySeconds,
    public readonly ?string $paymentHash,
    public readonly ?string $descriptionHash,
    public readonly ?string $description
  ) {
  }

  public function expiresAt(): int {
    return $this->timestamp + $this->expirySeconds;
  }
}
