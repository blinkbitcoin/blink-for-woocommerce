<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\RateLimiterInterface;

/**
 * Bounds outbound LNURL traffic.
 *
 * Three separate ceilings, because they protect different things: the per-IP
 * budget stops the public poll endpoint being used as an amplifier, the
 * per-domain budget protects the merchant's own LNURL provider from this shop,
 * and the global budget caps what the site can emit in total.
 *
 * Exceeding a budget is never an error shown to a customer. The caller falls
 * back to the last cached status and tries again later.
 */
final class PollBudget {
  public const PER_IP_LIMIT = 30;
  public const PER_DOMAIN_LIMIT = 120;
  public const GLOBAL_LIMIT = 300;
  public const WINDOW_SECONDS = 60;

  public function __construct(private RateLimiterInterface $limiter) {}

  public function allowIp(string $ip): bool {
    return $this->limiter->hit('ip_' . md5($ip), self::PER_IP_LIMIT, self::WINDOW_SECONDS);
  }

  public function allowDomain(string $host): bool {
    return $this->limiter->hit(
      'domain_' . md5(strtolower($host)),
      self::PER_DOMAIN_LIMIT,
      self::WINDOW_SECONDS
    );
  }

  public function allowGlobal(): bool {
    return $this->limiter->hit('global', self::GLOBAL_LIMIT, self::WINDOW_SECONDS);
  }

  /** Both outbound ceilings, checked together before a verify request. */
  public function allowOutbound(string $host): bool {
    // Order matters only for which counter records the hit first; both are
    // incremented so a blocked request still counts against the shop.
    $domainOk = $this->allowDomain($host);
    $globalOk = $this->allowGlobal();

    return $domainOk && $globalOk;
  }
}
