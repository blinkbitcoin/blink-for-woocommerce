<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Resolves a hostname to IP addresses.
 *
 * Injected so the private-address rules can be tested exhaustively without
 * depending on real DNS, which would make the suite non-hermetic and flaky.
 */
interface DnsResolverInterface {
  /** @return list<string> IPv4 and IPv6 literals; empty when resolution fails. */
  public function resolve(string $host): array;
}
