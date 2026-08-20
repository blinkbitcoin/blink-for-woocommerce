<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/** A deliberately small default that can be replaced through the service seam. */
final class FixedSettlementModeProvider implements SettlementModeProviderInterface {
  public function __construct(private SettlementMode $mode) {}

  public function mode(): SettlementMode {
    return $this->mode;
  }
}
