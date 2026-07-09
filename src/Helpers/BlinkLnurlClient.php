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
    if ($host === 'localhost' || $host === '127.0.0.1') {
      return 'http';
    }
    return 'https';
  }

  private static function httpGet(string $url): ?array {
    try {
      $client = new \GuzzleHttp\Client(['timeout' => 20]);
      $response = $client->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
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
   * @param string $callback   LNURL-pay callback URL (from fetchPayMetadata).
   * @param int    $amountMsat Amount in millisatoshis (must be a whole-sat multiple of 1000).
   * @param string $comment    Optional LUD-12 comment (order reference), truncated to 255 chars.
   *
   * @return array{paymentRequest:string,verifyUrl:string,paymentHash:string}|null
   */
  public static function requestInvoice(
    string $callback,
    int $amountMsat,
    string $comment = ''
  ): ?array {
    if ($amountMsat <= 0 || $amountMsat % 1000 !== 0) {
      Logger::debug('Invalid msat amount for LNURL invoice: ' . $amountMsat);
      return null;
    }

    $query = ['amount' => $amountMsat];
    if ($comment !== '') {
      $query['comment'] = substr($comment, 0, self::MAX_COMMENT_LENGTH);
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
  public static function checkVerify(string $verifyUrl): array {
    $result = [
      'settled' => false,
      'preimage' => null,
      'pr' => null,
      'notFound' => false,
      'error' => false,
    ];

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
