<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial\Bolt11;

use Blink\WC\NonCustodial\Bolt11\Bolt11Decoder;
use Blink\WC\NonCustodial\Bolt11\Bolt11Exception;
use Blink\WC\NonCustodial\Bolt11\DecodedInvoice;
use Blink\WC\Tests\Support\Bolt11Encoder;
use PHPUnit\Framework\TestCase;

final class Bolt11DecoderTest extends TestCase {
  /**
   * The amount comes from a server the shop does not control and the prefix
   * pattern accepts any number of digits. Casting saturated at PHP_INT_MAX and
   * the conversion overflowed to float, surfacing as a TypeError that
   * InvoiceValidator does not catch -- a fatal in checkout rather than a
   * refused invoice.
   *
   * @dataProvider overflowingAmounts
   */
  public function testAnAmountTooLargeToRepresentIsRefused(string $hrp): void {
    $invoice = Bolt11Encoder::create($hrp)
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('out of range');

    (new Bolt11Decoder())->decode($invoice);
  }

  /** @return array<string,array{string}> */
  public static function overflowingAmounts(): array {
    return [
      'bare bitcoin amount' => ['lnbc' . str_repeat('9', 30)],
      'milli multiplier' => ['lnbc' . str_repeat('9', 30) . 'm'],
      'pico multiplier' => ['lnbc' . str_repeat('9', 30) . 'p'],
      'just over the bare ceiling' => ['lnbc92233721'],
      // Casts cleanly; only the multiplier pushes it past what an int holds.
      'just over the milli ceiling' => ['lnbc9223372037m']
    ];
  }

  public function testTheLargestRepresentableBareAmountIsStillAccepted(): void {
    $invoice = Bolt11Encoder::create('lnbc92233720')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $this->assertSame(
      92233720 * 100000000000,
      (new Bolt11Decoder())->decode($invoice)->amountMsat
    );
  }

  private Bolt11Decoder $decoder;

  protected function setUp(): void {
    parent::setUp();
    $this->decoder = new Bolt11Decoder();
  }

  /** @return array{valid:list<array{label:string,invoice:string}>,invalid:list<array{label:string,invoice:string}>} */
  private static function specVectors(): array {
    $path = dirname(__DIR__, 3) . '/fixtures/bolt11/spec-vectors.json';

    return json_decode((string) file_get_contents($path), true);
  }

  /**
   * Every invoice in the BOLT11 specification's example section must decode.
   *
   * @dataProvider validSpecVectors
   */
  public function testDecodesEverySpecificationExample(
    string $label,
    string $invoice
  ): void {
    $decoded = $this->decoder->decode($invoice);

    $this->assertContains($decoded->network, ['bc', 'tb', 'bcrt', 'sb'], $label);
    $this->assertGreaterThan(0, $decoded->timestamp, $label);
  }

  /** @return array<string,array{string,string}> */
  public static function validSpecVectors(): array {
    $cases = [];
    foreach (self::specVectors()['valid'] as $i => $vector) {
      $cases[$i . ': ' . substr($vector['label'], 0, 50)] = [
        $vector['label'],
        $vector['invoice']
      ];
    }

    return $cases;
  }

  /**
   * Amount and expiry decoding across every multiplier, from the spec examples.
   *
   * @dataProvider specAmounts
   */
  public function testDecodesSpecAmounts(
    int $index,
    ?int $expectedMsat,
    int $expectedExpiry
  ): void {
    $invoice = self::specVectors()['valid'][$index]['invoice'];

    $decoded = $this->decoder->decode($invoice);

    $this->assertSame($expectedMsat, $decoded->amountMsat);
    $this->assertSame($expectedExpiry, $decoded->expirySeconds);
  }

  /** @return array<string,array{int,int|null,int}> */
  public static function specAmounts(): array {
    return [
      'amountless donation' => [0, null, 3600],
      '2500u with 60s expiry' => [1, 250000000, 60],
      '20m' => [3, 2000000000, 3600],
      '20m on testnet' => [4, 2000000000, 3600],
      '9678785340p with a week expiry' => [9, 967878534, 604800],
      '25m' => [10, 2500000000, 3600],
      '10m' => [12, 1000000000, 3600]
    ];
  }

  public function testDecodesNetworkFromTheSpecTestnetVector(): void {
    $decoded = $this->decoder->decode(self::specVectors()['valid'][4]['invoice']);

    $this->assertSame('tb', $decoded->network);
  }

  public function testDecodesPaymentHashFromTheSpecVector(): void {
    $decoded = $this->decoder->decode(self::specVectors()['valid'][1]['invoice']);

    $this->assertSame(
      '0001020304050607080900010203040506070809000102030405060708090102',
      $decoded->paymentHash
    );
  }

  public function testDecodesDescriptionHashFromTheSpecVector(): void {
    $decoded = $this->decoder->decode(self::specVectors()['valid'][3]['invoice']);

    $this->assertNotNull($decoded->descriptionHash);
    $this->assertSame(64, strlen($decoded->descriptionHash));
  }

  public function testDecodesUnicodeDescription(): void {
    // "a cup of nonsense" carries a Japanese string in the spec example.
    $decoded = $this->decoder->decode(self::specVectors()['valid'][2]['invoice']);

    $this->assertNotNull($decoded->description);
    $this->assertStringContainsString(
      "\u{30CA}\u{30F3}\u{30BB}\u{30F3}\u{30B9}",
      $decoded->description
    );
  }

