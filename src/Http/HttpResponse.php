<?php

declare(strict_types=1);

namespace Blink\WC\Http;

/**
 * The outcome of one outbound request.
 *
 * Transport failures are values, not exceptions: every caller in the
 * non-custodial path has to distinguish "the server said no" from "we could
 * not reach the server", and an exception makes that distinction easy to lose.
 */
final class HttpResponse {
  /**
   * @param int $status HTTP status code, or 0 when the request never completed.
   * @param array<string,string> $headers
   */
  public function __construct(
    public readonly int $status,
    public readonly string $body,
    public readonly array $headers = [],
    public readonly ?string $transportError = null,
    public readonly bool $truncated = false
  ) {}

  public static function transportFailure(string $reason): self {
    return new self(0, '', [], $reason);
  }

  public function failed(): bool {
    return $this->transportError !== null;
  }

  public function ok(): bool {
    return !$this->failed() && $this->status >= 200 && $this->status < 300;
  }

  /**
   * Decodes the body as a JSON object or array.
   *
   * Returns null for anything else, including a bare JSON scalar: every LNURL
   * response this plugin consumes is an object, and accepting `true` or `"ok"`
   * here would push the type confusion downstream.
   *
   * @return array<mixed>|null
   */
  public function json(): ?array {
    if ($this->body === '') {
      return null;
    }

    $decoded = json_decode($this->body, true);

    return is_array($decoded) ? $decoded : null;
  }
}
