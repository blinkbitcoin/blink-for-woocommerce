<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

interface UrlPolicyInterface {
  public function check(string $url, LnAddress $address): UrlDecision;
}
