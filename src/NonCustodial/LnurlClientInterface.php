<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

interface LnurlClientInterface {
  /** @return PayMetadata|LnurlFailure */
  public function fetchPayMetadata(LnAddress $address): PayMetadata|LnurlFailure;

  /** @return InvoiceOffer|LnurlFailure */
  public function requestInvoice(
    LnAddress $address,
    PayMetadata $metadata,
    int $amountMsat,
    string $comment
  ): InvoiceOffer|LnurlFailure;

  public function verify(string $verifyUrl, LnAddress $address): VerifyResult;
}
