<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support;

use WP_UnitTestCase;

/**
 * Base for tests that need real WordPress and WooCommerce.
 *
 * The outside world is still substituted -- HTTP, the clock, DNS -- through
 * the same service filters a site owner could use, so these tests exercise
 * genuine WooCommerce behaviour without touching the network or waiting on
 * real time.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {
  use SubstitutesBlinkServices;

  /** Re-exposed from TestTime: a trait cannot hold a constant on PHP 8.1. */
  public const NOW = TestTime::NOW;

  public function set_up() {
    parent::set_up();
    $this->substituteBlinkServices();
  }

  public function tear_down() {
    $this->restoreBlinkServices();
    parent::tear_down();
  }
}
