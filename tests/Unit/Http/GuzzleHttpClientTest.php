<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\Http;

use Blink\WC\Http\GuzzleHttpClient;
use Blink\WC\Http\HttpRequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class GuzzleHttpClientTest extends TestCase {
  /** @var list<array<string,mixed>> */
  private array $captured = [];

  /** @var list<\Psr\Http\Message\RequestInterface> */
  private array $sent = [];

  private function clientFor(mixed ...$queue): GuzzleHttpClient {
    $mock = new MockHandler($queue);
    $stack = HandlerStack::create($mock);
    // Capture the effective options so the policy assertions below are made
    // against what Guzzle would really have been given.
    $stack->push(function (callable $handler) {
      return function ($request, array $options) use ($handler) {
        $this->captured[] = $options;
        $this->sent[] = $request;

        return $handler($request, $options);
      };
    });

    return new GuzzleHttpClient(new Client(['handler' => $stack]));
  }

  public function testSuccessfulResponseIsReturnedWithBodyAndHeaders(): void {
    $client = $this->clientFor(
      new Response(200, ['Content-Type' => 'application/json'], '{"tag":"payRequest"}')
    );

    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertTrue($response->ok());
    $this->assertSame(200, $response->status);
    $this->assertSame(['tag' => 'payRequest'], $response->json());
    $this->assertSame('application/json', $response->headers['content-type']);
    $this->assertFalse($response->truncated);
  }

  /**
   * The single most important behavioural fix in this class: a non-2xx must
   * come back as data so callers can tell "not found" from "unreachable".
   *
   * @dataProvider errorStatuses
   */
  public function testNonSuccessStatusesAreDataRatherThanExceptions(int $status): void {
    $client = $this->clientFor(new Response($status, [], '{"status":"ERROR"}'));

    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertFalse(
      $response->failed(),
      'a completed request is not a transport failure'
    );
    $this->assertFalse($response->ok());
    $this->assertSame($status, $response->status);
    $this->assertSame(['status' => 'ERROR'], $response->json());
  }

  /** @return array<string,array{int}> */
  public static function errorStatuses(): array {
    return [
      '400' => [400],
      '404' => [404],
      '429' => [429],
      '500' => [500],
      '503' => [503],
    ];
  }

  public function testRedirectIsReturnedRatherThanFollowed(): void {
    $client = $this->clientFor(
      new Response(302, ['Location' => 'https://evil.test/'], '')
    );

    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertSame(302, $response->status);
    $this->assertFalse($response->ok());
    $this->assertFalse($this->captured[0][RequestOptions::ALLOW_REDIRECTS]);
  }

  public function testConnectionFailureBecomesATransportError(): void {
    $client = $this->clientFor(
      new ConnectException(
        'cURL error 28: timeout',
        new Request('GET', 'https://blink.sv/x')
      )
    );

    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertTrue($response->failed());
    $this->assertSame(0, $response->status);
    $this->assertStringContainsString('timeout', (string) $response->transportError);
    $this->assertNull($response->json());
  }

  public function testUnexpectedThrowableAlsoBecomesATransportError(): void {
    $client = $this->clientFor(new \RuntimeException('something exotic'));

    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertTrue($response->failed());
    $this->assertStringContainsString(
      'something exotic',
      (string) $response->transportError
    );
  }

  public function testOversizedBodyIsCappedAndFlagged(): void {
    $client = $this->clientFor(new Response(200, [], str_repeat('a', 200000)));

    $response = $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(maxBytes: 1024)
    );

    $this->assertTrue($response->truncated);
    $this->assertSame(1024, strlen($response->body));
  }

  public function testBodyExactlyAtTheCapIsNotTruncated(): void {
    $client = $this->clientFor(new Response(200, [], str_repeat('a', 1024)));

    $response = $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(maxBytes: 1024)
    );

    $this->assertFalse(
      $response->truncated,
      'a complete body that happens to be exactly the cap was not cut'
    );
    $this->assertSame(1024, strlen($response->body));
  }

  public function testBodyAtTheCapWithBytesStillPendingIsTruncated(): void {
    $client = $this->clientFor(new Response(200, [], str_repeat('a', 1025)));

    $response = $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(maxBytes: 1024)
    );

    $this->assertTrue($response->truncated, 'one byte beyond the cap is still a cut body');
    $this->assertSame(1024, strlen($response->body));
  }

  public function testBodyUnderTheCapIsNotFlagged(): void {
    $client = $this->clientFor(new Response(200, [], str_repeat('a', 100)));

    $response = $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(maxBytes: 1024)
    );

    $this->assertFalse($response->truncated);
    $this->assertSame(100, strlen($response->body));
  }

  public function testStreamThatStopsYieldingEndsTheRead(): void {
    // A stream that never reaches eof but returns nothing must not spin.
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('eof')->willReturn(false);
    $stream->method('read')->willReturn('');

    $client = $this->clientFor(new Response(200, [], $stream));
    $response = $client->get('https://blink.sv/x', new HttpRequestOptions(maxBytes: 32));

    $this->assertSame('', $response->body);
    $this->assertFalse($response->truncated);
  }

  public function testUnreadableBodyBecomesATransportError(): void {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('eof')->willReturn(false);
    $stream->method('read')->willThrowException(new \RuntimeException('stream died'));

    $client = $this->clientFor(new Response(200, [], $stream));
    $response = $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertTrue($response->failed());
    $this->assertStringContainsString('stream died', (string) $response->transportError);
  }

  public function testTimeoutsAndVerificationArePassedThrough(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(connectTimeout: 2.5, timeout: 7.5)
    );

    $this->assertSame(2.5, $this->captured[0][RequestOptions::CONNECT_TIMEOUT]);
    $this->assertSame(7.5, $this->captured[0][RequestOptions::TIMEOUT]);
    $this->assertTrue($this->captured[0][RequestOptions::VERIFY]);
    $this->assertFalse($this->captured[0][RequestOptions::HTTP_ERRORS]);
  }

  public function testHttpsOnlyByDefaultAndPlainHttpOnlyOnRequest(): void {
    $client = $this->clientFor(new Response(200, [], '{}'), new Response(200, [], '{}'));

    $client->get('https://blink.sv/x', new HttpRequestOptions());
    $this->assertSame(CURLPROTO_HTTPS, $this->captured[0]['curl'][CURLOPT_PROTOCOLS]);

    $client->get(
      'http://localhost:8889/x',
      (new HttpRequestOptions())->withPlainHttpAllowed(true)
    );
    $this->assertSame(
      CURLPROTO_HTTPS | CURLPROTO_HTTP,
      $this->captured[1]['curl'][CURLOPT_PROTOCOLS]
    );
  }

  public function testRedirectProtocolsAreDisabledEntirely(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertSame(0, $this->captured[0]['curl'][CURLOPT_REDIR_PROTOCOLS]);
  }

  public function testDnsPinsAreTranslatedIntoCurlResolveEntries(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get(
      'https://blink.sv/x',
      (new HttpRequestOptions())->withDnsPins([
        'blink.sv:443' => ['93.184.216.34', '2606:2800::1'],
      ])
    );

    $this->assertSame(
      ['blink.sv:443:93.184.216.34,2606:2800::1'],
      $this->captured[0]['curl'][CURLOPT_RESOLVE]
    );
  }

  public function testEmptyPinListIsSkippedRatherThanSentAsGarbage(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get(
      'https://blink.sv/x',
      (new HttpRequestOptions())->withDnsPins(['blink.sv:443' => []])
    );

    $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->captured[0]['curl']);
  }

  public function testNoPinsMeansNoResolveOption(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get('https://blink.sv/x', new HttpRequestOptions());

    $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->captured[0]['curl']);
  }

  public function testHeadersReachTheOutboundRequest(): void {
    $client = $this->clientFor(new Response(200, [], '{}'));

    $client->get(
      'https://blink.sv/x',
      new HttpRequestOptions(headers: ['Accept' => 'text/plain'])
    );

    // Guzzle folds the headers option into the request itself, so assert there
    // rather than on the effective options.
    $this->assertSame('text/plain', $this->sent[0]->getHeaderLine('Accept'));
  }
}
