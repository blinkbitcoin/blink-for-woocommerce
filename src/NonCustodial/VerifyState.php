<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * The outcome of one LUD-21 verify request.
 *
 * NotFound and TransportError are deliberately distinct. Collapsing them --
 * which the previous client did, because any non-2xx became an exception --
 * is what let a network blip be read as "this invoice is gone".
 */
enum VerifyState: string {
  case Settled = 'settled';
  case Unsettled = 'unsettled';
  case NotFound = 'not_found';
  case TransportError = 'transport_error';
  case PolicyError = 'policy_error';

  /** Whether this outcome says anything trustworthy about the payment. */
  public function isConclusive(): bool {
    return $this === self::Settled || $this === self::Unsettled;
  }
}
