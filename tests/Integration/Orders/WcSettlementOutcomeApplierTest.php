<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Orders;

use Blink\WC\NonCustodial\OrderRecord;
use Blink\WC\NonCustodial\SettlementOutcome;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\Orders\WcSettlementOutcomeApplier;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The step that turns a settlement result into a WooCommerce order status.
 *
 * BackgroundSettlementTest covers the paths that move an order. What is left
 * here are the refusals -- the cases where the applier must do nothing at all,
 * which are invisible from the outside unless asserted directly.
 */
final class WcSettlementOutcomeApplierTest extends IntegrationTestCase {
  private WcSettlementOutcomeApplier $outcomeApplier;

  public function set_up() {
    parent::set_up();
    $this->outcomeApplier = $this->services()->outcomeApplier();
  }

  private function outcome(SettlementStatus $status): SettlementOutcome {
    return new SettlementOutcome($status, self::NOW);
  }

  /**
   * The applier works against WooCommerce orders. Another OrderRecord
   * implementation carries no WC_Order to move, so the only correct response
   * is to leave it alone rather than reach for a method it does not have.
   */
  public function test_an_order_that_is_not_a_woocommerce_order_is_left_alone(): void {
    $record = new class implements OrderRecord {
      public function id(): int {
        return 4242;
      }

      public function getMeta(string $key): mixed {
        return '';
      }

      public function setMeta(string $key, mixed $value): void {
      }

      public function deleteMeta(string $key): void {
      }

      public function save(): void {
      }

      public function total(): string {
        return '10.00';
      }

      public function currency(): string {
        return 'USD';
      }
    };

    $this->outcomeApplier->applyOutcome($record, $this->outcome(SettlementStatus::Paid));

    $this->assertTrue(true, 'no exception: the applier declined to act');
  }

  public function test_an_unknown_outcome_leaves_the_order_pending(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $this->outcomeApplier->applyOutcome(
      $this->record($order),
      $this->outcome(SettlementStatus::Unknown)
    );

    $this->assertSame('pending', $this->reload($order)->get_status());
    $this->assertSame([], $this->noteContents($order));
  }

  public function test_a_pending_outcome_leaves_the_order_pending(): void {
    $order = $this->makeOrder();
    $this->storeInvoice($order);

    $this->outcomeApplier->applyOutcome(
      $this->record($order),
      $this->outcome(SettlementStatus::Pending)
    );

    $this->assertSame('pending', $this->reload($order)->get_status());
    $this->assertSame([], $this->noteContents($order));
  }
}
