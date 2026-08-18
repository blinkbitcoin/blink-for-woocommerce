<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Gateway;

use Blink\WC\Gateway\BlinkLnGateway;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The contract between the pay page and the script that drives it.
 *
 * renderPayPage() emits an inline `BlinkPay` object and markup with a handful
 * of ids; assets/js/frontend/pay.js reads exactly those keys and ids. Nothing
 * used to check the two against each other, and the consequences were silent:
 * renaming `orderKey` to `order_key` in the payload left every test in the
 * repository passing while every real pay page polled forever and got a 403,
 * because the endpoint reads $_POST['order_key'].
 *
 * This test does two things. It asserts the contract against an explicit list,
 * so widening or renaming it is a deliberate act. And it writes the real
 * rendered page to tests/fixtures/pay-page/, which the JavaScript suite then
 * loads instead of hand-written markup -- so the JS tests run against what the
 * gateway actually emits rather than against a copy that drifts.
 */
final class PayPageContractTest extends IntegrationTestCase {
  /**
   * Every key assets/js/frontend/pay.js reads from the payload.
   *
   * Adding one here without adding it to the payload, or vice versa, fails.
   */
  private const PAYLOAD_KEYS = [
    'ajaxUrl',
    'deadline',
    'i18n',
    'lightningUri',
    'nonce',
    'orderId',
    'orderKey',
    'paymentRequest',
    'pollInterval',
    'redirectUrl',
  ];

  /** Every element id the script looks up, and the page must therefore emit. */
  private const REQUIRED_DOM_IDS = [
    'blink-pay-qr',
    'blink-pay-bolt11',
    'blink-pay-copy',
    'blink-pay-status',
  ];

  /** Translated strings the script falls back from. */
  private const I18N_KEYS = [
    'checkAgain',
    'copied',
    'copy',
    'expired',
    'invalid',
    'paid',
    'review',
    'unconfirmed',
    'unreachable',
  ];

  private string $html;

  /** @var array<string,mixed> */
  private array $payload;

  public function set_up() {
    parent::set_up();

    $order = $this->makeOrder();
    $this->storeInvoice($order);

    // Inline script data accumulates on the global registry, so without this
    // each test appends another BlinkPay assignment to the previous ones.
    wp_deregister_script('blink-pay');

    $gateway = new BlinkLnGateway();
    ob_start();
    $gateway->renderPayPage($order->get_id());
    $this->html = (string) ob_get_clean();

    $this->payload = $this->extractPayload();
  }

  public function test_the_payload_carries_exactly_the_keys_the_script_reads(): void {
    $actual = array_keys($this->payload);
    sort($actual);
    $expected = self::PAYLOAD_KEYS;
    sort($expected);

    $this->assertSame(
      $expected,
      $actual,
      'The pay page payload and assets/js/frontend/pay.js have drifted apart.'
    );
  }

  public function test_the_page_emits_every_element_the_script_looks_up(): void {
    foreach (self::REQUIRED_DOM_IDS as $id) {
      $this->assertStringContainsString(
        'id="' . $id . '"',
        $this->html,
        $id . ' is read by pay.js but not emitted by the pay page.'
      );
    }
  }

  public function test_every_translated_string_the_script_uses_is_supplied(): void {
    $actual = array_keys((array) $this->payload['i18n']);
    sort($actual);
    $expected = self::I18N_KEYS;
    sort($expected);

    $this->assertSame($expected, $actual);
  }

  /**
   * @dataProvider payloadTypes
   */
  public function test_payload_values_reach_the_browser_as_the_right_type(
    string $key,
    string $type
  ): void {
    $this->assertSame(
      $type,
      get_debug_type($this->payload[$key]),
      $key .
        ' must arrive as ' .
        $type .
        '; wp_localize_script would have made it a string.'
    );
  }

  /** @return array<string,array{string,string}> */
  public static function payloadTypes(): array {
    return [
      // The original defect: as a string, the client's numeric comparison
      // never matched and the page polled forever.
      'deadline is a number' => ['deadline', 'int'],
      'orderId is a number' => ['orderId', 'int'],
      'pollInterval is a number' => ['pollInterval', 'int'],
      'orderKey is a string' => ['orderKey', 'string'],
      'nonce is a string' => ['nonce', 'string'],
      'ajaxUrl is a string' => ['ajaxUrl', 'string'],
      'paymentRequest is a string' => ['paymentRequest', 'string'],
      'lightningUri is a string' => ['lightningUri', 'string'],
      'redirectUrl is a string' => ['redirectUrl', 'string'],
      'i18n is an object' => ['i18n', 'array'],
    ];
  }

