<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\NonCustodial\DnsResolverInterface;

/**
 * Maps hostnames to fixed answers so the private-address rules can be tested
 * exhaustively without real DNS.
 */
final class FakeDnsResolver implements DnsResolverInterface {
  /** @param array<string,list<string>> $answers */
  public function __construct(
    private array $answers = [],
    private array $fallback = []
  ) {}

  public function resolve(string $host): array {
    return $this->answers[strtolower($host)] ?? $this->fallback;
  }

  /** @param list<string> $ips */
  public function map(string $host, array $ips): self {
    $this->answers[strtolower($host)] = $ips;

    return $this;
  }

  /** Answer for any host not explicitly mapped. */
  public function fallbackTo(string ...$ips): self {
    $this->fallback = $ips;

    return $this;
  }
}
