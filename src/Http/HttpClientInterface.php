<?php

declare(strict_types=1);

namespace Blink\WC\Http;

/**
 * Performs one outbound GET under a strict policy, and never throws.
 *
 * Injected everywhere rather than constructing Guzzle inline, so tests can
 * drive every branch -- timeouts, 5xx, malformed bodies, oversized bodies --
 * without a network.
 */
interface HttpClientInterface {
  public function get(string $url, HttpRequestOptions $options): HttpResponse;
}
