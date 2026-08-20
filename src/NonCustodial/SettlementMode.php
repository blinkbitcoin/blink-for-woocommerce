<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Chooses which independent triggers may ask the shared settlement service
 * to verify an invoice.
 *
 * The modes never change how settlement is validated or applied. They only
 * decide whether the background worker runs and whether a stale payment-page
 * observation may initiate a guarded live check.
 */
enum SettlementMode: string {
  case BrowserOnly = 'browser_only';
  case Hybrid = 'hybrid';
  case WorkerOnly = 'worker_only';

  public function usesBackgroundWorker(): bool {
    return $this !== self::BrowserOnly;
  }

  public function allowsPaymentPageVerification(): bool {
    return $this !== self::WorkerOnly;
  }
}
