<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * A validated Lightning address (LUD-16), e.g. "shop@blink.sv".
 *
 * Replaces the address handling that was spread across the old LNURL client
 * (parseLnAddress / lnAddressDomain / scheme / isLocalDevHost). Keeping it in
 * one immutable value object means the host used for the SSRF check, the host
 * used to build the well-known URL and the host stored on the order can never
 * drift apart.
 */
final class LnAddress {
  /**
   * Hosts that may be reached over plain HTTP, for local development only.
   * Everything else is HTTPS-only.
   */
  private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

  /** Reserved TLDs that only ever resolve locally. */
  private const LOCAL_TLDS = ['localhost', 'test', 'local'];

  private function __construct(
    public readonly string $identifier,
    public readonly string $host,
    public readonly ?int $port
  ) {}

  /**
   * Parses "identifier@host[:port]", or returns null when the input is not a
   * usable Lightning address.
   *
   * Stricter than the previous regex, which accepted consecutive dots, leading
   * and trailing dots, and labels of unbounded length.
   */
  public static function parse(string $raw): ?self {
    $raw = trim($raw);
    if ($raw === '' || substr_count($raw, '@') !== 1) {
      return null;
    }

    [$identifier, $domain] = explode('@', $raw, 2);
    if ($identifier === '' || $domain === '') {
      return null;
    }

    // LUD-16 limits the identifier to a-z0-9-_. (case-insensitive).
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $identifier)) {
      return null;
    }

    $domain = strtolower($domain);
    $port = null;

    // Bracketed IPv6 literal, e.g. [::1]:8080.
    if (str_starts_with($domain, '[')) {
      $close = strpos($domain, ']');
      if ($close === false) {
        return null;
      }
      $host = substr($domain, 0, $close + 1);
      $rest = substr($domain, $close + 1);
      if ($rest !== '') {
        $port = self::parsePort(ltrim($rest, ':'));
        if ($port === null) {
          return null;
        }
      }
      if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return null;
      }
      return new self($identifier, $host, $port);
    }

    if (substr_count($domain, ':') === 1) {
      [$domain, $rawPort] = explode(':', $domain, 2);
      $port = self::parsePort($rawPort);
      if ($port === null) {
        return null;
      }
    } elseif (str_contains($domain, ':')) {
      // Bare IPv6 without brackets is ambiguous with a port; reject it.
      return null;
    }

    if (!self::isValidHost($domain)) {
      return null;
    }

    return new self($identifier, $domain, $port);
  }

  private static function parsePort(string $raw): ?int {
    if (!preg_match('/^\d{1,5}$/', $raw)) {
      return null;
    }
    $port = (int) $raw;

    return $port >= 1 && $port <= 65535 ? $port : null;
  }

  private static function isValidHost(string $host): bool {
    if ($host === '' || strlen($host) > 253) {
      return false;
    }
    // An IPv4 literal is a valid host (used by local dev setups).
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
      return true;
    }
    if (str_starts_with($host, '.') || str_ends_with($host, '.')) {
      return false;
    }
    if (str_contains($host, '..')) {
      return false;
    }
    foreach (explode('.', $host) as $label) {
      if ($label === '' || strlen($label) > 63) {
        return false;
      }
      if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
        return false;
      }
      if (!preg_match('/^[a-z0-9-]+$/', $label)) {
        return false;
      }
    }

    return true;
  }

  /** Host with its port, as written in a URL authority. */
  public function domain(): string {
    return $this->port === null ? $this->host : $this->host . ':' . $this->port;
  }

  /**
   * Whether this address points at the machine running the shop, in which case
   * plain HTTP is tolerated so a developer can run an LNURL server locally.
   */
  public function isLocalDev(): bool {
    return self::hostIsLocalDev($this->host);
  }

  public static function hostIsLocalDev(string $host): bool {
    $host = strtolower($host);
    if (in_array($host, self::LOCAL_HOSTS, true)) {
      return true;
    }
    foreach (self::LOCAL_TLDS as $tld) {
      if (str_ends_with($host, '.' . $tld)) {
        return true;
      }
    }

    return false;
  }

  public function scheme(): string {
    return $this->isLocalDev() ? 'http' : 'https';
  }

  /** The LUD-16 well-known endpoint for this address. */
  public function wellKnownUrl(): string {
    return $this->scheme() .
      '://' .
      $this->domain() .
      '/.well-known/lnurlp/' .
      rawurlencode($this->identifier);
  }

  public function __toString(): string {
    return $this->identifier . '@' . $this->domain();
  }
}
