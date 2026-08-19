<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * What an LNURL callback returned: an invoice, and a URL to poll for its
 * settlement (LUD-21).
 */
final class InvoiceOffer {
  public function __construct(
    public readonly string $paymentRequest,
    public readonly string $verifyUrl,
    public readonly ?string $verifyUrlPaymentHash
  ) {}
}
