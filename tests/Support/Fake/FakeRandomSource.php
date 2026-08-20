<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\RandomSourceInterface;

/**
 * Returns a scripted sequence of floats.
 *
 * Exhausting the script is a failure rather than a wrap-around: an unexpected
 * extra draw means the code under test changed its sampling behaviour, and
 * silently reusing a value would hide that.
 */
final class FakeRandomSource implements RandomSourceInterface {
  private int $index = 0;

  /** @param list<float> $values */
  public function __construct(private array $values = [0.5]) {}

  public function float(): float {
    if (!array_key_exists($this->index, $this->values)) {
      throw new \LogicException(
        sprintf(
          'FakeRandomSource exhausted after %d draw(s); the code under test asked for more randomness than the test scripted.',
          count($this->values)
        )
      );
    }

    return $this->values[$this->index++];
  }

  public function drawCount(): int {
    return $this->index;
  }
}
