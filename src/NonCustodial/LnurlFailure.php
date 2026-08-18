<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * A named reason an LNURL exchange could not be completed.
 *
 * Returned rather than thrown so the caller is forced to consider it: the
 * difference between "this shop is misconfigured" and "the network hiccuped"
 * decides whether a customer sees an error or a retry.
 */
final class LnurlFailure {
  public function __construct(
    public readonly string $code,
    public readonly string $message
  ) {
  }
}
