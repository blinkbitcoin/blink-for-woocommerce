<?php

declare(strict_types=1);

namespace Blink\WC\Support;

use Blink\WC\Helpers\Logger;

/**
 * Adapter onto the plugin's existing static Logger.
 *
 * Logger::debug()'s second parameter doubles as "this is an error": it forces
 * the entry to be written even when the blink_debug option is off. That is the
 * behaviour error() relies on, so a failure is never swallowed just because
 * debug logging is disabled on the shop.
 */
final class WcLogger implements LoggerInterface {
  public function debug(string $message): void {
    Logger::debug($message);
  }

  public function error(string $message): void {
    Logger::debug($message, true);
  }
}
