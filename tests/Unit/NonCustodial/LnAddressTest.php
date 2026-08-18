<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\LnAddress;
use PHPUnit\Framework\TestCase;

final class LnAddressTest extends TestCase {
  /**
   * @dataProvider validAddresses
   */
  public function testParsesValidAddresses(
    string $raw,
    string $identifier,
    string $host,
    ?int $port
  ): void {
    $address = LnAddress::parse($raw);

    $this->assertNotNull($address, $raw . ' should parse');
    $this->assertSame($identifier, $address->identifier);
    $this->assertSame($host, $address->host);
    $this->assertSame($port, $address->port);
  }

  /** @return array<string,array{string,string,string,int|null}> */
  public static function validAddresses(): array {
    return [
      'plain' => ['shop@blink.sv', 'shop', 'blink.sv', null],
      'subdomain' => ['a@pay.example.co.uk', 'a', 'pay.example.co.uk', null],
      'uppercase host is normalised' => ['shop@BLINK.SV', 'shop', 'blink.sv', null],
      'identifier case is preserved' => ['ShopName@blink.sv', 'ShopName', 'blink.sv', null],
      'dots and dashes in identifier' => ['a.b-c_d@blink.sv', 'a.b-c_d', 'blink.sv', null],
      'explicit port' => ['ok@localhost:8889', 'ok', 'localhost', 8889],
      'ipv4 literal' => ['ok@127.0.0.1', 'ok', '127.0.0.1', null],
      'ipv4 with port' => ['ok@127.0.0.1:8080', 'ok', '127.0.0.1', 8080],
      'bracketed ipv6' => ['ok@[::1]', 'ok', '[::1]', null],
      'bracketed ipv6 with port' => ['ok@[::1]:8080', 'ok', '[::1]', 8080],
      'surrounding whitespace' => ['  shop@blink.sv  ', 'shop', 'blink.sv', null],
      'max length label' => ['a@' . str_repeat('x', 63) . '.sv', 'a', str_repeat('x', 63) . '.sv', null],
      'lowest port' => ['a@blink.sv:1', 'a', 'blink.sv', 1],
      'highest port' => ['a@blink.sv:65535', 'a', 'blink.sv', 65535],
    ];
  }

  /**
   * @dataProvider invalidAddresses
   */
  public function testRejectsInvalidAddresses(string $raw): void {
    $this->assertNull(LnAddress::parse($raw), $raw . ' should not parse');
  }

  /** @return array<string,array{string}> */
  public static function invalidAddresses(): array {
    return [
      'empty' => [''],
      'whitespace only' => ['   '],
      'no at sign' => ['blink.sv'],
      'two at signs' => ['a@b@blink.sv'],
      'empty identifier' => ['@blink.sv'],
      'empty domain' => ['shop@'],
      'space in identifier' => ['a b@blink.sv'],
      'slash in identifier' => ['a/b@blink.sv'],
      'path traversal in identifier' => ['../../etc@blink.sv'],
      'leading dot in host' => ['a@.blink.sv'],
      'trailing dot in host' => ['a@blink.sv.'],
      'consecutive dots' => ['a@blink..sv'],
      'leading dash in label' => ['a@-blink.sv'],
      'trailing dash in label' => ['a@blink-.sv'],
      'underscore in host' => ['a@bl_ink.sv'],
      'label too long' => ['a@' . str_repeat('x', 64) . '.sv'],
      'host too long' => ['a@' . str_repeat('x.', 130) . 'sv'],
      'port zero' => ['a@blink.sv:0'],
      'port too high' => ['a@blink.sv:65536'],
      'port not numeric' => ['a@blink.sv:http'],
      'port empty' => ['a@blink.sv:'],
      'bare ipv6 without brackets' => ['a@::1'],
      'unclosed bracket' => ['a@[::1'],
      'invalid ipv6 in brackets' => ['a@[not-an-ip]'],
      'bad port after bracket' => ['a@[::1]:0'],
    ];
  }

  /**
   * @dataProvider localDevHosts
   */
  public function testLocalDevDetection(string $raw, bool $expected): void {
    $address = LnAddress::parse($raw);

    $this->assertNotNull($address);
    $this->assertSame($expected, $address->isLocalDev());
    $this->assertSame($expected ? 'http' : 'https', $address->scheme());
  }

  /** @return array<string,array{string,bool}> */
  public static function localDevHosts(): array {
    return [
      'localhost' => ['a@localhost', true],
      'localhost with port' => ['a@localhost:8889', true],
      'loopback v4' => ['a@127.0.0.1', true],
      'loopback v6' => ['a@[::1]', true],
      'dot test tld' => ['a@shop.test', true],
      'dot local tld' => ['a@shop.local', true],
      'dot localhost tld' => ['a@shop.localhost', true],
      'public domain' => ['a@blink.sv', false],
      'public domain resembling local' => ['a@localhost.example.com', false],
      'public ip' => ['a@93.184.216.34', false],
    ];
  }

  public function testDomainIncludesPortOnlyWhenPresent(): void {
    $this->assertSame('blink.sv', LnAddress::parse('a@blink.sv')?->domain());
    $this->assertSame('localhost:8889', LnAddress::parse('a@localhost:8889')?->domain());
  }

  public function testWellKnownUrlUsesHttpsForPublicHosts(): void {
    $this->assertSame(
      'https://blink.sv/.well-known/lnurlp/shop',
      LnAddress::parse('shop@blink.sv')?->wellKnownUrl()
    );
  }

  public function testWellKnownUrlUsesHttpAndPortForLocalDev(): void {
    $this->assertSame(
      'http://localhost:8889/.well-known/lnurlp/ok',
      LnAddress::parse('ok@localhost:8889')?->wellKnownUrl()
    );
  }

  public function testWellKnownUrlEncodesTheIdentifier(): void {
    // A dot is legal in an identifier and must survive; anything needing
    // encoding is rejected at parse time, so this pins the encoding call.
    $this->assertSame(
      'https://blink.sv/.well-known/lnurlp/first.last',
      LnAddress::parse('first.last@blink.sv')?->wellKnownUrl()
    );
  }

  public function testStringRoundTrip(): void {
    $this->assertSame('shop@blink.sv', (string) LnAddress::parse('shop@BLINK.SV'));
    $this->assertSame('ok@localhost:8889', (string) LnAddress::parse('ok@localhost:8889'));
  }

  public function testHostIsLocalDevIsUsableWithoutAnInstance(): void {
    $this->assertTrue(LnAddress::hostIsLocalDev('LOCALHOST'));
    $this->assertFalse(LnAddress::hostIsLocalDev('blink.sv'));
  }
}
