<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Http\HttpClientInterface;
use Blink\WC\Http\HttpRequestOptions;
use Blink\WC\Http\HttpResponse;

/**
 * Scripted HTTP client with a call log.
 *
 * The onRequest hook is what makes single-flight testable without real
 * parallelism: it fires while a request is notionally in flight and the lock
 * is held, so a test can re-enter the code under test at exactly the moment a
 * second browser tab would have arrived.
 */
final class FakeHttpClient implements HttpClientInterface {
  /** @var list<HttpResponse> */
  private array $script = [];

  /** @var list<array{url:string,options:HttpRequestOptions}> */
  public array $requests = [];

  /** @var callable|null */
  private $onRequest = null;

  private ?HttpResponse $default = null;

  public function queue(HttpResponse ...$responses): self {
    foreach ($responses as $response) {
      $this->script[] = $response;
    }

    return $this;
  }

  public function queueJson(array $payload, int $status = 200): self {
    return $this->queue(new HttpResponse($status, (string) json_encode($payload)));
  }

  /** Response used once the script runs out; without one, exhaustion throws. */
  public function alwaysRespond(HttpResponse $response): self {
    $this->default = $response;

    return $this;
  }

  /** @param callable():void $callback */
  public function onRequest(callable $callback): self {
    $this->onRequest = $callback;

    return $this;
  }

  public function get(string $url, HttpRequestOptions $options): HttpResponse {
    $this->requests[] = ['url' => $url, 'options' => $options];

    if ($this->onRequest !== null) {
      $hook = $this->onRequest;
      // One-shot: the hook must not recurse into itself forever.
      $this->onRequest = null;
      $hook();
    }

    if ($this->script !== []) {
      return array_shift($this->script);
    }

    if ($this->default !== null) {
      return $this->default;
    }

    throw new \LogicException(
      sprintf('FakeHttpClient had no scripted response for request #%d to %s', count($this->requests), $url)
    );
  }

  public function requestCount(): int {
    return count($this->requests);
  }

  public function lastUrl(): ?string {
    $last = end($this->requests);

    return $last === false ? null : $last['url'];
  }

  /** @return list<string> */
  public function urls(): array {
    return array_map(static fn(array $r): string => $r['url'], $this->requests);
  }

  public function lastOptions(): ?HttpRequestOptions {
    $last = end($this->requests);

    return $last === false ? null : $last['options'];
  }
}
