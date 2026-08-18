<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * Guards the contract tests/bootstrap-unit.php provides.
 *
 * These assertions look trivial, but each one has failed in practice: a missing
 * autoload-dev mapping, a stub that stopped being required, or a plugin
 * constant that moved. Failing here points at the harness rather than at a
 * hundred confusing failures in the suites that depend on it.
 */
final class UnitBootstrapTest extends TestCase {
  public function testPluginClassesAreAutoloadable(): void {
    $this->assertTrue(
      class_exists(\Blink\WC\Helpers\BlinkApiHelper::class),
      'PSR-4 autoloading for Blink\\WC\\ is not wired up.'
    );
  }

  public function testTestHelpersAreAutoloadable(): void {
    $this->assertTrue(
      class_exists(self::class),
      'autoload-dev for Blink\\WC\\Tests\\ is not wired up.'
    );
  }

  public function testWooCommerceLoggerIsStubbed(): void {
    $this->assertTrue(
      class_exists(\WC_Logger::class),
      'Logger::debug() instantiates WC_Logger, so the unit tier needs the stub.'
    );
  }

  public function testWordPressIsNotLoaded(): void {
    $this->assertFalse(
      function_exists('add_action'),
      'The unit tier must not boot WordPress; use tests/Integration for that.'
    );
  }

  /**
   * @dataProvider pluginConstants
   */
  public function testPluginConstantsAreDefined(string $constant): void {
    $this->assertTrue(defined($constant), $constant . ' is not defined.');
  }

  /** @return array<string,array{string}> */
  public static function pluginConstants(): array {
    return [
      'version' => ['BLINK_VERSION'],
      'plugin id' => ['BLINK_PLUGIN_ID'],
      'plugin url' => ['BLINK_PLUGIN_URL'],
      'abspath' => ['ABSPATH'],
      'minute' => ['MINUTE_IN_SECONDS'],
    ];
  }
}
