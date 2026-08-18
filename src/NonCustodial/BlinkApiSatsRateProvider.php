<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Helpers\BlinkApiClient;
use Blink\WC\Support\LoggerInterface;

/**
 * Uses Blink's public currency conversion query.
 *
 * This query needs no authentication, which is what allows a non-custodial
 * merchant to run without an API key at all.
 */
final class BlinkApiSatsRateProvider implements SatsRateProviderInterface {
  public function __construct(
    private BlinkApiClient $client,
    private LoggerInterface $log
  ) {
  }

  public function toSatoshis(float $amount, string $currency): ?int {
    try {
      $conversion = $this->client->currencyConversionEstimation($amount, $currency);
    } catch (\Throwable $e) {
      $this->log->error('Currency conversion failed: ' . $e->getMessage());

      return null;
    }

    $satoshis = (int) ($conversion['btcSatAmount'] ?? 0);

    return $satoshis > 0 ? $satoshis : null;
  }
}
