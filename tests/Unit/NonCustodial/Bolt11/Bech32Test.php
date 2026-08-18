<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial\Bolt11;

use Blink\WC\NonCustodial\Bolt11\Bech32;
use Blink\WC\NonCustodial\Bolt11\Bolt11Exception;
use Blink\WC\Tests\Support\Bolt11Encoder;
use PHPUnit\Framework\TestCase;

final class Bech32Test extends TestCase {
  public function testDecodesAValidStringIntoItsHrpAndData(): void {
    $encoded = Bolt11Encoder::create('lnbc100u')->timestamp(1700000000)->build();

    [$hrp, $data] = Bech32::decode($encoded);

    $this->assertSame('lnbc100u', $hrp);
    // Timestamp (7) plus the 104 filler groups standing in for a signature.
    $this->assertCount(111, $data);
  }

  public function testCaseIsNormalisedBeforeDecoding(): void {
    $encoded = Bolt11Encoder::create('lnbc100u')->timestamp(1700000000)->build();

    $this->assertSame(Bech32::decode($encoded), Bech32::decode(strtoupper($encoded)));
  }

  public function testMixedCaseIsRejected(): void {
    $encoded = Bolt11Encoder::create('lnbc100u')->timestamp(1700000000)->build();

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('mixed case');
    Bech32::decode(strtoupper(substr($encoded, 0, 10)) . substr($encoded, 10));
  }

  public function testEmptyStringIsRejected(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('empty bech32 string');
    Bech32::decode('');
  }

  /**
   * @dataProvider outOfRangeCharacters
   */
  public function testCharactersOutsideThePrintableRangeAreRejected(string $input): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('out-of-range character');
    Bech32::decode($input);
  }

  /** @return array<string,array{string}> */
  public static function outOfRangeCharacters(): array {
    return [
      'space' => ['lnbc 1qqqqqqqqq'],
      'newline' => ["lnbc\n1qqqqqqqqq"],
      'null byte' => ["lnbc\x001qqqqqqqqq"],
      'del' => ["lnbc\x7f1qqqqqqqqq"],
    ];
  }

  public function testStringWithoutASeparatorIsRejected(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('no human-readable part');
    Bech32::decode('lnbc');
  }

  public function testSeparatorAtTheStartLeavesNoHumanReadablePart(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('no human-readable part');
    Bech32::decode('1qqqqqqqqq');
  }

  public function testDataPartShorterThanTheChecksumIsRejected(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('shorter than its checksum');
    Bech32::decode('lnbc1qqq');
  }

  public function testCharacterOutsideTheCharsetIsRejected(): void {
    // 'b' is deliberately absent from the bech32 charset.
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('outside the charset');
    Bech32::decode('lnbc1qqqqqbqqqqq');
  }

  public function testChecksumMismatchIsRejected(): void {
    $encoded = Bolt11Encoder::create('lnbc100u')->timestamp(1700000000)->build();
    // Flip the final checksum character to something else in the charset.
    $tampered =
      substr($encoded, 0, -1) . ($encoded[strlen($encoded) - 1] === 'q' ? 'p' : 'q');

    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('checksum mismatch');
    Bech32::decode($tampered);
  }

  /**
   * The separator is the last '1', because a human-readable part may itself
   * contain one -- an amount of 1 satoshi, for instance.
   */
  public function testSeparatorIsTheLastOneNotTheFirst(): void {
    $encoded = Bolt11Encoder::create('lnbc1u')->timestamp(1700000000)->build();

    [$hrp] = Bech32::decode($encoded);

    $this->assertSame('lnbc1u', $hrp);
  }

  public function testConvertBitsRegroupsWithPadding(): void {
    // 0xFF is 11111111: the first five bits make 31, and the remaining three
    // (111) are left-padded into 11100 = 28.
    $this->assertSame([31, 28], Bech32::convertBits([0xff], 8, 5, true));
  }

  public function testConvertBitsRoundTrips(): void {
    $bytes = array_values(unpack('C*', 'blink'));

    $groups = Bech32::convertBits($bytes, 8, 5, true);
    $roundTripped = Bech32::convertBits($groups, 5, 8, false);

    $this->assertSame($bytes, $roundTripped);
  }

  public function testConvertBitsRejectsAValueTooLargeForItsWidth(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('value out of range');
    Bech32::convertBits([32], 5, 8, false);
  }

  public function testConvertBitsRejectsANegativeValue(): void {
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('value out of range');
    Bech32::convertBits([-1], 5, 8, false);
  }

  public function testConvertBitsRejectsLeftoverBitsWhenNotPadding(): void {
    // Two 5-bit groups leave 2 bits over, which is more than a byte boundary
    // allows to be dropped silently.
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('invalid padding');
    Bech32::convertBits([31, 31], 5, 8, false);
  }

  public function testConvertBitsRejectsASingleLeftoverGroup(): void {
    // Five bits cannot make a byte, and dropping them would silently lose data.
    $this->expectException(Bolt11Exception::class);
    $this->expectExceptionMessage('invalid padding');
    Bech32::convertBits([31], 5, 8, false);
  }

  public function testConvertBitsAcceptsCleanBoundaries(): void {
    // 8 groups of 5 bits is exactly 5 bytes, so nothing is left over.
    $this->assertSame(
      [0, 0, 0, 0, 1],
      Bech32::convertBits([0, 0, 0, 0, 0, 0, 0, 1], 5, 8, false)
    );
  }

  public function testConvertBitsOfNothingIsNothing(): void {
    $this->assertSame([], Bech32::convertBits([], 5, 8, false));
  }

  /**
   * @dataProvider integers
   */
  public function testToIntReadsBigEndianFiveBitGroups(
    array $groups,
    int $expected
  ): void {
    $this->assertSame($expected, Bech32::toInt($groups));
  }

  /** @return array<string,array{list<int>,int}> */
  public static function integers(): array {
    return [
      'empty is zero' => [[], 0],
      'single group' => [[1], 1],
      'max single group' => [[31], 31],
      'two groups' => [[1, 0], 32],
      'three groups' => [[1, 2, 3], 1091],
    ];
  }
}
