<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support;

/**
 * The fixed "now" every suite runs at.
 *
 * It lives in a class rather than in SubstitutesBlinkServices because
 * constants in traits require PHP 8.2, and this plugin supports 8.1. The
 * classes that use the trait re-expose it as self::NOW, which is what the
 * tests read.
 */
final class TestTime {
  public const NOW = 1700000000;
}
