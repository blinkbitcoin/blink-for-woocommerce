<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Minimal logging seam.
 *
 * Wraps Blink\WC\Helpers\Logger so the non-custodial classes can be unit
 * tested without WooCommerce's WC_Logger, and so tests can assert on what was
 * logged rather than on a file written somewhere.
 */
interface LoggerInterface {
  public function debug(string $message): void;

  public function error(string $message): void;
}
