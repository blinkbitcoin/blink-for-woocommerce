<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\Bolt11\Bolt11Decoder;
use Blink\WC\NonCustodial\Bolt11\InvoiceValidator;
use Blink\WC\NonCustodial\InvoiceFactory;
use Blink\WC\NonCustodial\LnAddress;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\LnurlFailure;
use Blink\WC\NonCustodial\SatsRateProviderInterface;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\Tests\Support\Bolt11Encoder;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

final class InvoiceFactoryTest extends TestCase {
  private const NOW = 1700000000;
  private const METADATA = '[["text/plain","Order GW-1"]]';
  private const HASH = 'aa00112233445566778899aabbccddeeff00112233445566778899aabbccddee';

  private FakeHttpClient $http;
  private FakeClock $clock;
  private SpyLogger $log;
  private LnAddress $address;

  protected function setUp(): void {
    parent::setUp();
    $this->http = new FakeHttpClient();
    $this->clock = new FakeClock(self::NOW);
    $this->log = new SpyLogger();
    $this->address = LnAddress::parse('shop@blink.sv');
  }

  private function rate(?int $satoshis): SatsRateProviderInterface {
    return new class ($satoshis) implements SatsRateProviderInterface {
      public function __construct(private ?int $satoshis) {
      }

      public function toSatoshis(float $amount, string $currency): ?int {
        return $this->satoshis;
      }
    };
  }

  private function factory(
    ?int $satoshis = 10000,
    bool $requireDescriptionBinding = true
  ): InvoiceFactory {
    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $client = new LnurlClient($this->http, $policy, $this->log);

    return new InvoiceFactory(
      $client,
      $this->rate($satoshis),
      new InvoiceValidator(new Bolt11Decoder(), $this->clock),
      $this->clock,
      $this->log,
      $requireDescriptionBinding
    );
  }

  /** 10,000 sat = 10,000,000 msat = 100u. */
  private function invoiceFor(
    int $expirySeconds = 3600,
    string $hrp = 'lnbc100u'
  ): string {
    return Bolt11Encoder::create($hrp)
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', $expirySeconds)
      ->build();
  }

