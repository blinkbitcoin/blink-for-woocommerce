<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

enum SettlementStatus: string {
  case Pending = 'PENDING';
  case Paid = 'PAID';
  case Expired = 'EXPIRED';
  case Review = 'REVIEW';

  /**
   * Nothing useful was learned -- the budget was spent, another process held
   * the lock, or there was no invoice to check. Callers must not act on it.
   */
  case Unknown = 'UNKNOWN';
}
