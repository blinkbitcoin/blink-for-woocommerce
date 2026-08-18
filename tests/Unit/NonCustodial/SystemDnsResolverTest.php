<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\SystemDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The resolver feeds UrlPolicy, which decides whether a host may be reached at
 * all. Its job is to return every address a name resolves to: missing one is
 * how an SSRF check gets bypassed, because the address that was never returned
 * is the address never examined.
 */
final class SystemDnsResolverTest extends TestCase {
  /**
   * @param list<string>|false $v4
   * @param list<array<string,mixed>>|false $v6
   */
  private function resolver($v4 = false, $v6 = false): SystemDnsResolver {
    return new SystemDnsResolver(
      static fn(string $host) => $v4,
      static fn(string $host) => $v6
    );
  }

  public function testALiteralIpv4AddressIsReturnedWithoutALookup(): void {
    $resolver = new SystemDnsResolver(
      static fn(string $host) => self::fail('no lookup should happen'),
      static fn(string $host) => self::fail('no lookup should happen')
    );

    $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
  }

  public function testALiteralIpv6AddressIsReturnedWithoutALookup(): void {
    $this->assertSame(['::1'], $this->resolver()->resolve('::1'));
  }

  public function testIpv4AddressesAreReturned(): void {
    $resolver = $this->resolver(['93.184.216.34', '93.184.216.35']);

    $this->assertSame(
      ['93.184.216.34', '93.184.216.35'],
      $resolver->resolve('example.com')
    );
  }

  public function testIpv6AddressesAreReturnedAlongsideIpv4(): void {
    $resolver = $this->resolver(
      ['93.184.216.34'],
      [['type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25c8:1946']]
    );

    $this->assertSame(
      ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
      $resolver->resolve('example.com')
    );
  }

  /** A name with AAAA records but no A records still has to be examined. */
  public function testAnIpv6OnlyHostStillResolves(): void {
    $resolver = $this->resolver(false, [['ipv6' => '::1']]);

    $this->assertSame(['::1'], $resolver->resolve('v6only.example'));
  }

  public function testRecordsWithoutAnIpv6FieldAreSkipped(): void {
    $resolver = $this->resolver(false, [
      ['type' => 'AAAA'],
      ['type' => 'AAAA', 'ipv6' => ''],
      ['type' => 'AAAA', 'ipv6' => '::2'],
    ]);

    $this->assertSame(['::2'], $resolver->resolve('partial.example'));
  }

  public function testAFailedLookupYieldsNoAddresses(): void {
    $this->assertSame([], $this->resolver()->resolve('blink-test.invalid'));
  }

  public function testDuplicateAddressesAreCollapsedAndKeysStaySequential(): void {
    $resolver = $this->resolver(
      ['93.184.216.34', '93.184.216.34'],
      [['ipv6' => '::1'], ['ipv6' => '::1']]
    );

    $result = $resolver->resolve('example.com');

    $this->assertSame(['93.184.216.34', '::1'], $result);
    $this->assertSame([0, 1], array_keys($result));
  }

  /**
   * The default construction path is what production uses; the seams exist for
   * the branches above, and would be worth little if the real lookups were
   * never wired up.
   */
  public function testTheDefaultLookupsResolveALiteralAddress(): void {
    $this->assertSame(['127.0.0.1'], (new SystemDnsResolver())->resolve('127.0.0.1'));
  }

  public function testTheDefaultLookupsReturnNothingForAnUnresolvableName(): void {
    $this->assertSame([], (new SystemDnsResolver())->resolve('blink-test.invalid'));
  }

  /**
   * Shared hosts disable dns_get_record. When that happens the resolver must
   * fall back to IPv4 rather than fatal, because a fatal here takes down every
   * outbound request the plugin makes.
   */
  public function testTheDefaultAaaaLookupIsSkippedWhereTheFunctionIsUnavailable(): void {
    $result = SystemDnsResolver::lookupAaaa(
      'example.com',
      static fn(string $name): bool => false
    );

    $this->assertFalse($result);
  }

  public function testTheDefaultAaaaLookupRunsWhereTheFunctionIsAvailable(): void {
    $seen = [];
    $result = SystemDnsResolver::lookupAaaa('blink-test.invalid', static function (
      string $name
    ) use (&$seen): bool {
      $seen[] = $name;

      return function_exists($name);
    });

    $this->assertSame(['dns_get_record'], $seen);
    // An unresolvable name yields no records; the point is that it was tried.
    $this->assertNotTrue($result);
  }
}
