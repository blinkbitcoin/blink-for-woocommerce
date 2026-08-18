<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

/**
 * What the plugin asked the LNURL server for, and therefore what the invoice
 * it returns has to match.
 */
final class InvoiceExpectation {
  public function __construct(
    public readonly int $amountMsat,
    public readonly string $lnurlMetadata,
    // Nullable only so the caller can report "the server's verify URL had no
    // hash in it". Validation refuses that case rather than skipping the
    // binding check.
    public readonly ?string $verifyUrlPaymentHash = null,
    public readonly int $maxExpirySeconds = 3600,
    public readonly int $minRemainingSeconds = 120,
    public readonly bool $allowTestNetworks = false,
    public readonly bool $requireDescriptionBinding = true
  ) {
  }
}
