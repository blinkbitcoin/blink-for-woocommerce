<?php

declare(strict_types=1);

namespace Blink\WC\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Guzzle-backed HTTP client.
 *
 * Every option set here exists because of a specific failure mode:
 *
 * - http_errors=false, because a non-2xx is data. Guzzle's default turns a 404
 *   into an exception, which is why the previous client could never tell
 *   "invoice not found" from "network down".
 * - allow_redirects=false, because a redirect is resolved after the URL policy
 *   has approved the original host, and would let a compliant first hop bounce
 *   the request to an internal address.
 * - separate connect and total timeouts, so a blackholed host fails fast
 *   instead of holding a checkout worker for the full budget.
 * - streamed reads with a byte cap, so a hostile or broken server cannot
 *   exhaust PHP's memory limit with an unbounded body.
 * - CURLOPT_RESOLVE pins, so the IPs the policy validated are the IPs actually
 *   connected to. Without this the DNS check is advisory: an attacker's
 *   resolver can answer with a public address for the check and a private one
 *   for the request.
 */
final class GuzzleHttpClient implements HttpClientInterface {
  private bool $canPin;

  /**
   * @param bool|null $canPin whether the transport can honour DNS pins.
   *   Guzzle chooses its handler through Utils::chooseHandler(), which picks
   *   curl exactly when the curl functions exist, so that is what decides it.
   *   Injectable because both arms have to be testable on any PHP build.
   */
  public function __construct(private ClientInterface $guzzle, ?bool $canPin = null) {
    $this->canPin = $canPin ?? function_exists('curl_init');
  }

  public function get(string $url, HttpRequestOptions $options): HttpResponse {
    // Without the curl handler the 'curl' options below are silently dropped,
    // taking the DNS pins with them -- and an unpinned request is exactly the
    // DNS-rebinding hole the pins exist to close. Refuse rather than send it.
    // A transport failure is the right shape: settlement already treats an
    // unreachable verify as "unknown", so this cannot expire a paid order.
    if ($this->resolveEntries($options) !== [] && !$this->canPin) {
      return HttpResponse::transportFailure(
        'DNS pinning is unavailable without the cURL extension'
      );
    }

    try {
      $response = $this->guzzle->request('GET', $url, $this->guzzleOptions($options));
    } catch (\Throwable $e) {
      return HttpResponse::transportFailure($e->getMessage());
    }

    try {
      [$body, $truncated] = $this->readCapped($response->getBody(), $options->maxBytes);
    } catch (\Throwable $e) {
      return HttpResponse::transportFailure(
        'could not read response body: ' . $e->getMessage()
      );
    }

    return new HttpResponse(
      $response->getStatusCode(),
      $body,
      $this->flattenHeaders($response->getHeaders()),
      null,
      $truncated
    );
  }

  /** @return array<string,mixed> */
  private function guzzleOptions(HttpRequestOptions $options): array {
    $curl = [
      CURLOPT_PROTOCOLS => $options->allowPlainHttp
        ? CURLPROTO_HTTPS | CURLPROTO_HTTP
        : CURLPROTO_HTTPS,
      CURLOPT_REDIR_PROTOCOLS => 0,
    ];

    $resolve = $this->resolveEntries($options);
    if ($resolve !== []) {
      $curl[CURLOPT_RESOLVE] = $resolve;
    }

    return [
      RequestOptions::HEADERS => $options->headers,
      RequestOptions::HTTP_ERRORS => false,
      RequestOptions::ALLOW_REDIRECTS => false,
      RequestOptions::CONNECT_TIMEOUT => $options->connectTimeout,
      RequestOptions::TIMEOUT => $options->timeout,
      RequestOptions::VERIFY => true,
      RequestOptions::STREAM => true,
      'curl' => $curl,
    ];
  }

  /**
   * The CURLOPT_RESOLVE entries this request needs.
   *
   * A host mapped to an empty IP list is not a pin, which is why this is what
   * both the fail-closed guard and the curl options are decided from: the
   * local-development branch of UrlPolicy legitimately produces no pins, and
   * must keep working without curl.
   *
   * @return list<string>
   */
  private function resolveEntries(HttpRequestOptions $options): array {
    $resolve = [];
    foreach ($options->dnsPins as $hostPort => $ips) {
      if ($ips === []) {
        continue;
      }
      $resolve[] = $hostPort . ':' . implode(',', $ips);
    }

    return $resolve;
  }

  /**
   * Reads at most $maxBytes from the stream.
   *
   * @return array{string,bool} the body, and whether the cap was hit.
   */
  private function readCapped(
    \Psr\Http\Message\StreamInterface $stream,
    int $maxBytes
  ): array {
    $body = '';
    while (!$stream->eof()) {
      $remaining = $maxBytes - strlen($body);
      if ($remaining <= 0) {
        // The cap is reached, but that alone does not mean the body was cut:
        // a response of exactly maxBytes is complete. eof() cannot answer
        // this, because it reports a read that hit the end rather than
        // arrival at it, so ask for one more byte. Anything there means the
        // body really is truncated and may be cut mid-token.
        return [$body, $stream->read(1) !== ''];
      }
      $chunk = $stream->read(min(8192, $remaining));
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }

    // Reaching here means the stream ended on its own, so whatever was read is
    // the whole body.
    return [$body, false];
  }

  /**
   * @param array<string,list<string>> $headers
   * @return array<string,string>
   */
  private function flattenHeaders(array $headers): array {
    $flat = [];
    foreach ($headers as $name => $values) {
      $flat[strtolower($name)] = implode(', ', $values);
    }

    return $flat;
  }
}