  /**
   * Spec invoices that are invalid for reasons this decoder does check.
   *
   * @dataProvider rejectableSpecVectors
   */
  public function testRejectsSpecInvoicesThatFailChecksItPerforms(
    int $index,
    string $expected
  ): void {
    $invoice = self::specVectors()['invalid'][$index]['invoice'];

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage($expected);
    $this->decoder->decode($invoice);
  }

  /** @return array<string,array{int,string}> */
  public static function rejectableSpecVectors(): array {
    return [
      'bad checksum' => [1, 'checksum mismatch'],
      'too short' => [3, 'too short'],
      'invalid multiplier' => [4, 'unrecognised invoice prefix'],
      'sub-millisatoshi precision' => [5, 'not representable in millisatoshi']
    ];
  }

  /**
   * The spec's remaining invalid vectors fail only on signature grounds, which
   * this decoder deliberately does not check. Accepting them is correct here --
   * binding is established by InvoiceValidator and by the preimage check at
   * settlement -- and pinning it here stops the behaviour being mistaken for a
   * gap by a future reader.
   *
   * @dataProvider signatureOnlySpecVectors
   */
  public function testAcceptsSpecInvoicesThatAreOnlySignatureInvalid(int $index): void {
    $invoice = self::specVectors()['invalid'][$index]['invoice'];

    $this->assertInstanceOf(DecodedInvoice::class, $this->decoder->decode($invoice));
  }

  /** @return array<string,array{int}> */
  public static function signatureOnlySpecVectors(): array {
    return [
      'high-S signature' => [0],
      'unrecoverable signature' => [2],
      'missing payment secret' => [6],
      'high-S with n field' => [7]
    ];
  }

  public function testBareAmountIsInterpretedAsBitcoin(): void {
    $invoice = Bolt11Encoder::create('lnbc2')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $this->assertSame(200000000000, $this->decoder->decode($invoice)->amountMsat);
  }

  public function testMissingExpiryTagFallsBackToTheSpecDefault(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $decoded = $this->decoder->decode($invoice);

    $this->assertSame(3600, $decoded->expirySeconds);
    $this->assertSame(1700003600, $decoded->expiresAt());
  }

  public function testExpiryTagIsHonoured(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->tagInt('x', 300)
      ->build();

    $decoded = $this->decoder->decode($invoice);

    $this->assertSame(300, $decoded->expirySeconds);
    $this->assertSame(1700000300, $decoded->expiresAt());
  }

  /** BOLT11: the first occurrence of a field wins, later ones are ignored. */
  public function testFirstOccurrenceOfARepeatedFieldWins(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('aa', 32))
      ->tagHex('p', str_repeat('bb', 32))
      ->tagInt('x', 111)
      ->tagInt('x', 222)
      ->tagBytes('d', 'first')
      ->tagBytes('d', 'second')
      ->build();

    $decoded = $this->decoder->decode($invoice);

    $this->assertSame(str_repeat('aa', 32), $decoded->paymentHash);
    $this->assertSame(111, $decoded->expirySeconds);
    $this->assertSame('first', $decoded->description);
  }

  public function testUnknownTagsAreSkippedByLength(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagBytes('f', 'fallback address bytes')
      ->tagHex('p', str_repeat('cd', 32))
      ->build();

    $this->assertSame(
      str_repeat('cd', 32),
      $this->decoder->decode($invoice)->paymentHash
    );
  }

  public function testPaymentHashOfTheWrongLengthIsIgnored(): void {
    // A 'p' field must be exactly 52 groups; anything else is not a hash.
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 16))
      ->build();

    $this->assertNull($this->decoder->decode($invoice)->paymentHash);
  }

  public function testDescriptionHashOfTheWrongLengthIsIgnored(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('h', str_repeat('ab', 16))
      ->build();

    $this->assertNull($this->decoder->decode($invoice)->descriptionHash);
  }

  public function testTruncatedTaggedFieldIsRejected(): void {
    // Declares a 20-group field but supplies two groups.
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->raw([1, 0, 20, 5, 5])
      ->build();

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('truncated tagged field');
    $this->decoder->decode($invoice);
  }

  public function testTrailingGroupsTooShortForAHeaderAreIgnored(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ef', 32))
      ->raw([1, 0])
      ->build();

    $this->assertSame(
      str_repeat('ef', 32),
      $this->decoder->decode($invoice)->paymentHash
    );
  }

  /**
   * @dataProvider malformedInvoices
   */
  public function testRejectsMalformedInput(string $invoice, string $expected): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage($expected);
    $this->decoder->decode($invoice);
  }

  /** @return array<string,array{string,string}> */
  public static function malformedInvoices(): array {
    return [
      'empty' => ['', 'empty bech32 string'],
      'not lightning' => [
        'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
        'not a lightning invoice'
      ],
      'no separator' => ['lnbc', 'no human-readable part']
    ];
  }

  public function testMultiplierWithoutAnAmountIsRejected(): void {
    $invoice = Bolt11Encoder::create('lnbcm')->timestamp(1700000000)->build();

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('multiplier but no amount');
    $this->decoder->decode($invoice);
  }

  public function testWhitespaceAroundTheInvoiceIsTolerated(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $this->assertSame(
      $this->decoder->decode($invoice)->paymentHash,
      $this->decoder->decode("  \n" . $invoice . '  ')->paymentHash
    );
  }

  public function testUppercaseInvoiceIsAccepted(): void {
    $invoice = Bolt11Encoder::create('lnbc100u')
      ->timestamp(1700000000)
      ->tagHex('p', str_repeat('ab', 32))
      ->build();

    $this->assertSame(
      $this->decoder->decode($invoice)->paymentHash,
      $this->decoder->decode(strtoupper($invoice))->paymentHash
    );
  }
}
