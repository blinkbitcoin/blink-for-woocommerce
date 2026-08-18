<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Support;

use Blink\WC\Support\DbLock;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The lock against a real MySQL, which is the only place its atomicity claim
 * can actually be checked.
 */
final class DbLockTest extends IntegrationTestCase {
  private DbLock $lock;
  private FakeClock $lockClock;

  public function set_up() {
    parent::set_up();
    global $wpdb;
    $this->lockClock = new FakeClock(self::NOW);
    $this->lock = new DbLock($wpdb, $this->lockClock);
  }

  public function tear_down() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'blink_lock_%'");
    parent::tear_down();
  }

  public function test_an_uncontended_lock_is_granted(): void {
    $this->assertNotNull($this->lock->acquire('order_1', 30));
  }

  public function test_a_held_lock_is_refused(): void {
    $this->assertNotNull($this->lock->acquire('order_1', 30));

    $this->assertNull($this->lock->acquire('order_1', 30), 'a second holder must be refused');
  }

  public function test_locks_are_independent_per_key(): void {
    $this->lock->acquire('order_1', 30);

    $this->assertNotNull($this->lock->acquire('order_2', 30));
  }

  public function test_a_released_lock_can_be_taken_again(): void {
    $token = $this->lock->acquire('order_1', 30);
    $this->lock->release('order_1', (string) $token);

    $this->assertNotNull($this->lock->acquire('order_1', 30));
  }

  public function test_an_expired_lock_can_be_taken_over(): void {
    $this->lock->acquire('order_1', 30);

    $this->lockClock->travel(31);

    $this->assertNotNull($this->lock->acquire('order_1', 30));
  }

  public function test_a_lock_is_still_held_at_the_moment_it_expires(): void {
    $this->lock->acquire('order_1', 30);

    $this->lockClock->travel(30);

    $this->assertNull($this->lock->acquire('order_1', 30));
  }

  /**
   * A process whose lock expired and was taken over must not be able to
   * release the new holder's lock on its way out.
   */
  public function test_a_stale_holder_cannot_release_its_successors_lock(): void {
    $stale = (string) $this->lock->acquire('order_1', 30);
    $this->lockClock->travel(31);
    $fresh = $this->lock->acquire('order_1', 30);

    $this->lock->release('order_1', $stale);

    $this->assertNull(
      $this->lock->acquire('order_1', 30),
      'the fresh lock must still be held'
    );
    $this->assertNotNull($fresh);
  }

  public function test_releasing_with_an_unknown_token_does_nothing(): void {
    $this->lock->acquire('order_1', 30);

    $this->lock->release('order_1', 'not-the-token');

    $this->assertNull($this->lock->acquire('order_1', 30));
  }

  /** Lock rows must not be loaded on every page request. */
  public function test_lock_rows_are_not_autoloaded(): void {
    global $wpdb;
    $this->lock->acquire('order_1', 30);

    $autoload = $wpdb->get_var(
      "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'blink_lock_order_1'"
    );

    $this->assertSame('no', $autoload);
  }

  /**
   * The zero-padded expiry prefix is what makes a string comparison order
   * locks correctly, so a short timestamp must not sort above a long one.
   */
  public function test_expiry_is_stored_zero_padded_so_it_sorts_numerically(): void {
    global $wpdb;
    $this->lock->acquire('order_1', 30);

    $value = $wpdb->get_var(
      "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blink_lock_order_1'"
    );

    $this->assertMatchesRegularExpression('/^\d{10}:[0-9a-f]{16}$/', (string) $value);
  }

  public function test_garbage_collection_removes_long_expired_locks(): void {
    global $wpdb;
    $this->lock->acquire('order_1', 30);
    $this->lockClock->travel(2 * HOUR_IN_SECONDS);

    $this->lock->collectGarbage();

    $this->assertSame(
      '0',
      (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'blink_lock_%'"
      )
    );
  }

  public function test_garbage_collection_keeps_live_locks(): void {
    global $wpdb;
    $this->lock->acquire('order_1', 30);

    $this->lock->collectGarbage();

    $this->assertSame(
      '1',
      (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'blink_lock_%'"
      )
    );
  }

  /**
   * These rows are never read through get_option(), but a stale cache entry
   * elsewhere would be confusing, so the option cache is cleared for them.
   */
  public function test_the_option_cache_is_not_left_holding_a_stale_value(): void {
    $this->lock->acquire('order_1', 30);
    $token = $this->lock->acquire('order_2', 30);
    $this->lock->release('order_2', (string) $token);

    $this->assertFalse(wp_cache_get('blink_lock_order_2', 'options'));
  }
}
