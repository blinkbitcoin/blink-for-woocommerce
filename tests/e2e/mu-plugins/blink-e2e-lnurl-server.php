<?php
/**
 * Plugin Name: Blink E2E LNURL server
 * Description: A fake LNURL-pay / LUD-21 server for end-to-end tests. Never shipped.
 *
 * Lives inside the test site rather than beside it, which is the whole trick:
 * it is served from http://localhost:8889, a host the plugin classifies as
 * local development. A server on a different origin would be neither local nor
 * same-domain, so the plugin's own SSRF policy would reject every request and
 * the harness would only work by weakening production code.
 *
 * The scenario is chosen by the identifier part of the lightning address, so
 * specs are self-describing and cannot race on shared state.
 */

declare(strict_types=1);

defined('ABSPATH') || exit();

final class Blink_E2E_Lnurl_Server {
  private const OPTION_PREFIX = 'blink_e2e_';
  /** WooCommerce has no status constants at the plugin's compatibility floor. */
  private const ORDER_STATUS_PENDING = 'pending';
  private const SETTLEMENT_MODE_FIELD = 'blink_e2e_settlement_mode';
  private const SETTLEMENT_MODE_OPTION = self::OPTION_PREFIX . 'settlement_mode';

  public static function boot(): void {
    add_filter('blink_service_settlementMode', [self::class, 'settlementMode']);
    // Keep the regression deterministic: WP-Cron is disabled in wp-config and
    // this also prevents Action Scheduler's request-shutdown loopback runner.
    // The test's one WP-CLI invocation is therefore the only process allowed
    // to execute queued settlement work.
    add_filter('action_scheduler_allow_async_request_runner', '__return_false');

    // wp_loaded, not init: the control endpoints call wc_get_order() and the
    // real gateway, and at init priority 0 WooCommerce has not yet registered
    // its data stores, so order lookups fail. wp_loaded still runs well before
    // redirect_canonical would turn /.well-known/... into a 301.
    add_action('wp_loaded', [self::class, 'route'], 0);
  }

