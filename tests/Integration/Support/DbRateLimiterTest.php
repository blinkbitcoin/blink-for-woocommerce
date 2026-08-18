<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Support;

use Blink\WC\Support\DbRateLimiter;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\StubWpdb;
use Blink\WC\Tests\Support\IntegrationTestCase;

final class DbRateLimiterTest extends IntegrationTestCase {
  private DbRateLimiter $limiter;
  private FakeClock $limiterClock;

  public function set_up() {
    parent::set_up();
    global $wpdb;
    $this->limiterClock = new FakeClock(self::NOW);
    $this->limiter = new DbRateLimiter($wpdb, $this->limiterClock);
  }

  public function tear_down() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'blink_rl_%'");
    parent::tear_down();
  }

  public function test_hits_within_the_limit_are_allowed(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->assertTrue($this->limiter->hit('ip_a', 5, 60), 'hit ' . ($i + 1));
    }
  }

  public function test_the_limit_is_a_ceiling(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->limiter->hit('ip_a', 5, 60);
    }

    $this->assertFalse($this->limiter->hit('ip_a', 5, 60));
  }

  public function test_buckets_are_independent(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->limiter->hit('ip_a', 5, 60);
    }

    $this->assertTrue($this->limiter->hit('ip_b', 5, 60));
  }

  public function test_a_new_window_starts_a_fresh_count(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->limiter->hit('ip_a', 5, 60);
    }
    $this->assertFalse($this->limiter->hit('ip_a', 5, 60));

    $this->limiterClock->travel(60);

    $this->assertTrue($this->limiter->hit('ip_a', 5, 60));
  }

  public function test_counting_continues_within_the_same_window(): void {
    $this->limiter->hit('ip_a', 5, 60);
    // Windows are aligned to the wall clock rather than to the first hit, so
    // this stays inside the current one.
    $this->limiterClock->travel(20);

    for ($i = 0; $i < 4; $i++) {
      $this->limiter->hit('ip_a', 5, 60);
    }

    $this->assertFalse($this->limiter->hit('ip_a', 5, 60));
  }

  /**
   * Because windows are aligned rather than sliding, a burst straddling a
   * boundary can be admitted twice. That is a deliberate trade: this is abuse
   * mitigation, not billing, and it buys an atomic single-statement counter.
   */
  public function test_a_burst_across_a_window_boundary_is_admitted_twice(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->limiter->hit('ip_a', 5, 60);
    }
    $this->assertFalse($this->limiter->hit('ip_a', 5, 60));

    $this->limiterClock->travel(60);

    $this->assertTrue($this->limiter->hit('ip_a', 5, 60));
  }

  public function test_a_zero_limit_refuses_everything(): void {
    $this->assertFalse($this->limiter->hit('ip_a', 0, 60));
  }

  public function test_a_negative_limit_refuses_everything(): void {
    $this->assertFalse($this->limiter->hit('ip_a', -1, 60));
  }

  public function test_counter_rows_are_not_autoloaded(): void {
    global $wpdb;
    $this->limiter->hit('ip_a', 5, 60);

    $autoload = $wpdb->get_var(
      "SELECT autoload FROM {$wpdb->options} WHERE option_name LIKE 'blink_rl_ip_a%'"
    );

    $this->assertSame('no', $autoload);
  }

  /**
   * The increment is a single statement precisely so two concurrent callers
   * cannot both read the same value and both decide they are within budget.
   */
  public function test_the_increment_is_a_single_statement(): void {
    global $wpdb;
    $this->limiter->hit('ip_a', 100, 60);
    $this->limiter->hit('ip_a', 100, 60);
    $this->limiter->hit('ip_a', 100, 60);

    $value = $wpdb->get_var(
      "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'blink_rl_ip_a%'"
    );

    $this->assertSame('3', (string) $value);
  }

  public function test_garbage_collection_removes_stale_windows(): void {
    global $wpdb;
    $this->limiter->hit('ip_a', 5, 60);
    $this->limiterClock->travel(2 * DAY_IN_SECONDS);

    $this->limiter->collectGarbage();

    $this->assertSame(
      '0',
      (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'blink_rl_%'"
      )
    );
  }

  public function test_garbage_collection_keeps_the_current_window(): void {
    global $wpdb;
    $this->limiter->hit('ip_a', 5, 60);

    $this->limiter->collectGarbage();

    $this->assertSame(
      '1',
      (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'blink_rl_%'"
      )
    );
  }

  public function test_garbage_collection_ignores_rows_it_cannot_date(): void {
    global $wpdb;
    $wpdb->query(
      "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
       VALUES ('blink_rl_malformed', '1', 'no')"
    );

    $this->limiter->collectGarbage();

    $this->assertSame(
      '1',
      (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'blink_rl_malformed'"
      )
    );
  }

  // ---------------------------------------------------- degenerate responses

  /**
   * The counter is a budget on outbound requests. If the statement cannot be
   * built the count is never incremented, so the guard has to stop rather than
   * pass null to query() and leave the budget silently disabled.
   */
  public function test_a_statement_that_cannot_be_built_runs_nothing(): void {
    $db = new StubWpdb();
    $db->prepareReturnsNull = true;
    $limiter = new DbRateLimiter($db, new FakeClock(self::NOW));

    $limiter->hit('ip_a', 5, 60);

    $this->assertSame([], $db->ranQueries);
  }

  public function test_garbage_collection_runs_nothing_when_the_statement_cannot_be_built(): void {
    $db = new StubWpdb();
    $db->prepareReturnsNull = true;
    $limiter = new DbRateLimiter($db, new FakeClock(self::NOW));

    $limiter->collectGarbage();

    $this->assertSame([], $db->ranQueries);
  }

  public function test_garbage_collection_copes_with_a_query_that_returns_no_rows(): void {
    $db = new StubWpdb();
    $db->colResult = null;
    $limiter = new DbRateLimiter($db, new FakeClock(self::NOW));

    $limiter->collectGarbage();

    $this->assertSame([], $db->ranQueries, 'nothing to delete, so nothing deleted');
  }

  /**
   * Option names are read back and parsed in PHP. A name that does not carry a
   * window start must be left alone rather than guessed at -- deleting it
   * would mean deleting somebody else's option.
   */
  public function test_a_name_without_a_window_start_is_left_alone(): void {
    $db = new StubWpdb();
    $db->colResult = ['nowindowmarker'];
    $limiter = new DbRateLimiter($db, new FakeClock(self::NOW));

    $limiter->collectGarbage();

    $this->assertSame([], $db->ranQueries);
  }

  public function test_a_name_with_an_expired_window_is_deleted(): void {
    $db = new StubWpdb();
    $db->colResult = [
      DbRateLimiter::PREFIX . 'ip_a_' . sprintf('%010d', self::NOW - 200000),
    ];
    $limiter = new DbRateLimiter($db, new FakeClock(self::NOW));

    $limiter->collectGarbage();

    $this->assertNotSame([], $db->ranQueries, 'the stale counter is removed');
  }
}
