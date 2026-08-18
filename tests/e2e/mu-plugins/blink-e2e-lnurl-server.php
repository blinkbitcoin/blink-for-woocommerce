<?php
/**
 * Plugin Name: Blink E2E LNURL server
 * Description: A fake LNURL-pay / LUD-21 server for end-to-end tests. Never shipped.
 *
 * Lives inside the test site rather than beside it, which is the whole trick:
 * wp-env serves it on http://localhost:8889, a host the plugin classifies as
 * local development. A server running on the host machine would only be
 * reachable as host.docker.internal -- neither local nor same-domain -- so the
 * plugin's own SSRF policy would reject every request and the harness would
 * only work by weakening production code.
 *
 * The scenario is chosen by the identifier part of the lightning address, so
 * specs are self-describing and cannot race on shared state.
 */

declare(strict_types=1);

defined('ABSPATH') || exit();

final class Blink_E2E_Lnurl_Server {
  private const OPTION_PREFIX = 'blink_e2e_';

  public static function boot(): void {
    add_action('init', [self::class, 'route'], 0);
  }

  public static function route(): void {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($uri, PHP_URL_PATH);

    if (preg_match('#^/\.well-known/lnurlp/([^/]+)$#', $path, $m)) {
      self::payMetadata(urldecode($m[1]));
    }
    if ($path === '/blink-e2e/callback') {
      self::callback();
    }
    if (preg_match('#^/blink-e2e/verify/([a-f0-9]{64})$#i', $path, $m)) {
      self::verify(strtolower($m[1]));
    }
    if ($path === '/blink-e2e/control/settle') {
      self::control('settled');
    }
    if ($path === '/blink-e2e/control/fail') {
      self::control('failing');
    }
    if ($path === '/blink-e2e/control/reset') {
      self::reset();
    }
    if ($path === '/blink-e2e/control/requests') {
      self::requestLog();
    }
  }

  // ------------------------------------------------------------- endpoints

  private static function payMetadata(string $identifier): void {
    self::recordRequest('well-known:' . $identifier);

    switch ($identifier) {
      case 'error-body':
        self::json(['status' => 'ERROR', 'reason' => 'Wallet unavailable']);
      case 'not-payrequest':
        self::json(['tag' => 'withdrawRequest']);
      case 'http-404':
        status_header(404);
        self::json(['status' => 'ERROR', 'reason' => 'Not found']);
      case 'http-500':
        status_header(500);
        self::send('Internal Server Error');
      case 'timeout':
        sleep(25);
        self::json(['tag' => 'payRequest']);
      case 'malformed':
        self::send('{"callback":', 'application/json');
      case 'redirect':
        wp_redirect(home_url('/blink-e2e/callback'), 302);
        exit();
      case 'oversized':
        self::json(['tag' => 'payRequest', 'padding' => str_repeat('x', 12 * 1024 * 1024)]);
      case 'below-min':
        self::json(self::metadata($identifier, ['minSendable' => 900000000000]));
      case 'above-max':
        self::json(self::metadata($identifier, ['maxSendable' => 1000]));
      case 'no-comment':
        self::json(self::metadata($identifier, ['commentAllowed' => 0]));
      default:
        self::json(self::metadata($identifier));
    }
  }

  /** @param array<string,mixed> $overrides @return array<string,mixed> */
  private static function metadata(string $identifier, array $overrides = []): array {
    return array_merge(
      [
        'tag' => 'payRequest',
        'callback' => home_url('/blink-e2e/callback?id=' . rawurlencode($identifier)),
        'minSendable' => 1000,
        'maxSendable' => 100000000000,
        'commentAllowed' => 255,
        'metadata' => self::metadataString($identifier),
      ],
      $overrides
    );
  }

  private static function metadataString(string $identifier): string {
    return wp_json_encode([['text/plain', 'Blink E2E payment to ' . $identifier]]);
  }

