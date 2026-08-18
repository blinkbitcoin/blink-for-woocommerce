<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Helpers;

use Blink\WC\Helpers\BlinkApiClient;
use GuzzleHttp\Exception\ConnectException;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The custodial GraphQL client.
 *
 * This is legacy code and stays that way, but this PR gave its Guzzle client
 * explicit timeouts: without them Guzzle waits indefinitely, so a hung Blink
 * API holds the PHP worker that is serving a customer's checkout until the
 * web server gives up on it.
 *
 * What is pinned here is the failure mode -- an unreachable API raises, so
 * callers cannot mistake it for an empty answer. The timeout values themselves
 * are not asserted: doing so honestly would need a server that accepts a
 * connection and then stalls for twenty seconds, which is a slow test to run
 * on every push for a constant that is visible in the diff.
 */
final class BlinkApiClientTest extends IntegrationTestCase {
  /** Port 1 on loopback: nothing listens, so the refusal is immediate. */
  private const UNREACHABLE = 'http://127.0.0.1:1/graphql';

  public function test_an_unreachable_api_raises_rather_than_returning_nothing(): void {
    $client = new BlinkApiClient(self::UNREACHABLE, 'token-123');

    $this->expectException(ConnectException::class);
    $client->getAuthorizationScopes();
  }

  /**
   * The caller that decides whether a merchant's key is valid must treat an
   * unreachable API as "cannot confirm", not as "confirmed".
   */
  public function test_the_currency_query_also_raises_when_unreachable(): void {
    $client = new BlinkApiClient(self::UNREACHABLE, 'token-123');

    $this->expectException(ConnectException::class);
    $client->currencyConversionEstimation(10.0, 'USD');
  }
}
