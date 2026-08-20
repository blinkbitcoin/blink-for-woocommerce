<?php

declare(strict_types=1);

namespace Blink\WC\Http;

/**
 * Per-request policy for an outbound call.
 *
 * The defaults are deliberately strict: these requests are made to a host the
 * shop does not control, during a customer's checkout, from a PHP worker that
 * is blocked while they run.
 */
final class HttpRequestOptions {
  /**
   * @param array<string,string> $headers
   * @param array<string,list<string>> $dnsPins host => IPs the request is pinned to.
   */
  public function __construct(
    public readonly float $connectTimeout = 5.0,
    public readonly float $timeout = 10.0,
    public readonly int $maxBytes = 65536,
    public readonly array $headers = ['Accept' => 'application/json'],
    public readonly array $dnsPins = [],
    public readonly bool $allowPlainHttp = false
  ) {}

  /** @param array<string,list<string>> $pins */
  public function withDnsPins(array $pins): self {
    return new self(
      $this->connectTimeout,
      $this->timeout,
      $this->maxBytes,
      $this->headers,
      $pins,
      $this->allowPlainHttp
    );
  }

  public function withPlainHttpAllowed(bool $allowed): self {
    return new self(
      $this->connectTimeout,
      $this->timeout,
      $this->maxBytes,
      $this->headers,
      $this->dnsPins,
      $allowed
    );
  }
}
