<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * The result of checking one URL against the SSRF policy.
 *
 * Carries the resolved addresses so the request that follows can be pinned to
 * exactly the IPs that were validated.
 */
final class UrlDecision {
  /** @param array<string,list<string>> $dnsPins */
  private function __construct(
    public readonly bool $allowed,
    public readonly string $reason,
    public readonly array $dnsPins
  ) {
  }

  /** @param array<string,list<string>> $dnsPins */
  public static function allow(array $dnsPins = []): self {
    return new self(true, '', $dnsPins);
  }

  public static function deny(string $reason): self {
    return new self(false, $reason, []);
  }
}
