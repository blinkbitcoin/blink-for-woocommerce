<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Http;

use Blink\WC\Http\HttpRequestOptions;
use PHPUnit\Framework\TestCase;

final class HttpRequestOptionsTest extends TestCase {
  public function testDefaultsAreStrict(): void {
    $options = new HttpRequestOptions();

    $this->assertSame(5.0, $options->connectTimeout);
    $this->assertSame(10.0, $options->timeout);
    $this->assertSame(65536, $options->maxBytes);
    $this->assertSame(['Accept' => 'application/json'], $options->headers);
    $this->assertSame([], $options->dnsPins);
    $this->assertFalse($options->allowPlainHttp);
  }

  public function testWithDnsPinsReturnsACopyAndKeepsEverythingElse(): void {
    $options = new HttpRequestOptions(connectTimeout: 1.0, timeout: 2.0, maxBytes: 10);

    $pinned = $options->withDnsPins(['blink.sv:443' => ['93.184.216.34']]);

    $this->assertNotSame($options, $pinned);
    $this->assertSame([], $options->dnsPins, 'the original must be untouched');
    $this->assertSame(['blink.sv:443' => ['93.184.216.34']], $pinned->dnsPins);
    $this->assertSame(1.0, $pinned->connectTimeout);
    $this->assertSame(2.0, $pinned->timeout);
    $this->assertSame(10, $pinned->maxBytes);
  }

  public function testWithPlainHttpAllowedReturnsACopyAndKeepsEverythingElse(): void {
    $options = (new HttpRequestOptions(maxBytes: 99))->withDnsPins(['a' => ['1.2.3.4']]);

    $relaxed = $options->withPlainHttpAllowed(true);

    $this->assertNotSame($options, $relaxed);
    $this->assertFalse($options->allowPlainHttp);
    $this->assertTrue($relaxed->allowPlainHttp);
    $this->assertSame(99, $relaxed->maxBytes);
    $this->assertSame(['a' => ['1.2.3.4']], $relaxed->dnsPins);
  }
}
