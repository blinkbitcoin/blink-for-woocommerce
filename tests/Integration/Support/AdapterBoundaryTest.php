<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Support;

use Blink\WC\NonCustodial\SystemDnsResolver;
use Blink\WC\Support\SystemClock;
use Blink\WC\Support\WcLogger;
use Blink\WC\Support\WpRandomSource;
use WP_UnitTestCase;

/**
 * Covers the thin adapters that exist purely to reach a WordPress or
 * WooCommerce primitive.
 *
 * These deliberately live in the integration tier. Faking their WordPress
 * calls in the unit tier would mean instrumenting src/ with Patchwork, which
 * inflates Xdebug's branch counts and makes the coverage gate meaningless --
 * so they are exercised against the real functions instead.
 */
final class AdapterBoundaryTest extends WP_UnitTestCase {
  public function tear_down() {
    delete_option('blink_debug');
    parent::tear_down();
  }

  public function test_system_clock_tracks_wall_clock(): void {
    $before = time();
    $now = (new SystemClock())->now();

    $this->assertGreaterThanOrEqual($before, $now);
    $this->assertLessThanOrEqual(time(), $now);
  }

  public function test_random_source_stays_within_the_unit_range(): void {
    $source = new WpRandomSource();

    for ($i = 0; $i < 200; $i++) {
      $value = $source->float();
      $this->assertGreaterThanOrEqual(0.0, $value);
      $this->assertLessThan(1.0, $value);
    }
  }

  public function test_dns_resolver_returns_ip_literals_without_a_lookup(): void {
    $resolver = new SystemDnsResolver();

    $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
    $this->assertSame(['::1'], $resolver->resolve('::1'));
  }

  public function test_dns_resolver_resolves_a_local_name(): void {
    $ips = (new SystemDnsResolver())->resolve('localhost');

    $this->assertNotEmpty($ips, 'localhost must resolve via the hosts file');
    foreach ($ips as $ip) {
      $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
    }
  }

  public function test_dns_resolver_returns_nothing_for_a_name_that_cannot_exist(): void {
    // .invalid is reserved by RFC 6761 and must never resolve.
    $this->assertSame([], (new SystemDnsResolver())->resolve('blink-test.invalid'));
  }

  public function test_debug_is_suppressed_when_debug_logging_is_off(): void {
    update_option('blink_debug', 'no');

    (new WcLogger())->debug('routine poll');

    $this->assertSame([], $this->readLogEntries());
  }

  public function test_debug_is_written_when_debug_logging_is_on(): void {
    update_option('blink_debug', 'yes');

    (new WcLogger())->debug('routine poll');

    $this->assertContains('routine poll', $this->readLogEntries());
  }

  /**
   * The reason error() exists: a settlement failure must be recorded even on a
   * shop that never turned debug logging on.
   */
  public function test_error_is_written_even_when_debug_logging_is_off(): void {
    update_option('blink_debug', 'no');

    (new WcLogger())->error('verify endpoint unreachable');

    $this->assertContains('verify endpoint unreachable', $this->readLogEntries());
  }

  /**
   * Captures what the plugin handed to WC_Logger by installing a throwaway
   * log handler for the duration of the assertion.
   *
   * @return list<string>
   */
  private function readLogEntries(): array {
    return CapturingLogHandler::$messages;
  }

  public function set_up() {
    parent::set_up();
    CapturingLogHandler::$messages = [];
    add_filter('woocommerce_register_log_handlers', static function (): array {
      return [new CapturingLogHandler()];
    });
  }
}