  private function queueHappyPath(?string $bolt11 = null): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'minSendable' => 1000,
      'maxSendable' => 100000000000,
      'commentAllowed' => 255,
      'metadata' => self::METADATA
    ]);
    $this->http->queueJson([
      'pr' => $bolt11 ?? $this->invoiceFor(),
      'verify' => 'https://blink.sv/verify/' . self::HASH
    ]);
  }

  public function testCreatesAnInvoice(): void {
    $this->queueHappyPath();

    $result = $this->factory()->create(
      $this->address,
      10.0,
      'USD',
      '10.00',
      'USD',
      'GW-1'
    );

    $this->assertInstanceOf(StoredInvoice::class, $result);
    $this->assertSame(self::HASH, $result->paymentHash);
    $this->assertSame('https://blink.sv/verify/' . self::HASH, $result->verifyUrl);
    $this->assertSame('shop@blink.sv', $result->lnAddress);
    $this->assertSame(10000000, $result->amountMsat);
    $this->assertSame(10000, $result->satoshis);
    $this->assertSame(self::NOW, $result->createdAt);
    $this->assertSame('10.00', $result->orderTotal);
    $this->assertSame('USD', $result->orderCurrency);
  }

  /**
   * Expiry comes from the invoice, not from what was requested. This is the
   * whole reason the decoder exists.
   */
  public function testExpiryIsTakenFromTheInvoiceRatherThanAssumed(): void {
    $this->queueHappyPath($this->invoiceFor(900));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(StoredInvoice::class, $result);
    $this->assertSame(self::NOW + 900, $result->expiresAt);
  }

  public function testAcceptsABlinkLengthInvoiceExpiry(): void {
    $this->queueHappyPath($this->invoiceFor(86400));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(StoredInvoice::class, $result);
    $this->assertSame(self::NOW + 86400, $result->expiresAt);
  }

  public function testAnOverlyLongInvoiceExpiryIsClampedToTheTrackingWindow(): void {
    $this->queueHappyPath($this->invoiceFor(2592000));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(StoredInvoice::class, $result);
    $this->assertSame(self::NOW + 86400, $result->expiresAt);
  }

  public function testTheCommentIsForwardedToTheServer(): void {
    $this->queueHappyPath();

    $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', 'GW-77');

    $this->assertStringContainsString('comment=GW-77', $this->http->urls()[1]);
  }

  public function testFailsWhenNoRateIsAvailable(): void {
    $result = $this->factory(null)->create(
      $this->address,
      10.0,
      'USD',
      '10.00',
      'USD',
      ''
    );

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('RATE_UNAVAILABLE', $result->code);
    $this->assertSame(
      0,
      $this->http->requestCount(),
      'nothing should be fetched without a rate'
    );
  }

  public function testFailsWhenTheRateIsNotPositive(): void {
    $result = $this->factory(0)->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('RATE_UNAVAILABLE', $result->code);
  }

  public function testFailsWhenTheAddressOffersNoLnurlPay(): void {
    $this->http->queueJson(['tag' => 'withdrawRequest']);

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_NOT_PAYABLE', $result->code);
  }

  public function testFailsWhenTheCallbackRefuses(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/cb',
      'metadata' => self::METADATA
    ]);
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'no route']);

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('LNURL_ERROR', $result->code);
  }

  /**
   * The check that stops a hostile or broken server billing a fraction of the
   * order. 1u is a hundredth of the requested amount.
   */
  public function testRejectsAnInvoiceForTheWrongAmount(): void {
    $this->queueHappyPath($this->invoiceFor(3600, 'lnbc1u'));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_AMOUNT_MISMATCH', $result->code);
  }

  public function testRejectsAnInvoiceWhosePaymentHashDiffersFromTheVerifyUrl(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/cb',
      'metadata' => self::METADATA
    ]);
    $this->http->queueJson([
      'pr' => $this->invoiceFor(),
      'verify' => 'https://blink.sv/verify/' . str_repeat('cd', 32)
    ]);

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_HASH_MISMATCH', $result->code);
  }

  public function testRejectsAnInvoiceNotBoundToTheAdvertisedMetadata(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/cb',
      'metadata' => '[["text/plain","a different order"]]'
    ]);
    $this->http->queueJson([
      'pr' => $this->invoiceFor(),
      'verify' => 'https://blink.sv/verify/' . self::HASH
    ]);

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_DESC_MISMATCH', $result->code);
  }

  public function testDescriptionBindingCanBeRelaxedPerSite(): void {
    $unbound = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagInt('x', 3600)
      ->build();
    $this->queueHappyPath($unbound);

    $strict = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');
    $this->assertInstanceOf(LnurlFailure::class, $strict);

    $this->http = new FakeHttpClient();
    $this->queueHappyPath($unbound);
    $relaxed = $this->factory(10000, false)->create(
      $this->address,
      10.0,
      'USD',
      '10.00',
      'USD',
      ''
    );

    $this->assertInstanceOf(StoredInvoice::class, $relaxed);
  }

  public function testRejectsAnInvoiceThatExpiresTooSoon(): void {
    $this->queueHappyPath($this->invoiceFor(30));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_TOO_SHORT', $result->code);
  }

  public function testRejectsAMalformedInvoice(): void {
    $this->queueHappyPath('this-is-not-an-invoice');

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_MALFORMED', $result->code);
  }

  public function testRejectsATestnetInvoiceForAPublicAddress(): void {
    $this->queueHappyPath($this->invoiceFor(3600, 'lntb100u'));

    $result = $this->factory()->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(LnurlFailure::class, $result);
    $this->assertSame('BOLT11_WRONG_NETWORK', $result->code);
  }

  public function testAcceptsASignetInvoiceForALocalDevelopmentAddress(): void {
    $local = LnAddress::parse('ok@localhost:8889');
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'http://localhost:8889/cb',
      'metadata' => self::METADATA
    ]);
    $this->http->queueJson([
      'pr' => $this->invoiceFor(3600, 'lntbs100u'),
      'verify' => 'http://localhost:8889/verify/' . self::HASH
    ]);

    $result = $this->factory()->create($local, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertInstanceOf(StoredInvoice::class, $result);
  }

  public function testFailuresAreLogged(): void {
    $this->factory(null)->create($this->address, 10.0, 'USD', '10.00', 'USD', '');

    $this->assertTrue($this->log->hasMessageContaining('RATE_UNAVAILABLE', 'error'));
  }
}
