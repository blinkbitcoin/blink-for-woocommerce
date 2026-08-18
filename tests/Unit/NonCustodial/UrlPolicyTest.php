<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\LnAddress;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase {
  private FakeDnsResolver $dns;
  private SpyLogger $log;
  private UrlPolicy $policy;

  protected function setUp(): void {
    parent::setUp();
    $this->dns = (new FakeDnsResolver())->fallbackTo('93.184.216.34');
    $this->log = new SpyLogger();
    $this->policy = new UrlPolicy($this->dns, $this->log);
  }

  private function address(string $raw = 'shop@blink.sv'): LnAddress {
    $address = LnAddress::parse($raw);
    self::assertNotNull($address);

    return $address;
  }

  public function testAllowsTheAddressHostItself(): void {
    $decision = $this->policy->check('https://blink.sv/lnurlp/cb', $this->address());

    $this->assertTrue($decision->allowed);
    $this->assertSame('', $decision->reason);
  }

  public function testAllowsASubdomainOfTheAddressHost(): void {
    $this->assertTrue(
      $this->policy->check('https://api.pay.blink.sv/cb', $this->address())->allowed
    );
  }

  /**
   * The bug this class exists to fix: a last-two-labels rule collapses
   * pay.example.co.uk to co.uk, so every .co.uk host passed the check.
   */
  public function testRejectsAnUnrelatedHostSharingAMultiPartSuffix(): void {
    $decision = $this->policy->check(
      'https://evil.co.uk/cb',
      $this->address('shop@pay.example.co.uk')
    );

    $this->assertFalse($decision->allowed);
    $this->assertSame('host outside the address domain', $decision->reason);
  }

  public function testRejectsASuffixMatchThatIsNotASubdomain(): void {
    // notblink.sv ends with "blink.sv" as a string but is a different domain.
    $this->assertFalse($this->policy->check('https://notblink.sv/cb', $this->address())->allowed);
  }

  public function testRejectsADifferentRegistrableDomain(): void {
    $this->assertFalse($this->policy->check('https://blink.io/cb', $this->address())->allowed);
  }

  public function testRejectsPlainHttpOnAPublicDomain(): void {
    $decision = $this->policy->check('http://blink.sv/cb', $this->address());

    $this->assertFalse($decision->allowed);
    $this->assertSame('plain http outside local dev', $decision->reason);
  }

  /**
   * @dataProvider badSchemes
   */
  public function testRejectsNonHttpSchemes(string $url): void {
    $decision = $this->policy->check($url, $this->address());

    $this->assertFalse($decision->allowed);
    $this->assertContains($decision->reason, ['scheme not http(s)', 'unparseable']);
  }

  /** @return array<string,array{string}> */
  public static function badSchemes(): array {
    return [
      'file' => ['file:///etc/passwd'],
      'gopher' => ['gopher://blink.sv/'],
      'ftp' => ['ftp://blink.sv/x'],
      'no scheme' => ['//blink.sv/x'],
    ];
  }

  /**
   * @dataProvider unparseable
   */
  public function testRejectsUnparseableUrls(string $url): void {
    $this->assertFalse($this->policy->check($url, $this->address())->allowed);
  }

  /** @return array<string,array{string}> */
  public static function unparseable(): array {
    return [
      'empty' => [''],
      'garbage' => ['not a url'],
      'scheme only' => ['https://'],
      'too long' => ['https://blink.sv/' . str_repeat('a', 2100)],
    ];
  }

  public function testRejectsCredentialsInTheAuthority(): void {
    $decision = $this->policy->check('https://user:pass@blink.sv/cb', $this->address());

    $this->assertFalse($decision->allowed);
    $this->assertSame('credentials in url', $decision->reason);
  }

  public function testRejectsUserWithoutPassword(): void {
    $this->assertFalse($this->policy->check('https://user@blink.sv/cb', $this->address())->allowed);
  }

  public function testRejectsABareIpHostForAPublicAddress(): void {
    $decision = $this->policy->check('https://93.184.216.34/cb', $this->address());

    $this->assertFalse($decision->allowed);
    $this->assertSame('ip literal host', $decision->reason);
  }

  public function testRejectsAHostThatDoesNotResolve(): void {
    $this->dns->map('blink.sv', []);

    $decision = $this->policy->check('https://blink.sv/cb', $this->address());

    $this->assertFalse($decision->allowed);
    $this->assertSame('host does not resolve', $decision->reason);
  }

  /**
   * @dataProvider addresses
   */
  public function testPrivateAndReservedAddressesAreRejected(string $ip, bool $allowed): void {
    $this->dns->map('blink.sv', [$ip]);

    $decision = $this->policy->check('https://blink.sv/cb', $this->address());

    $this->assertSame($allowed, $decision->allowed, $ip . ' should be ' . ($allowed ? 'allowed' : 'rejected'));
  }

  /** @return array<string,array{string,bool}> */
  public static function addresses(): array {
    return [
      'public v4' => ['93.184.216.34', true],
      'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true],
      'rfc1918 10/8' => ['10.0.0.5', false],
      'rfc1918 172.16/12 low' => ['172.16.0.1', false],
      'rfc1918 172.16/12 high' => ['172.31.255.254', false],
      // Guards against an over-broad hand-rolled 172.* check.
      'just outside rfc1918' => ['172.32.0.1', true],
      'rfc1918 192.168/16' => ['192.168.1.1', false],
      'loopback' => ['127.0.0.1', false],
      'this network' => ['0.0.0.0', false],
      'cloud metadata' => ['169.254.169.254', false],
      // FILTER_FLAG_NO_RES_RANGE does not cover carrier-grade NAT.
      'cgnat low' => ['100.64.0.1', false],
      'cgnat high' => ['100.127.255.254', false],
      'just below cgnat' => ['100.63.255.255', true],
      'just above cgnat' => ['100.128.0.1', true],
      'v6 loopback' => ['::1', false],
      'v6 unique local' => ['fc00::1', false],
      'v6 link local' => ['fe80::1', false],
      // filter_var does not apply IPv4 private ranges to the mapped form.
      'ipv4-mapped loopback' => ['::ffff:127.0.0.1', false],
      'ipv4-mapped rfc1918' => ['::ffff:10.1.2.3', false],
      'ipv4-mapped public' => ['::ffff:93.184.216.34', true],
      'nat64 loopback' => ['64:ff9b::127.0.0.1', false],
      'nat64 public' => ['64:ff9b::93.184.216.34', true],
      'not an ip at all' => ['nonsense', false],
    ];
  }

  public function testEveryResolvedAddressMustPassNotJustOne(): void {
    $this->dns->map('blink.sv', ['93.184.216.34', '10.0.0.1']);

    $decision = $this->policy->check('https://blink.sv/cb', $this->address());

    $this->assertFalse($decision->allowed, 'a single private answer must sink the whole host');
    $this->assertStringContainsString('10.0.0.1', $decision->reason);
  }

  public function testAllowedDecisionCarriesPinsForTheDefaultHttpsPort(): void {
    $this->dns->map('blink.sv', ['93.184.216.34', '2606:2800::1']);

    $decision = $this->policy->check('https://blink.sv/cb', $this->address());

    $this->assertSame(
      ['blink.sv:443' => ['93.184.216.34', '2606:2800::1']],
      $decision->dnsPins
    );
  }

  public function testPinsUseAnExplicitPortWhenPresent(): void {
    $this->dns->map('blink.sv', ['93.184.216.34']);

    $decision = $this->policy->check('https://blink.sv:8443/cb', $this->address());

    $this->assertSame(['blink.sv:8443' => ['93.184.216.34']], $decision->dnsPins);
  }

  public function testDeniedDecisionCarriesNoPins(): void {
    $this->assertSame([], $this->policy->check('https://blink.io/cb', $this->address())->dnsPins);
  }

  public function testLocalDevAddressMayReachLocalHostsOverPlainHttp(): void {
    $decision = $this->policy->check(
      'http://localhost:8889/blink-e2e/callback',
      $this->address('ok@localhost:8889')
    );

    $this->assertTrue($decision->allowed);
  }

  public function testLocalDevAddressMayNotReachThePublicInternet(): void {
    $decision = $this->policy->check(
      'https://blink.sv/cb',
      $this->address('ok@localhost:8889')
    );

    $this->assertFalse($decision->allowed);
    $this->assertSame('local dev address may only reach local hosts', $decision->reason);
  }

  public function testLocalDevAddressMayReachAnIpLiteral(): void {
    $this->assertTrue(
      $this->policy->check('http://127.0.0.1:8889/cb', $this->address('ok@localhost'))->allowed
    );
  }

  public function testPublicAddressMayNotBeDowngradedByALocalTarget(): void {
    $this->assertFalse(
      $this->policy->check('http://localhost:8889/cb', $this->address())->allowed
    );
  }

  public function testTrailingDotHostIsTreatedAsTheSameDomain(): void {
    $this->dns->map('blink.sv.', ['93.184.216.34']);

    $this->assertTrue($this->policy->check('https://blink.sv./cb', $this->address())->allowed);
  }

  public function testRejectionIsLoggedWithTheReason(): void {
    $this->policy->check('https://blink.io/cb', $this->address());

    $this->assertTrue($this->log->hasMessageContaining('host outside the address domain'));
    $this->assertTrue($this->log->hasMessageContaining('https://blink.io/cb'));
  }

  public function testApprovalIsNotLogged(): void {
    $this->policy->check('https://blink.sv/cb', $this->address());

    $this->assertSame([], $this->log->records);
  }
}