  /** Selects an orchestration mode without changing production configuration. */
  public static function settlementMode(): \Blink\WC\NonCustodial\SettlementModeProviderInterface {
    $mode =
      \Blink\WC\NonCustodial\SettlementMode::tryFrom(
        (string) get_option(self::SETTLEMENT_MODE_OPTION)
      ) ?? \Blink\WC\NonCustodial\SettlementMode::Hybrid;

    return new \Blink\WC\NonCustodial\FixedSettlementModeProvider($mode);
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
    if ($path === '/blink-e2e/control/order') {
      self::seedOrder();
    }
    if ($path === '/blink-e2e/control/order-state') {
      self::orderState();
    }
    if ($path === '/blink-e2e/control/settings') {
      self::settings();
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
        self::json([
          'tag' => 'payRequest',
          'padding' => str_repeat('x', 12 * 1024 * 1024)
        ]);
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
        'metadata' => self::metadataString($identifier)
      ],
      $overrides
    );
  }

  private static function metadataString(string $identifier): string {
    return wp_json_encode([['text/plain', 'Blink E2E payment to ' . $identifier]]);
  }

  private static function callback(): void {
    $identifier = isset($_GET['id'])
      ? sanitize_text_field(wp_unslash($_GET['id']))
      : 'ok';
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
          'verify' => 'http://example.com/verify/' . $paymentHash
        ]);
      case 'foreign-verify':
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash),
          'verify' => 'https://attacker.example/verify/' . $paymentHash
        ]);
      case 'wrong-amount':
        self::json([
          'pr' => self::invoice($identifier, (int) ($amountMsat / 100), $paymentHash),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash)
        ]);
      case 'short-expiry':
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash, 30),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash)
        ]);
      default:
        self::json([
          'pr' => self::invoice($identifier, $amountMsat, $paymentHash),
          'verify' => home_url('/blink-e2e/verify/' . $paymentHash)
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
      'preimage' => $settled
        ? (string) get_option(self::OPTION_PREFIX . 'preimage_' . $paymentHash)
        : null,
      'pr' => null
    ]);
  }

  // --------------------------------------------------------------- control

  private static function control(string $what): void {
    $hash = isset($_REQUEST['hash'])
      ? strtolower(sanitize_text_field(wp_unslash($_REQUEST['hash'])))
      : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      status_header(400);
      self::json(['error' => 'a 64 character hex hash is required']);
    }

    if ($what === 'settled') {
      update_option(self::OPTION_PREFIX . 'settled_' . $hash, 'yes');
      self::json(['ok' => true, 'settled' => $hash]);
    }

    $mode = isset($_REQUEST['mode'])
      ? sanitize_text_field(wp_unslash($_REQUEST['mode']))
      : 'http-500';
    update_option(self::OPTION_PREFIX . 'fail_' . $hash, $mode);
    self::json(['ok' => true, 'failing' => $hash, 'mode' => $mode]);
  }

  /**
   * Creates an order and takes it through the real gateway.
   *
   * This is the part the previous harness skipped: it created a bare order and
   * never ran process_payment, so no invoice existed, the pay page rendered
   * nothing, and the specs asserting "no invoice" passed for the wrong reason.
   */
  private static function seedOrder(): void {
    $total = isset($_REQUEST['total'])
      ? (float) sanitize_text_field(wp_unslash($_REQUEST['total']))
      : 10.0;

    $order = wc_create_order();
    $order->set_currency('USD');
    $order->set_total((string) $total);
    $order->set_payment_method('blink_default');
    $order->set_status(self::ORDER_STATUS_PENDING);
    $order->save();

    $gateways = WC()->payment_gateways()->payment_gateways();
    $gateway = $gateways['blink_default'] ?? null;
    if ($gateway === null) {
      status_header(500);
      self::json(['error' => 'the Blink gateway is not registered']);
    }

    try {
      $result = $gateway->process_payment($order->get_id());
    } catch (\Throwable $e) {
      self::json([
        'ok' => false,
        'orderId' => $order->get_id(),
        'error' => $e->getMessage()
      ]);
    }

    $order = wc_get_order($order->get_id());

    self::json([
      'ok' => true,
      'orderId' => $order->get_id(),
      'orderKey' => $order->get_order_key(),
      'payUrl' => $order->get_checkout_payment_url(true),
      'paymentHash' => (string) $order->get_meta(
        \Blink\WC\NonCustodial\InvoiceRepository::PAYMENT_HASH
      ),
      'satoshis' => (int) $order->get_meta(
        \Blink\WC\NonCustodial\InvoiceRepository::SATOSHIS
      ),
      'status' => $order->get_status(),
      'redirect' => $result['redirect'] ?? null
    ]);
  }

  private static function orderState(): void {
    $id = isset($_REQUEST['id']) ? absint($_REQUEST['id']) : 0;
    $order = $id ? wc_get_order($id) : null;

    if (!($order instanceof \WC_Order)) {
      status_header(404);
      self::json(['error' => 'no such order']);
    }

    self::json([
      'status' => $order->get_status(),
      'paymentHash' => (string) $order->get_meta(
        \Blink\WC\NonCustodial\InvoiceRepository::PAYMENT_HASH
      ),
      'satoshis' => (int) $order->get_meta(
        \Blink\WC\NonCustodial\InvoiceRepository::SATOSHIS
      ),
      'terminal' => (string) $order->get_meta(
        \Blink\WC\NonCustodial\InvoiceRepository::TERMINAL
      ),
      'notes' => array_map(
        static fn($note): string => trim($note->content),
        wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 20])
      )
    ]);
  }

  private static function settings(): void {
    foreach (
      ['blink_account_type', 'blink_ln_address', 'blink_env', 'blink_debug']
      as $key
    ) {
      if (isset($_REQUEST[$key])) {
        update_option($key, sanitize_text_field(wp_unslash($_REQUEST[$key])));
      }
    }

    if (isset($_REQUEST[self::SETTLEMENT_MODE_FIELD])) {
      update_option(
        self::SETTLEMENT_MODE_OPTION,
        sanitize_text_field(wp_unslash($_REQUEST[self::SETTLEMENT_MODE_FIELD]))
      );
    }

    self::json([
      'ok' => true,
      'blink_account_type' => get_option('blink_account_type'),
      'blink_ln_address' => get_option('blink_ln_address'),
      self::SETTLEMENT_MODE_FIELD => get_option(
        self::SETTLEMENT_MODE_OPTION,
        \Blink\WC\NonCustodial\SettlementMode::Hybrid->value
      )
    ]);
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

/**
 * A fixed conversion rate, so the suite never calls the real Blink API.
 *
 * The rate is the one piece of the non-custodial flow that still reached out
 * to api.blink.sv during an end-to-end run: everything else is served by the
 * fake LNURL endpoints above. That made a required check depend on a third
 * party being up, and made the amounts on the pay page move with the market,
 * which no assertion can pin.
 *
 * 100,000 sat per unit of currency keeps the arithmetic obvious: a 10.00
 * order is 1,000,000 sat.
 */
add_filter('blink_service_satsRateProvider', static function () {
  return new class implements \Blink\WC\NonCustodial\SatsRateProviderInterface {
    public function toSatoshis(float $amount, string $currency): ?int {
      return (int) round($amount * 100000);
    }
  };
});
