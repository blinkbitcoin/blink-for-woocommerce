<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

interface SettlementModeProviderInterface {
  public function mode(): SettlementMode;
}
