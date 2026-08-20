<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\Helpers\BlinkApiClient;
use Blink\WC\NonCustodial\BlinkApiSatsRateProvider;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

/**
 * The one place an order total becomes an amount of bitcoin.
 *
 * Every other suite injects a fake rate, which is what left this class
 * untested: it is small, but a wrong answer here charges the wrong amount, and
 * a thrown exception that escaped would abort checkout rather than fall back.
 */
final class BlinkApiSatsRateProviderTest extends TestCase {
  private SpyLogger $log;

  protected function setUp(): void {
    parent::setUp();
    $this->log = new SpyLogger();
  }

  /** @param mixed $result an array to return, or a Throwable to throw */
  private function provider($result): BlinkApiSatsRateProvider {
    $client = new class ($result) extends BlinkApiClient {
      /** @param mixed $result */
      public function __construct(private $result) {
        // Deliberately not calling the parent constructor: it only stores the
        // endpoint and token, and this double never reaches the network.
      }

      public function currencyConversionEstimation($amount, $currency) {
        if ($this->result instanceof \Throwable) {
          throw $this->result;
        }

        return $this->result;
      }
    };

    return new BlinkApiSatsRateProvider($client, $this->log);
  }

  public function testReturnsTheSatoshiAmountFromTheApi(): void {
    $provider = $this->provider(['btcSatAmount' => 12345]);

    $this->assertSame(12345, $provider->toSatoshis(10.0, 'USD'));
  }

  public function testANumericStringAmountIsNormalisedToAnInteger(): void {
    $provider = $this->provider(['btcSatAmount' => '9876']);

    $this->assertSame(9876, $provider->toSatoshis(10.0, 'USD'));
  }

  /**
   * A failure must read as "no rate", not as zero satoshis: InvoiceFactory
   * refuses to build an invoice without a rate, which is the safe outcome.
   */
  public function testAThrownErrorBecomesNoRate(): void {
    $provider = $this->provider(new \RuntimeException('GraphQL query failed'));

    $this->assertNull($provider->toSatoshis(10.0, 'USD'));
  }

  public function testAThrownErrorIsLogged(): void {
    $this->provider(new \RuntimeException('GraphQL query failed'))->toSatoshis(
      10.0,
      'USD'
    );

    $this->assertTrue($this->log->hasMessageContaining('GraphQL query failed', 'error'));
  }

  public function testAMissingAmountBecomesNoRate(): void {
    $this->assertNull($this->provider(['id' => 'abc'])->toSatoshis(10.0, 'USD'));
  }

  public function testAZeroAmountBecomesNoRate(): void {
    $this->assertNull($this->provider(['btcSatAmount' => 0])->toSatoshis(10.0, 'USD'));
  }

  public function testANegativeAmountBecomesNoRate(): void {
    $this->assertNull($this->provider(['btcSatAmount' => -5])->toSatoshis(10.0, 'USD'));
  }
}
