<?php

declare(strict_types=1);

namespace Blink\WC\Helpers;

/**
 * Minimal client for the public LNURL-pay (LUD-06) + LUD-21 verify flow used by
 * non-custodial (self-custodial / Spark) Blink accounts.
 *
 * Unlike the custodial GraphQL API, this flow requires no API key: a merchant is
 * identified purely by a lightning address (identifier@domain). Invoices are created
 * through the LNURL-pay callback and settlement is detected by polling the LUD-21
 * verify endpoint until `settled` is true.
 *
 * The verify endpoint is served from the LNURL callback origin (e.g. lnurl.blink.sv),
 * which is NOT necessarily the lightning address domain (e.g. blink.sv). This client
 * always derives the verify URL from the callback response, never by reconstructing it
 * from the bare address domain.
 */
class BlinkLnurlClient {
  /** Maximum LUD-12 comment length advertised by blink-lnurl-server. */
  const MAX_COMMENT_LENGTH = 255;

  /**
   * Splits a lightning address into [identifier, domain].
   *
   * @return array{0:string,1:string}|null Null if the address is malformed.
   */
  public static function parseLnAddress(string $lnAddress): ?array {
    $lnAddress = trim($lnAddress);
    if (!str_contains($lnAddress, '@')) {
      return null;
    }

    [$identifier, $domain] = explode('@', $lnAddress, 2);
    $identifier = trim($identifier);
    $domain = trim(strtolower($domain));

    if ($identifier === '' || $domain === '') {
      return null;
    }

    // Basic sanity: domain must look like a hostname (optionally with port).
    if (!preg_match('/^[a-z0-9.\-]+(?::\d+)?$/', $domain)) {
      return null;
    }

    return [$identifier, $domain];
  }

  private static function scheme(string $domain): string {
    // Allow plain HTTP only for local development hosts.
    $host = explode(':', $domain, 2)[0];
    if (self::isLocalDevHost($host)) {
      return 'http';
    }
    return 'https';
  }