  private static function callback(): void {
    $identifier = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : 'ok';
    $amountMsat = isset($_GET['amount']) ? (int) $_GET['amount'] : 0;
    self::recordRequest('callback:' . $identifier);

    if ($amountMsat <= 0) {
      self::json(['status' => 'ERROR', 'reason' => 'Missing amount']);
    }

    $paymentHash = self::hashFor($identifier, $amountMsat);

    switch ($identifier) {
      case 'insecure-callback':
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash),
          'verify' => 'http://example.com/verify/' . $paymentHash,
        ]);
      case 'foreign-verify':
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash),
          'verify' => 'https://attacker.example/verify/' . $paymentHash,
        ]);
      case 'wrong-amount':
        self::json([
          'pr' => self::invoice($identifier, (int) ($amountMsat / 100), $paymentHash),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash),
        ]);
      case 'short-expiry':
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash, 30),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash),
        ]);
      default:
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash),
        ]);
    }
  }

  private static function verify(string $paymentHash): void {
    self::recordRequest('verify:' . $paymentHash);

    $mode = (string) get_option(self::OPTION_PREFIX . 'fail_' . $paymentHash, '');
    if ($mode === 'http-500') {
      status_header(500);
      self::send('Internal Server Error');
    }
    if ($mode === 'not-found') {
      status_header(404);
      self::json(['status' => 'ERROR', 'reason' => 'Not found']);
    }
    if ($mode === 'timeout') {
      sleep(25);
    }

    $settled = get_option(self::OPTION_PREFIX . 'settled_' . $paymentHash) === 'yes';

    self::json([
      'status' => 'OK',
      'settled' => $settled,
      'preimage' => $settled ? (string) get_option(self::OPTION_PREFIX . 'preimage_' . $paymentHash) : null,
      'pr' => null,
    ]);
  }

  // --------------------------------------------------------------- control

  private static function control(string $what): void {
    $hash = isset($_REQUEST['hash']) ? strtolower(sanitize_text_field(wp_unslash($_REQUEST['hash']))) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      status_header(400);
      self::json(['error' => 'a 64 character hex hash is required']);
    }

    if ($what === 'settled') {
      update_option(self::OPTION_PREFIX . 'settled_' . $hash, 'yes');
      self::json(['ok' => true, 'settled' => $hash]);
    }

    $mode = isset($_REQUEST['mode']) ? sanitize_text_field(wp_unslash($_REQUEST['mode'])) : 'http-500';
    update_option(self::OPTION_PREFIX . 'fail_' . $hash, $mode);
    self::json(['ok' => true, 'failing' => $hash, 'mode' => $mode]);
  }

  private static function reset(): void {
    global $wpdb;
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like(self::OPTION_PREFIX) . '%'
      )
    );
    wp_cache_flush();
    self::json(['ok' => true]);
  }

  private static function requestLog(): void {
    self::json(['requests' => get_option(self::OPTION_PREFIX . 'log', [])]);
  }

  private static function recordRequest(string $what): void {
    $log = get_option(self::OPTION_PREFIX . 'log', []);
    if (!is_array($log)) {
      $log = [];
    }
    $log[] = ['at' => microtime(true), 'what' => $what];
    update_option(self::OPTION_PREFIX . 'log', array_slice($log, -200));
  }

  // --------------------------------------------------------------- invoice

  /**
   * A payment hash that is stable for an identifier and amount, so a spec can
   * predict it without scraping the page.
   */
  private static function hashFor(string $identifier, int $amountMsat): string {
    $preimage = hash('sha256', $identifier . ':' . $amountMsat . ':blink-e2e');
    $hash = hash('sha256', (string) hex2bin($preimage));
    update_option(self::OPTION_PREFIX . 'preimage_' . $hash, $preimage);

    return $hash;
  }

  /**
   * Builds a BOLT11 invoice with a filler signature.
   *
   * The plugin deliberately does not verify signatures (it checks binding
   * instead), which is what makes a fake server possible at all.
   */
  private static function invoice(
    string $identifier,
    int $amountMsat,
    string $paymentHash,
    int $expirySeconds = 3600
  ): string {
    require_once __DIR__ . '/blink-e2e-bolt11.php';

    return Blink_E2E_Bolt11::encode(
      $amountMsat,
      $paymentHash,
      hash('sha256', self::metadataString($identifier)),
      $expirySeconds
    );
  }

  // ------------------------------------------------------------------ send

  /** @param array<string,mixed> $payload */
  private static function json(array $payload): void {
    self::send((string) wp_json_encode($payload), 'application/json');
  }

  private static function send(string $body, string $contentType = 'text/plain'): void {
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store');
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test fixture emitting a raw protocol response.
    exit();
  }
}

Blink_E2E_Lnurl_Server::boot();