  /**
   * Wallets scan the QR far more reliably in alphanumeric mode, which requires
   * uppercase. Asserted nowhere else.
   */
  public function test_the_lightning_uri_is_uppercased_for_the_qr_encoder(): void {
    $this->assertStringStartsWith('lightning:', $this->payload['lightningUri']);

    $invoice = substr($this->payload['lightningUri'], strlen('lightning:'));
    $this->assertSame(strtoupper($invoice), $invoice);
  }

  public function test_the_order_key_matches_what_the_endpoint_checks(): void {
    // pay.js posts this straight back as order_key; ajaxCheckInvoice compares
    // it with hash_equals against the order's real key.
    $this->assertNotSame('', $this->payload['orderKey']);
    $this->assertStringStartsWith('wc_order_', $this->payload['orderKey']);
  }

  /**
   * Writes the real page for the JavaScript suite to consume.
   *
   * Keeping the fixture generated rather than hand-written is the point: a
   * hand-maintained copy had already drifted, losing the amount and the
   * "open in wallet" link.
   */
  public function test_the_fixture_the_javascript_suite_uses_is_current(): void {
    $dir = dirname(__DIR__, 2) . '/fixtures/pay-page';
    if (!is_dir($dir)) {
      mkdir($dir, 0777, true);
    }

    $markup = $this->stripDynamicValues($this->extractSection());
    $payload = $this->payload;
    // Values that change per run would make the fixture churn on every commit.
    $payload['nonce'] = 'test-nonce';
    $payload['orderId'] = 42;
    $payload['orderKey'] = 'wc_order_testkey';
    $payload['deadline'] = 1700003600;
    $payload['ajaxUrl'] = 'https://shop.test/wp-admin/admin-ajax.php';
    $payload['redirectUrl'] = 'https://shop.test/checkout/order-received/42/';

    $markupPath = $dir . '/rendered.html';
    $payloadPath = $dir . '/payload.json';
    $encoded = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (getenv('BLINK_UPDATE_FIXTURES') === '1') {
      file_put_contents($markupPath, $markup);
      file_put_contents($payloadPath, $encoded . "\n");
    }

    $this->assertFileExists(
      $markupPath,
      'Run with BLINK_UPDATE_FIXTURES=1 to create it.'
    );
    $this->assertSame(
      trim((string) file_get_contents($markupPath)),
      trim($markup),
      'The pay page markup changed. Re-run with BLINK_UPDATE_FIXTURES=1 and commit the fixture.'
    );
    $this->assertSame(
      trim((string) file_get_contents($payloadPath)),
      trim($encoded),
      'The pay page payload changed. Re-run with BLINK_UPDATE_FIXTURES=1 and commit the fixture.'
    );
  }

  /** @return array<string,mixed> */
  private function extractPayload(): array {
    $inline = wp_scripts()->get_data('blink-pay', 'before');
    $script = is_array($inline) ? implode("\n", $inline) : (string) $inline;

    $this->assertNotSame('', $script, 'The pay page emitted no BlinkPay payload.');
    // One assignment per line; take the last in case anything else registered
    // inline data before this test ran.
    $this->assertSame(
      1,
      preg_match_all('/var BlinkPay = (\{.*\});?$/m', $script, $matches),
      'Expected exactly one BlinkPay assignment, got: ' . $script
    );

    $json = end($matches[1]);
    $decoded = json_decode((string) $json, true);
    $this->assertIsArray($decoded, 'The BlinkPay payload was not valid JSON: ' . $json);

    return $decoded;
  }

  /** The pay section only, so unrelated theme output cannot churn the fixture. */
  private function extractSection(): string {
    $start = strpos($this->html, '<section');
    $end = strrpos($this->html, '</section>');
    $this->assertNotFalse($start);
    $this->assertNotFalse($end);

    return substr($this->html, $start, $end - $start + strlen('</section>'));
  }

  private function stripDynamicValues(string $markup): string {
    return (string) preg_replace('/\s+/', ' ', trim($markup));
  }
}
