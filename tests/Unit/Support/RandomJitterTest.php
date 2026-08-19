<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Support;

use Blink\WC\Support\RandomJitter;
use Blink\WC\Tests\Support\Fake\FakeRandomSource;
use PHPUnit\Framework\TestCase;

final class RandomJitterTest extends TestCase {
  /**
   * Pins the exact ends and midpoint of the jitter window. Three scripted
   * draws is enough to prove the mapping from [0,1) onto [-spread, +spread].
   *
   * @dataProvider windowBounds
   */
  public function testMapsRandomDrawOntoTheJitterWindow(
    float $draw,
    int $seconds,
    float $factor,
    int $expected
  ): void {
    $jitter = new RandomJitter(new FakeRandomSource([$draw]));

    $this->assertSame($expected, $jitter->apply($seconds, $factor));
  }

  /** @return array<string,array{float,int,float,int}> */
  public static function windowBounds(): array {
    return [
      'lower bound' => [0.0, 100, 0.25, 75],
      'midpoint is the input' => [0.5, 100, 0.25, 100],
      'upper bound' => [1.0, 100, 0.25, 125],
      'smaller factor narrows the window' => [0.0, 100, 0.1, 90],
      'zero factor disables jitter' => [0.0, 100, 0.0, 100]
    ];
  }

  public function testNonPositiveInputNeedsNoRandomnessAndStaysZero(): void {
    // An exhausted source throws, so this also proves no draw was taken.
    $source = new FakeRandomSource([]);
    $jitter = new RandomJitter($source);

    $this->assertSame(0, $jitter->apply(0));
    $this->assertSame(0, $jitter->apply(-30));
    $this->assertSame(0, $source->drawCount());
  }

  public function testResultNeverCollapsesToABusyLoop(): void {
    // A 1s delay with full jitter could round to 0 and spin; it must not.
    $jitter = new RandomJitter(new FakeRandomSource([0.0]));

    $this->assertSame(1, $jitter->apply(1, 1.0));
  }

  /**
   * @dataProvider outOfRangeFactors
   */
  public function testFactorIsClampedToSaneBounds(float $factor, int $expected): void {
    $jitter = new RandomJitter(new FakeRandomSource([1.0]));

    $this->assertSame($expected, $jitter->apply(100, $factor));
  }

  /** @return array<string,array{float,int}> */
  public static function outOfRangeFactors(): array {
    return [
      'negative factor behaves as zero' => [-0.5, 100],
      'factor above one is capped at one' => [4.0, 200]
    ];
  }

  public function testDelaysStayWithinTheWindowAcrossManyDraws(): void {
    $draws = [];
    mt_srand(1337);
    for ($i = 0; $i < 500; $i++) {
      $draws[] = mt_rand(0, 999999) / 1000000;
    }
    $jitter = new RandomJitter(new FakeRandomSource($draws));

    foreach ($draws as $_) {
      $delay = $jitter->apply(240, 0.25);
      $this->assertGreaterThanOrEqual(180, $delay);
      $this->assertLessThanOrEqual(300, $delay);
    }
  }

  public function testExhaustedSourceFailsLoudly(): void {
    $jitter = new RandomJitter(new FakeRandomSource([0.5]));
    $jitter->apply(10);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('exhausted');
    $jitter->apply(10);
  }
}
