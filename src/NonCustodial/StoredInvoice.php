<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Everything about a non-custodial invoice that outlives the request which
 * created it.
 *
 * The Lightning address is stored alongside the invoice rather than read back
 * from settings, so that changing the shop's configuration cannot strand an
 * order that is already in flight.
 */
final class StoredInvoice {
  public function __construct(
    public readonly string $paymentHash,
    public readonly string $paymentRequest,
    public readonly string $verifyUrl,
    public readonly string $lnAddress,
    public readonly int $amountMsat,
    public readonly int $satoshis,
    public readonly int $createdAt,
    public readonly int $expiresAt,
    public readonly string $orderTotal,
    public readonly string $orderCurrency
  ) {
  }

  public function address(): ?LnAddress {
    return LnAddress::parse($this->lnAddress);
  }
}
