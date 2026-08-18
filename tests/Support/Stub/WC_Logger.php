<?php
/**
 * Minimal stand-in for WooCommerce's WC_Logger.
 *
 * Blink\WC\Helpers\Logger instantiates WC_Logger directly, so the unit tier
 * needs the class to exist. Records are kept in memory so a test can assert on
 * what was logged without touching the filesystem.
 */

declare(strict_types=1);

class WC_Logger {
  /** @var list<array{level:string,message:string,context:array}> */
  public static array $records = [];

  public static function reset(): void {
    self::$records = [];
  }

  public function log(string $level, string $message, array $context = []): void {
    self::$records[] = ['level' => $level, 'message' => $message, 'context' => $context];
  }

  public function debug(string $message, array $context = []): void {
    $this->log('debug', $message, $context);
  }

  public function error(string $message, array $context = []): void {
    $this->log('error', $message, $context);
  }
}
