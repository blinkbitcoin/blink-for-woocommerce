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
  public function __construct(private ClientInterface $guzzle) {}

  public function get(string $url, HttpRequestOptions $options): HttpResponse {
    try {
      $response = $this->guzzle->request('GET', $url, $this->guzzleOptions($options));
    } catch (\Throwable $e) {
      return HttpResponse::transportFailure($e->getMessage());
    }

    try {
      [$body, $truncated] = $this->readCapped($response->getBody(), $options->maxBytes);
    } catch (\Throwable $e) {
      return HttpResponse::transportFailure('could not read response body: ' . $e->getMessage());
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

    $resolve = [];
    foreach ($options->dnsPins as $hostPort => $ips) {
      if ($ips === []) {
        continue;
      }
      $resolve[] = $hostPort . ':' . implode(',', $ips);
    }
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
   * Reads at most $maxBytes from the stream.
   *
   * @return array{string,bool} the body, and whether the cap was hit.
   */
  private function readCapped(\Psr\Http\Message\StreamInterface $stream, int $maxBytes): array {
    $body = '';
    while (!$stream->eof()) {
      $remaining = $maxBytes - strlen($body);
      if ($remaining <= 0) {
        return [$body, true];
      }
      $chunk = $stream->read(min(8192, $remaining));
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }

    // A body of exactly maxBytes is indistinguishable from a truncated one, so
    // treat it as truncated rather than hand a caller a body that may be cut
    // mid-token.
    return [$body, strlen($body) >= $maxBytes];
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