  private static function isLocalDevHost(string $host): bool {
    $host = strtolower($host);
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]';
  }

  /**
   * Returns the registrable base of a host as its last two labels
   * (e.g. "lnurl.blink.sv" and "blink.sv" both -> "blink.sv"). This is a
   * pragmatic check (not a full public-suffix list) sufficient to ensure a
   * callback/verify URL stays within the same organisation's domain as the
   * configured lightning address.
   */
  private static function registrableBase(string $host): string {
    $host = strtolower(rtrim($host, '.'));
    $labels = explode('.', $host);
    if (count($labels) <= 2) {
      return $host;
    }
    return implode('.', array_slice($labels, -2));
  }

  /**
   * SSRF guard. A URL returned by an (untrusted) LNURL server is only allowed if:
   *  - it is a well-formed http(s) URL, and
   *  - its host shares the same registrable domain as the lightning-address
   *    domain (so blink.sv may legitimately delegate to lnurl.blink.sv), and
   *  - it does not resolve to a private/loopback/link-local/reserved IP
   *    (blocks cloud metadata endpoints such as 169.254.169.254 and intranet
   *    targets).
   *
   * Local development hosts (localhost/127.0.0.1) are allowed only when the
   * configured address domain is itself a local dev host.
   */
  public static function isUrlAllowed(string $url, string $addressDomain): bool {
    $parts = wp_parse_url($url);
    if (!$parts || empty($parts['host'])) {
      Logger::debug('LNURL URL rejected (could not parse): ' . $url);
      return false;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
      Logger::debug('LNURL URL rejected (bad scheme): ' . $url);
      return false;
    }

    $host = strtolower($parts['host']);
    $addressHost = strtolower(explode(':', $addressDomain, 2)[0]);

    // Local dev exception: only if the configured address is itself local.
    if (self::isLocalDevHost($addressHost)) {
      return self::isLocalDevHost($host);
    }

    // Must stay within the same registrable domain as the address.
    if (self::registrableBase($host) !== self::registrableBase($addressHost)) {
      Logger::debug(
        'LNURL URL rejected (host outside address domain): ' .
          $host .
          ' vs ' .
          $addressHost
      );
      return false;
    }

    // Resolve and reject private/reserved IPs (SSRF to intranet/metadata).
    $ips = self::resolveHost($host);
    if (empty($ips)) {
      Logger::debug('LNURL URL rejected (host does not resolve): ' . $host);
      return false;
    }
    foreach ($ips as $ip) {
      if (!self::isPublicIp($ip)) {
        Logger::debug('LNURL URL rejected (non-public IP ' . $ip . '): ' . $host);
        return false;
      }
    }

    return true;
  }

  private static function resolveHost(string $host): array {
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
      $ips = $v4;
    }
    // Best-effort IPv6 resolution.
    if (function_exists('dns_get_record')) {
      $aaaa = @dns_get_record($host, DNS_AAAA);
      if (is_array($aaaa)) {
        foreach ($aaaa as $rec) {
          if (!empty($rec['ipv6'])) {
            $ips[] = $rec['ipv6'];
          }
        }
      }
    }
    return $ips;
  }

  private static function isPublicIp(string $ip): bool {
    return (bool) filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
  }

  private static function httpGet(string $url): ?array {
    try {
      $client = new \GuzzleHttp\Client(['timeout' => 20]);
      $response = $client->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        // Legitimate LNURL servers do not redirect; disallowing redirects
        // prevents a 3xx from bypassing the host/IP SSRF checks.
        'allow_redirects' => false,
      ]);
      $body = $response->getBody()->getContents();
      $decoded = json_decode($body, true);
      return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $e) {
      Logger::debug('LNURL GET failed for ' . $url . ': ' . $e->getMessage(), true);
      return null;
    }
  }

  /**
   * Fetches the LNURL-pay metadata (LUD-06) for a lightning address.
   *
   * @return array{callback:string,minSendable:int,maxSendable:int,commentAllowed:int}|null
   */
  public static function fetchPayMetadata(string $lnAddress): ?array {
    $parsed = self::parseLnAddress($lnAddress);
    if (!$parsed) {
      Logger::debug('Invalid lightning address: ' . $lnAddress);
      return null;
    }

    [$identifier, $domain] = $parsed;
    $scheme = self::scheme($domain);
    $url = $scheme . '://' . $domain . '/.well-known/lnurlp/' . rawurlencode($identifier);

    $json = self::httpGet($url);
    if (!$json) {
      return null;
    }

    if (($json['tag'] ?? null) !== 'payRequest' || empty($json['callback'])) {
      Logger::debug('Not a valid LNURL-pay endpoint for ' . $lnAddress);
      return null;
    }

    return [
      'callback' => (string) $json['callback'],
      'minSendable' => (int) ($json['minSendable'] ?? 0),
      'maxSendable' => (int) ($json['maxSendable'] ?? 0),
      'commentAllowed' => (int) ($json['commentAllowed'] ?? 0),
    ];
  }

  /**
   * Requests a BOLT11 invoice from the LNURL-pay callback for a fixed amount.
   *
   * @param string $callback       LNURL-pay callback URL (from fetchPayMetadata).
   * @param int    $amountMsat     Amount in millisatoshis (whole-sat multiple of 1000).
   * @param string $addressDomain  The lightning-address domain, for the SSRF host check.
   * @param string $comment        Optional LUD-12 comment (order reference).
   * @param int    $commentAllowed Max comment length advertised by the server (LUD-12);
   *                               0 means the server does not accept comments.
   * @param int    $expiry         Optional requested invoice expiry, in seconds (0 = default).
   *
   * @return array{paymentRequest:string,verifyUrl:string,paymentHash:string}|null
   */
  public static function requestInvoice(
    string $callback,
    int $amountMsat,
    string $addressDomain,
    string $comment = '',
    int $commentAllowed = 0,
    int $expiry = 0
  ): ?array {
    if ($amountMsat <= 0 || $amountMsat % 1000 !== 0) {
      Logger::debug('Invalid msat amount for LNURL invoice: ' . $amountMsat);
      return null;
    }

    // SSRF guard: the callback URL comes from an untrusted LNURL response.
    if (!self::isUrlAllowed($callback, $addressDomain)) {
      Logger::debug('LNURL callback URL not allowed: ' . $callback);
      return null;
    }

    $query = ['amount' => $amountMsat];
    // LUD-12: only send a comment if the server advertises support for it.
    if ($comment !== '' && $commentAllowed > 0) {
      $limit = min($commentAllowed, self::MAX_COMMENT_LENGTH);
      $query['comment'] = substr($comment, 0, $limit);
    }
    if ($expiry > 0) {
      $query['expiry'] = $expiry;
    }

    $separator = str_contains($callback, '?') ? '&' : '?';
    $url = $callback . $separator . http_build_query($query);

    $json = self::httpGet($url);
    if (!$json) {
      return null;
    }

    // LNURL errors are returned with HTTP 200 and status ERROR.
    if (($json['status'] ?? null) === 'ERROR') {
      Logger::debug('LNURL invoice error: ' . ($json['reason'] ?? 'unknown'));
      return null;
    }

    $pr = $json['pr'] ?? null;
    $verifyUrl = $json['verify'] ?? null;
    if (!$pr || !$verifyUrl) {
      Logger::debug('LNURL invoice response missing pr/verify.');
      return null;
    }

    // SSRF guard: the verify URL is persisted and polled repeatedly, so validate
    // it before it is ever stored.
    if (!self::isUrlAllowed((string) $verifyUrl, $addressDomain)) {
      Logger::debug('LNURL verify URL not allowed: ' . $verifyUrl);
      return null;
    }

    $paymentHash = self::paymentHashFromVerifyUrl((string) $verifyUrl);
    if (!$paymentHash) {
      Logger::debug('Could not derive payment hash from verify url: ' . $verifyUrl);
      return null;
    }

    return [
      'paymentRequest' => (string) $pr,
      'verifyUrl' => (string) $verifyUrl,
      'paymentHash' => $paymentHash,
    ];
  }

  /**
   * Extracts the payment hash from a LUD-21 verify URL of the form
   * {origin}/verify/{payment_hash}.
   */
  public static function paymentHashFromVerifyUrl(string $verifyUrl): ?string {
    $path = parse_url($verifyUrl, PHP_URL_PATH);
    if (!$path) {
      return null;
    }
    $segments = explode('/', trim($path, '/'));
    $hash = end($segments);
    if (!$hash || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
      return null;
    }
    return strtolower($hash);
  }

  /**
   * Polls the LUD-21 verify endpoint.
   *
   * Distinguishes three outcomes so callers never falsely settle or falsely expire:
   *  - 'settled'  => bool     Payment confirmed when true.
   *  - 'notFound' => bool     Verify endpoint says the invoice does not exist.
   *  - 'error'    => bool     Transient transport/parse error; caller should keep polling.
   *
   * @return array{settled:bool,preimage:?string,pr:?string,notFound:bool,error:bool}
   */
  public static function checkVerify(
    string $verifyUrl,
    string $addressDomain = ''
  ): array {
    $result = [
      'settled' => false,
      'preimage' => null,
      'pr' => null,
      'notFound' => false,
      'error' => false,
    ];

    // Defense in depth: the verify URL is validated at creation time before being
    // stored, but re-check here since this runs on every (public) poll.
    if ($addressDomain !== '' && !self::isUrlAllowed($verifyUrl, $addressDomain)) {
      Logger::debug('Verify URL not allowed at poll time: ' . $verifyUrl);
      $result['error'] = true;
      return $result;
    }

    $json = self::httpGet($verifyUrl);
    if ($json === null) {
      $result['error'] = true;
      return $result;
    }

    if (($json['status'] ?? null) === 'ERROR') {
      // e.g. {"status":"ERROR","reason":"Not found"}
      $reason = strtolower((string) ($json['reason'] ?? ''));
      $result['notFound'] = str_contains($reason, 'not found');
      $result['error'] = !$result['notFound'];
      return $result;
    }

    $result['settled'] = (bool) ($json['settled'] ?? false);
    $result['preimage'] = isset($json['preimage']) ? (string) $json['preimage'] : null;
    $result['pr'] = isset($json['pr']) ? (string) $json['pr'] : null;

    return $result;
  }
}
