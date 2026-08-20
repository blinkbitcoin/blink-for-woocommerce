<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial\Bolt11;

use Blink\WC\NonCustodial\Bolt11\Bolt11Decoder;
use Blink\WC\NonCustodial\Bolt11\InvoiceExpectation;
use Blink\WC\NonCustodial\Bolt11\InvoiceValidator;
use Blink\WC\Tests\Support\Bolt11Encoder;
use Blink\WC\Tests\Support\Fake\FakeClock;
use PHPUnit\Framework\TestCase;

final class InvoiceValidatorTest extends TestCase {
  private const NOW = 1700000000;
  private const METADATA = '[["text/plain","Order GW-123"]]';
  private const HASH = 'aa00112233445566778899aabbccddeeff00112233445566778899aabbccddee';

  private FakeClock $clock;
  private InvoiceValidator $validator;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FakeClock(self::NOW);
    $this->validator = new InvoiceValidator(new Bolt11Decoder(), $this->clock);
  }

  /**
   * An invoice that matches the request in every respect.
   *
   * 100u = 10,000,000 msat.
   */
  private function goodInvoice(): string {
    return Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 3600)
      ->build();
  }

  private function expectation(array $overrides = []): InvoiceExpectation {
    return new InvoiceExpectation(
      $overrides['amountMsat'] ?? 10000000,
      $overrides['lnurlMetadata'] ?? self::METADATA,
      $overrides['verifyUrlPaymentHash'] ?? self::HASH,
      $overrides['maxExpirySeconds'] ?? 3600,
      $overrides['minRemainingSeconds'] ?? 120,
      $overrides['allowTestNetworks'] ?? false,
      $overrides['requireDescriptionBinding'] ?? true
    );
  }

  /**
   * The binding used to be skipped when the verify URL carried no hash, which
   * left nothing tying that URL to the invoice the customer is shown. A verify
   * URL for a different, already-settled invoice answers "settled" with no
   * preimage, and settlement tolerates a missing preimage by design -- so the
   * order completed without a payment.
   */
  public function testRefusesAnInvoiceItCannotBindToTheVerifyUrl(): void {
    $expectation = new InvoiceExpectation(10000000, self::METADATA, null);

    $result = $this->validator->validate($this->goodInvoice(), $expectation);

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_HASH_UNBINDABLE', $result->code);
  }

  public function testAcceptsAnInvoiceThatMatchesTheRequest(): void {
    $result = $this->validator->validate($this->goodInvoice(), $this->expectation());

    $this->assertTrue($result->valid, $result->code . ': ' . $result->message);
    $this->assertNotNull($result->invoice);
    $this->assertSame(self::NOW - 10 + 3600, $result->expiresAt);
  }

  public function testRejectsMalformedInput(): void {
    $result = $this->validator->validate('not-an-invoice', $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_MALFORMED', $result->code);
  }

  public function testRejectsATestnetInvoiceByDefault(): void {
    $invoice = Bolt11Encoder::create('lntb100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_WRONG_NETWORK', $result->code);
  }

  public function testAllowsATestnetInvoiceForALocalDevelopmentAddress(): void {
    $invoice = Bolt11Encoder::create('lntb100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate(
      $invoice,
      $this->expectation(['allowTestNetworks' => true])
    );

    $this->assertTrue($result->valid, $result->code);
  }

  public function testAllowsASignetInvoiceForALocalDevelopmentAddress(): void {
    $invoice = Bolt11Encoder::create('lntbs100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate(
      $invoice,
      $this->expectation(['allowTestNetworks' => true])
    );

    $this->assertTrue($result->valid, $result->code);
  }

  public function testRejectsAnAmountlessInvoice(): void {
    $invoice = Bolt11Encoder::create('lnbc')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_NO_AMOUNT', $result->code);
  }

  /**
   * The check that stops a hostile server billing the customer a fraction of
   * the order total.
   *
   * @dataProvider mismatchedAmounts
   */
  public function testRejectsAnAmountThatDoesNotMatchExactly(string $hrp): void {
    $invoice = Bolt11Encoder::create($hrp)
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_AMOUNT_MISMATCH', $result->code);
  }

  /** @return array<string,array{string}> */
  public static function mismatchedAmounts(): array {
    return [
      'one satoshi short' => ['lnbc99999n'],
      'an order of magnitude low' => ['lnbc10u'],
      'higher than requested' => ['lnbc200u']
    ];
  }

  public function testRejectsAnInvoiceWithNoPaymentHash(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_NO_HASH', $result->code);
  }

  /**
   * Settlement is polled against the verify URL, so if the invoice pays a
   * different hash the shop watches a payment the customer never makes.
   */
  public function testRejectsAPaymentHashThatDoesNotMatchTheVerifyUrl(): void {
    $invoice = $this->goodInvoice();

    $result = $this->validator->validate(
      $invoice,
      $this->expectation(['verifyUrlPaymentHash' => str_repeat('bb', 32)])
    );

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_HASH_MISMATCH', $result->code);
  }

  public function testSkipsTheHashCrossCheckWhenTheVerifyUrlCarriesNoHash(): void {
    $result = $this->validator->validate(
      $this->goodInvoice(),
      $this->expectation(['verifyUrlPaymentHash' => null])
    );

    $this->assertTrue($result->valid, $result->code);
  }

  public function testRejectsADescriptionHashThatDoesNotMatchTheMetadata(): void {
    $result = $this->validator->validate(
      $this->goodInvoice(),
      $this->expectation(['lnurlMetadata' => '[["text/plain","a different order"]]'])
    );

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_DESC_MISMATCH', $result->code);
  }

  public function testAcceptsAPlainDescriptionEqualToTheMetadata(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagBytes('d', self::METADATA)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertTrue($result->valid, $result->code . ': ' . $result->message);
  }

  public function testRejectsAPlainDescriptionThatDiffersFromTheMetadata(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagBytes('d', 'something else entirely')
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_DESC_MISMATCH', $result->code);
  }

  public function testRejectsAnInvoiceBoundToNothing(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_DESC_ABSENT', $result->code);
  }

  public function testDescriptionBindingCanBeWaivedForServersThatDoNotEchoMetadata(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->build();

    $result = $this->validator->validate(
      $invoice,
      $this->expectation(['requireDescriptionBinding' => false])
    );

    $this->assertTrue($result->valid, $result->code);
  }

  public function testRejectsAnInvoiceTimestampedTooFarInTheFuture(): void {
    $future = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW + 3600)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $result = $this->validator->validate($future, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_FUTURE', $result->code);
  }

  public function testToleratesSmallClockSkew(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW + 120)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->build();

    $this->assertTrue($this->validator->validate($invoice, $this->expectation())->valid);
  }

  /**
   * The reason expiry is read from the invoice rather than assumed: a server
   * is free to issue something much shorter than was requested.
   */
  public function testRejectsAnInvoiceThatExpiresTooSoonToBePaid(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 10)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 60)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_TOO_SHORT', $result->code);
  }

  public function testAcceptsAnInvoiceExactlyAtTheMinimumRemainingWindow(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 120)
      ->build();

    $this->assertTrue($this->validator->validate($invoice, $this->expectation())->valid);
  }

  public function testAnAlreadyExpiredInvoiceIsRejected(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW - 7200)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 3600)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertFalse($result->valid);
    $this->assertSame('BOLT11_TOO_SHORT', $result->code);
  }

  public function testAnOverlyLongExpiryIsClampedToTheTrackingWindow(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 2592000)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertTrue($result->valid);
    $this->assertSame(self::NOW + 3600, $result->expiresAt);
  }

  public function testAcceptsExpiryAtTheConfiguredMaximum(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 86400)
      ->build();

    $result = $this->validator->validate(
      $invoice,
      $this->expectation(['maxExpirySeconds' => 86400])
    );

    $this->assertTrue($result->valid);
    $this->assertSame(self::NOW + 86400, $result->expiresAt);
  }

  public function testExpiryFollowsTheInvoiceWhenShorterThanTheMaximum(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 900)
      ->build();

    $result = $this->validator->validate($invoice, $this->expectation());

    $this->assertTrue($result->valid);
    $this->assertSame(self::NOW + 900, $result->expiresAt);
  }

  public function testValidityIsReassessedAsTimePasses(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(self::NOW)
      ->tagHex('p', self::HASH)
      ->tagHex('h', hash('sha256', self::METADATA))
      ->tagInt('x', 600)
      ->build();

    $this->assertTrue($this->validator->validate($invoice, $this->expectation())->valid);

    $this->clock->travel(500);

    $later = $this->validator->validate($invoice, $this->expectation());
    $this->assertFalse($later->valid);
    $this->assertSame('BOLT11_TOO_SHORT', $later->code);
  }
}
