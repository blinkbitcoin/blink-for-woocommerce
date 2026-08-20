<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Support;

use WC_Log_Handler;

/**
 * Collects WooCommerce log output in memory so assertions do not depend on
 * files being written under wp-content/uploads.
 */
final class CapturingLogHandler extends WC_Log_Handler {
  /** @var list<string> */
  public static array $messages = [];

  public function handle($timestamp, $level, $message, $context) {
    self::$messages[] = $message;

    return true;
  }
}
