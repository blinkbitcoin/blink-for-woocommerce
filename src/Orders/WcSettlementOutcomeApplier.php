<?php

declare(strict_types=1);

namespace Blink\WC\Orders;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\OrderRecord;
use Blink\WC\NonCustodial\SettlementOutcome;
use Blink\WC\NonCustodial\SettlementOutcomeApplier;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\WcOrderRecord;

/**
 * Moves a WooCommerce order to match a settlement outcome.
 *
 * Notes are deduplicated because settlement is observed repeatedly -- by the
 * scheduler and by every open pay page -- and an order note per observation
 * would bury the order's real history.
 */
final class WcSettlementOutcomeApplier implements SettlementOutcomeApplier {
  public function __construct(
    private OrderStatusApplier $applier,
    private InvoiceRepository $repository
  ) {
  }

  public function applyOutcome(OrderRecord $order, SettlementOutcome $outcome): void {
    // Nothing conclusive was learned, so the order is left alone.
    if (
      $outcome->status === SettlementStatus::Unknown ||
      $outcome->status === SettlementStatus::Pending
    ) {
      return;
    }

    if (!$order instanceof WcOrderRecord) {
      return;
    }

    $wcOrder = $order->order();
    $this->applier->apply($wcOrder, $outcome->status->value, 'blink', true);

    if ($outcome->status === SettlementStatus::Paid) {
      $invoice = $this->repository->load($order);
      $this->applier->completePayment($wcOrder, (string) $invoice?->paymentHash);
    }
  }
}
