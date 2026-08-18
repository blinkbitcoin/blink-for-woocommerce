<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

final class SystemDnsResolver implements DnsResolverInterface {
  /** @var callable(string):(list<string>|false) */
  private $lookupV4;

  /** @var callable(string):(list<array<string,mixed>>|false) */
  private $lookupV6;

  /**
   * The two lookups are injectable so the address handling can be tested
   * without a working network.
   *
   * They are seams, not configuration: nothing but the tests passes anything
   * here. Resolving a name with real AAAA records would otherwise make the
   * IPv6 branch depend on the DNS the build machine happens to have, which is
   * the kind of test that fails on a train.
   *
   * @param null|callable(string):(list<string>|false) $lookupV4
   * @param null|callable(string):(list<array<string,mixed>>|false) $lookupV6
   */
  public function __construct(?callable $lookupV4 = null, ?callable $lookupV6 = null) {
    $this->lookupV4 = $lookupV4 ?? static fn(string $host) => @gethostbynamel($host);
    $this->lookupV6 = $lookupV6 ?? static fn(string $host) => self::lookupAaaa($host);
  }

  /**
   * The default AAAA lookup, kept as a named method so both of its outcomes
   * can be reached from a test.
   *
   * dns_get_record is part of PHP's standard extension but shared hosts do
   * disable it, and this plugin's audience is full of shared hosts. Without
   * the guard the plugin would fatal on every outbound request there; with it,
   * such a host simply resolves IPv4 only.
   *
   * @param null|callable(string):bool $functionExists seam for the guard
   * @return list<array<string,mixed>>|false
   */
  public static function lookupAaaa(string $host, ?callable $functionExists = null) {
    $exists = $functionExists ?? static fn(string $name): bool => function_exists($name);

    if (!$exists('dns_get_record')) {
      return false;
    }

    return @dns_get_record($host, DNS_AAAA);
  }

  public function resolve(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
      return [$host];
    }

    $ips = [];

    $v4 = ($this->lookupV4)($host);
    if (is_array($v4)) {
      $ips = $v4;
    }

    $aaaa = ($this->lookupV6)($host);
    if (is_array($aaaa)) {
      foreach ($aaaa as $record) {
        if (!empty($record['ipv6'])) {
          $ips[] = $record['ipv6'];
        }
      }
    }

    return array_values(array_unique($ips));
  }
}
