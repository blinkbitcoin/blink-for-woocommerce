<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Support;

use Blink\WC\Support\ActionSchedulerAdapter;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The adapter against the real Action Scheduler that WooCommerce ships.
 *
 * Settlement without a browser rests entirely on this working, and the three
 * methods are thin enough that the only thing worth checking is that they are
 * wired to the right functions and translate the return values correctly.
 */
final class ActionSchedulerAdapterTest extends IntegrationTestCase {
  private const HOOK = 'blink_test_adapter_hook';
  private const GROUP = 'blink-test-adapter';

  private ActionSchedulerAdapter $adapter;

  public function set_up() {
    parent::set_up();
    $this->adapter = new ActionSchedulerAdapter();
  }

  public function tear_down() {
    as_unschedule_all_actions(self::HOOK, [], self::GROUP);
    as_unschedule_all_actions(self::HOOK, [7], self::GROUP);
    parent::tear_down();
  }

  public function test_action_scheduler_is_present(): void {
    $this->assertTrue($this->adapter->isAvailable());
  }

  public function test_nothing_is_scheduled_to_begin_with(): void {
    $this->assertNull($this->adapter->nextScheduled(self::HOOK, [7], self::GROUP));
  }

  public function test_a_scheduled_action_is_reported_with_its_timestamp(): void {
    $when = time() + 3600;

    $this->adapter->scheduleSingle($when, self::HOOK, [7], self::GROUP);

    $this->assertSame($when, $this->adapter->nextScheduled(self::HOOK, [7], self::GROUP));
  }

  /**
   * Action Scheduler answers `true`, not a timestamp, for an action that has
   * no scheduled date -- an async action queued to run at the next
   * opportunity. Passing that through unconverted would hand callers `1`, a
   * timestamp in 1970, and every "is it due yet" comparison would be wrong.
   *
   * A past-dated single action does not produce this: it still carries its
   * date and is reported as that timestamp. Enqueuing an async action is how
   * the case arises through the real API.
   */
  public function test_an_action_with_no_scheduled_date_is_reported_as_zero(): void {
    as_enqueue_async_action(self::HOOK, [7], self::GROUP);

    $this->assertSame(0, $this->adapter->nextScheduled(self::HOOK, [7], self::GROUP));
  }

  public function test_a_past_dated_action_keeps_its_timestamp(): void {
    $when = time() - 60;

    $this->adapter->scheduleSingle($when, self::HOOK, [7], self::GROUP);

    $this->assertSame($when, $this->adapter->nextScheduled(self::HOOK, [7], self::GROUP));
  }

  public function test_unscheduling_removes_the_action(): void {
    $this->adapter->scheduleSingle(time() + 3600, self::HOOK, [7], self::GROUP);

    $this->adapter->unscheduleAll(self::HOOK, [7], self::GROUP);

    $this->assertNull($this->adapter->nextScheduled(self::HOOK, [7], self::GROUP));
  }

  public function test_unscheduling_is_scoped_to_the_arguments_given(): void {
    $when = time() + 3600;
    $this->adapter->scheduleSingle($when, self::HOOK, [7], self::GROUP);
    $this->adapter->scheduleSingle($when, self::HOOK, [], self::GROUP);

    $this->adapter->unscheduleAll(self::HOOK, [], self::GROUP);

    $this->assertSame(
      $when,
      $this->adapter->nextScheduled(self::HOOK, [7], self::GROUP),
      'the other order must keep its check'
    );
  }
}
