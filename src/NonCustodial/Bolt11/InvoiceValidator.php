<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

use Blink\WC\Support\ClockInterface;

/**
 * Checks that an invoice returned by an LNURL server is the invoice that was
 * asked for.
 *
 * This is where the trust boundary is actually enforced. The plugin does not
 * verify the invoice signature (see Bolt11Decoder), so every guarantee it has
 * comes from binding the invoice to the request:
 *
 * - the amount must match exactly, or the customer underpays the order;
 * - the payment hash must match the verify URL, or settlement is checked
 *   against a different payment than the one the customer makes;
 * - the description must be bound to the LNURL metadata, or an interposed
 *   server can hand back a well-formed invoice payable to a third party;
 * - the expiry must be real and far enough away to be payable.
 *
 * Every check fails closed.
 */
final class InvoiceValidator {
  public function __construct(
    private Bolt11Decoder $decoder,
    private ClockInterface $clock
  ) {
  }

  public function validate(
    string $bolt11,
    InvoiceExpectation $expectation
  ): ValidationResult {
    try {
      $invoice = $this->decoder->decode($bolt11);
    } catch (Bolt11Exception $e) {
      return ValidationResult::fail('BOLT11_MALFORMED', $e->getMessage());
    }

    $allowedNetworks = $expectation->allowTestNetworks
      ? ['bc', 'tb', 'tbs', 'bcrt', 'sb']
      : ['bc'];
    if (!in_array($invoice->network, $allowedNetworks, true)) {
      return ValidationResult::fail(
        'BOLT11_WRONG_NETWORK',
        'invoice is for network ' . $invoice->network
      );
    }

    // An amountless invoice would let the customer's wallet choose what to
    // pay, which for an order total is never acceptable.
    if ($invoice->amountMsat === null) {
      return ValidationResult::fail(
        'BOLT11_NO_AMOUNT',
        'invoice does not specify an amount'
      );
    }

    if ($invoice->amountMsat !== $expectation->amountMsat) {
      return ValidationResult::fail(
        'BOLT11_AMOUNT_MISMATCH',
        sprintf(
          'invoice is for %d msat but %d msat was requested',
          $invoice->amountMsat,
          $expectation->amountMsat
        )
      );
    }

    if ($invoice->paymentHash === null) {
      return ValidationResult::fail('BOLT11_NO_HASH', 'invoice carries no payment hash');
    }

    // Skipping this when the hash could not be read from the verify URL would
    // leave nothing tying that URL to the invoice on screen. A verify URL
    // pointing at a different, already-settled invoice answers "settled" with
    // no preimage, which settlement tolerates by design, and the order would
    // complete without a payment.
    if ($expectation->verifyUrlPaymentHash === null) {
      return ValidationResult::fail(
        'BOLT11_HASH_UNBINDABLE',
        'verify URL carries no payment hash to check the invoice against'
      );
    }

    if (!hash_equals($expectation->verifyUrlPaymentHash, $invoice->paymentHash)) {
      return ValidationResult::fail(
        'BOLT11_HASH_MISMATCH',
        'invoice payment hash does not match the verify URL'
      );
    }

    $descriptionCheck = $this->checkDescriptionBinding($invoice, $expectation);
    if ($descriptionCheck !== null) {
      return $descriptionCheck;
    }

    $now = $this->clock->now();

    // A little tolerance for clock skew between the shop and the LNURL server.
    if ($invoice->timestamp > $now + 300) {
      return ValidationResult::fail(
        'BOLT11_FUTURE',
        'invoice is timestamped in the future'
      );
    }

    // Never stop tracking an invoice while it remains payable. An invoice
    // longer than the shop is willing to hold the order open is refused before
    // it can be shown to the customer.
    if ($invoice->expirySeconds > $expectation->maxExpirySeconds) {
      return ValidationResult::fail(
        'BOLT11_TOO_LONG',
        'invoice expiry exceeds the maximum supported lifetime'
      );
    }

    $expiresAt = $invoice->expiresAt();

    if ($expiresAt - $now < $expectation->minRemainingSeconds) {
      return ValidationResult::fail(
        'BOLT11_TOO_SHORT',
        'invoice expires too soon to be payable'
      );
    }

    return ValidationResult::ok($invoice, $expiresAt);
  }

  /**
   * LUD-06 requires the invoice description (or its hash) to equal the
   * metadata string the server advertised. Without this an interposed server
   * can substitute an invoice that is well formed in every other respect.
   */
  private function checkDescriptionBinding(
    DecodedInvoice $invoice,
    InvoiceExpectation $expectation
  ): ?ValidationResult {
    if (!$expectation->requireDescriptionBinding) {
      return null;
    }

    if ($invoice->descriptionHash !== null) {
      $expected = hash('sha256', $expectation->lnurlMetadata);

      return hash_equals($expected, $invoice->descriptionHash)
        ? null
        : ValidationResult::fail(
          'BOLT11_DESC_MISMATCH',
          'invoice description hash does not match the LNURL metadata'
        );
    }

    if ($invoice->description !== null) {
      return hash_equals($expectation->lnurlMetadata, $invoice->description)
        ? null
        : ValidationResult::fail(
          'BOLT11_DESC_MISMATCH',
          'invoice description does not match the LNURL metadata'
        );
    }

    return ValidationResult::fail(
      'BOLT11_DESC_ABSENT',
      'invoice is not bound to the LNURL metadata'
    );
  }
}
