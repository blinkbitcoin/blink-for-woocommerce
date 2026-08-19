<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\NonCustodial\Bolt11\InvoiceExpectation;
use Blink\WC\NonCustodial\Bolt11\InvoiceValidator;
use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\LoggerInterface;

/**
 * Creates a non-custodial invoice for an order.
 *
 * The sequence matters: convert the total, fetch the address's terms, ask for
 * an invoice, and only then decide whether what came back is acceptable. The
 * invoice is never stored before it has been validated, so an order can never
 * end up displaying a QR code the plugin has not checked.
 */
final class InvoiceFactory {
  /** Longest invoice lifetime this plugin will hold an order open for. */
  public const MAX_EXPIRY_SECONDS = 3600;

  /**
   * @param bool $requireDescriptionBinding Real LNURL servers vary in how they
   *   echo metadata, and a hard failure would break checkout for some
   *   providers, so this is configurable per site. It is decided at wiring
   *   time rather than read here, which keeps this class free of WordPress.
   */
  public function __construct(
    private LnurlClientInterface $lnurl,
    private SatsRateProviderInterface $rates,
    private InvoiceValidator $validator,
    private ClockInterface $clock,
    private LoggerInterface $log,
    private bool $requireDescriptionBinding = true
  ) {}

  /**
   * @return StoredInvoice|LnurlFailure
   */
  public function create(
    LnAddress $address,
    float $amount,
    string $currency,
    string $orderTotal,
    string $orderCurrency,
    string $comment
  ): StoredInvoice|LnurlFailure {
    $satoshis = $this->rates->toSatoshis($amount, $currency);
    if ($satoshis === null || $satoshis <= 0) {
      return $this->fail(
        'RATE_UNAVAILABLE',
        'could not convert the order total to satoshis'
      );
    }

    $metadata = $this->lnurl->fetchPayMetadata($address);
    if ($metadata instanceof LnurlFailure) {
      return $metadata;
    }

    $amountMsat = $satoshis * 1000;

    $offer = $this->lnurl->requestInvoice($address, $metadata, $amountMsat, $comment);
    if ($offer instanceof LnurlFailure) {
      return $offer;
    }

    $expectation = new InvoiceExpectation(
      $amountMsat,
      $metadata->metadata,
      $offer->verifyUrlPaymentHash,
      self::MAX_EXPIRY_SECONDS,
      120,
      $address->isLocalDev(),
      $this->requireDescriptionBinding
    );

    $validation = $this->validator->validate($offer->paymentRequest, $expectation);
    if (!$validation->isValid()) {
      return $this->fail($validation->code, $validation->message);
    }

    $invoice = $validation->invoice;

    return new StoredInvoice(
      (string) $invoice->paymentHash,
      $offer->paymentRequest,
      $offer->verifyUrl,
      (string) $address,
      $amountMsat,
      $satoshis,
      $this->clock->now(),
      $validation->expiresAt,
      $orderTotal,
      $orderCurrency
    );
  }

  private function fail(string $code, string $message): LnurlFailure {
    $this->log->error('Invoice creation failed [' . $code . '] ' . $message);

    return new LnurlFailure($code, $message);
  }
}
