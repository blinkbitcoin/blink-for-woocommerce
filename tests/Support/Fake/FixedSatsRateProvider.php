<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\NonCustodial\SatsRateProviderInterface;

/**
 * A fixed conversion rate.
 *
 * The real provider goes through BlinkApiClient, which builds its own HTTP
 * client, so substituting the provider is what keeps the integration suite off
 * the network entirely.
 */
final class FixedSatsRateProvider implements SatsRateProviderInterface {
  /** @var list<array{amount:float,currency:string}> */
  public array $calls = [];

  public function __construct(private ?int $satoshis = 10000) {}

  public function toSatoshis(float $amount, string $currency): ?int {
    $this->calls[] = ['amount' => $amount, 'currency' => $currency];

    return $this->satoshis;
  }
}
