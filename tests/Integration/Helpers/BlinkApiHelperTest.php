<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Helpers;

use Blink\WC\Helpers\BlinkApiHelper;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Blink\WC\Tests\Support\IntegrationTestCase;

/**
 * The settings-facing helper, which this PR extended with the non-custodial
 * account type.
 *
 * It is legacy code and mostly stays that way, but the lightning address
 * parts are new and decide what the merchant is told about their own
 * configuration -- including whether an address is reachable at all, which is
 * the difference between a shop that can take payments and one that silently
 * cannot.
 */
final class BlinkApiHelperTest extends IntegrationTestCase {
  private function configureNonCustodial(string $address = 'shop@blink.sv'): void {
    update_option('blink_account_type', 'non_custodial');
    update_option('blink_ln_address', $address);
    update_option('blink_env', 'blink');
  }

  private function configureCustodial(): void {
    update_option('blink_account_type', 'custodial');
    update_option('blink_env', 'blink');
    update_option('blink_api_key', 'key-123');
    update_option('blink_wallet_type', 'bitcoin');
  }

  // ------------------------------------------------------------- the domain

  /** @dataProvider addressDomains */
  public function test_the_domain_is_extracted_from_a_lightning_address(
    ?string $address,
    string $expected
  ): void {
    $this->assertSame($expected, BlinkApiHelper::lnAddressDomain($address));
  }

  /** @return array<string,array{string|null,string}> */
  public static function addressDomains(): array {
    return [
      'ordinary address' => ['shop@blink.sv', 'blink.sv'],
      'uppercase is normalised' => ['Shop@BLINK.SV', 'blink.sv'],
      'surrounding space is trimmed' => ['shop@ blink.sv ', 'blink.sv'],
      'an extra @ keeps the remainder' => ['a@b@blink.sv', 'b@blink.sv'],
      'no domain part' => ['shop', ''],
      'empty' => ['', ''],
      'null' => [null, ''],
    ];
  }

  // ---------------------------------------------------------------- the urls

  public function test_the_pay_url_is_environment_specific(): void {
    $this->assertSame('https://pay.blink.sv', BlinkApiHelper::getPayUrl('blink'));
    $this->assertSame(
      'https://pay.staging.galoy.io',
      BlinkApiHelper::getPayUrl('staging')
    );
  }

  public function test_an_unknown_environment_falls_back_to_production(): void {
    $this->assertSame('https://pay.blink.sv', BlinkApiHelper::getPayUrl('nonsense'));
  }

  // -------------------------------------------------------------- the config

  public function test_a_non_custodial_config_carries_the_address(): void {
    $this->configureNonCustodial();

    $config = BlinkApiHelper::getConfig();

    $this->assertSame('non_custodial', $config['account_type']);
    $this->assertSame('shop@blink.sv', $config['ln_address']);
    $this->assertSame('bitcoin', $config['wallet_type']);
  }

  /**
   * Without an address there is nothing to pay, so the account counts as
   * unconfigured rather than half-configured.
   */
  public function test_a_non_custodial_account_without_an_address_is_unconfigured(): void {
    update_option('blink_account_type', 'non_custodial');
    delete_option('blink_ln_address');

    $this->assertSame([], BlinkApiHelper::getConfig());
    $this->assertFalse((new BlinkApiHelper())->configured);
  }

  public function test_a_custodial_config_is_returned_when_complete(): void {
    $this->configureCustodial();

    $config = BlinkApiHelper::getConfig();

    $this->assertSame('custodial', $config['account_type']);
    $this->assertSame('key-123', $config['api_key']);
  }

  public function test_a_custodial_account_missing_a_wallet_type_is_unconfigured(): void {
    $this->configureCustodial();
    delete_option('blink_wallet_type');

    $this->assertSame([], BlinkApiHelper::getConfig());
  }

  /** A stored value outside the known set must not select a code path. */
  public function test_an_unrecognised_account_type_is_treated_as_custodial(): void {
    $this->configureCustodial();
    update_option('blink_account_type', 'something_else');

    $this->assertSame('custodial', BlinkApiHelper::getConfig()['account_type']);
  }

  public function test_the_account_type_is_readable_from_the_helper(): void {
    $this->configureNonCustodial();
    $this->assertTrue((new BlinkApiHelper())->isNonCustodial());

    $this->configureCustodial();
    $this->assertFalse((new BlinkApiHelper())->isNonCustodial());
  }

  // ------------------------------------------------------- address verifying

  public function test_a_malformed_address_is_rejected_without_a_request(): void {
    $this->assertFalse(BlinkApiHelper::verifyLnAddress('not-an-address'));
    $this->assertSame(0, $this->http->requestCount());
  }

  public function test_a_reachable_address_verifies(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'minSendable' => 1000,
      'maxSendable' => 100000000000,
      'metadata' => '[["text/plain","shop"]]',
    ]);

    $this->assertTrue(BlinkApiHelper::verifyLnAddress('shop@blink.sv'));
  }

  public function test_an_unreachable_address_does_not_verify(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'no such user']);

    $this->assertFalse(BlinkApiHelper::verifyLnAddress('ghost@blink.sv'));
  }

  /**
   * The settings screen renders this on every page load. Without the cache a
   * merchant sitting on that page would hammer their own address provider.
   */
  public function test_the_answer_is_cached_rather_than_refetched(): void {
    $this->http->queueJson([
      'tag' => 'payRequest',
      'callback' => 'https://blink.sv/lnurlp/shop/callback',
      'metadata' => '[["text/plain","shop"]]',
    ]);

    $this->assertTrue(BlinkApiHelper::verifyLnAddress('shop@blink.sv'));
    $requests = $this->http->requestCount();

    $this->assertTrue(BlinkApiHelper::verifyLnAddress('shop@blink.sv'));
    $this->assertSame($requests, $this->http->requestCount(), 'no second request');
  }

  public function test_a_cached_failure_is_also_reused(): void {
    $this->http->queueJson(['status' => 'ERROR', 'reason' => 'no such user']);

    $this->assertFalse(BlinkApiHelper::verifyLnAddress('ghost@blink.sv'));
    $requests = $this->http->requestCount();

    $this->assertFalse(BlinkApiHelper::verifyLnAddress('ghost@blink.sv'));
    $this->assertSame($requests, $this->http->requestCount());
  }

  // ----------------------------------------------------------- the api key

  public function test_an_api_key_check_without_an_environment_fails_closed(): void {
    $this->assertFalse(BlinkApiHelper::verifyApiKey(null, 'key-123'));
    $this->assertFalse(BlinkApiHelper::verifyApiKey('blink', null));
  }

  // ------------------------------------------------------ custodial invoices

  public function test_reading_a_custodial_invoice_needs_a_configured_account(): void {
    update_option('blink_account_type', 'non_custodial');
    delete_option('blink_ln_address');
    $helper = new BlinkApiHelper();

    $this->assertNull($helper->getInvoiceCustodial('abc123'));
  }

  public function test_reading_a_custodial_invoice_needs_a_payment_hash(): void {
    $this->configureCustodial();

    $this->assertNull((new BlinkApiHelper())->getInvoiceCustodial(''));
  }

  public function test_creating_an_invoice_needs_complete_arguments(): void {
    $this->configureCustodial();
    $helper = new BlinkApiHelper();

    $this->assertNull($helper->createInvoice(0, 'USD', 'GW-1'));
    $this->assertNull($helper->createInvoice(10.0, '', 'GW-1'));
    $this->assertNull($helper->createInvoice(10.0, 'USD', ''));
  }

  public function test_creating_an_invoice_needs_a_configured_account(): void {
    update_option('blink_account_type', 'non_custodial');
    delete_option('blink_ln_address');

    $this->assertNull((new BlinkApiHelper())->createInvoice(10.0, 'USD', 'GW-1'));
  }

  // ------------------------------------------------- unreachable api errors

  /**
   * Points the client at a port nothing listens on, so the request fails at
   * once. Without this seam these paths could only be reached by calling the
   * real Blink API from a test run.
   */
  private function pointTheApiAtNothing(): void {
    add_filter('blink_api_url', static fn(): string => 'http://127.0.0.1:1/graphql');
  }

  /**
   * An API that cannot be reached must read as "key not confirmed". Answering
   * true would tell a merchant their credentials work when nothing checked
   * them.
   */
  public function test_an_unreachable_api_cannot_confirm_a_key(): void {
    $this->pointTheApiAtNothing();

    $this->assertFalse(BlinkApiHelper::verifyApiKey('blink', 'key-123'));
  }

  /**
   * And an unreadable invoice status must read as "unknown", not as an empty
   * result: the caller uses this to decide whether an order was paid.
   */
  public function test_an_unreachable_api_yields_no_invoice_status(): void {
    $this->configureCustodial();
    $this->pointTheApiAtNothing();

    $this->assertNull((new BlinkApiHelper())->getInvoiceCustodial('abc123'));
  }

  public function test_the_endpoint_can_be_filtered_for_a_self_hosted_instance(): void {
    add_filter('blink_api_url', static fn(): string => 'https://galoy.example/graphql');

    $this->assertSame(
      'https://galoy.example/graphql',
      BlinkApiHelper::getApiUrl('blink')
    );
  }

  // ------------------------------------------------------- successful calls

  /**
   * Answers the Blink GraphQL endpoint with a canned response, so the paths
   * that read a successful reply can be exercised without the real API.
   *
   * @param array<string,mixed> $payload the GraphQL body to return
   */
  private function apiReturns(array $payload): void {
    $handler = HandlerStack::create(
      new MockHandler([new Response(200, [], (string) json_encode($payload))])
    );
    add_filter('blink_api_http_handler', static fn() => $handler);
  }

  public function test_a_key_with_the_receive_scope_is_accepted(): void {
    $this->apiReturns(['data' => ['authorization' => ['scopes' => ['RECEIVE']]]]);

    $this->assertTrue(BlinkApiHelper::verifyApiKey('blink', 'key-123'));
  }

  public function test_a_key_with_the_write_scope_is_accepted(): void {
    $this->apiReturns(['data' => ['authorization' => ['scopes' => ['WRITE']]]]);

    $this->assertTrue(BlinkApiHelper::verifyApiKey('blink', 'key-123'));
  }

  /**
   * A key that can only read is not enough to take payments, and telling the
   * merchant otherwise would leave them with a shop that cannot invoice.
   */
  public function test_a_key_with_neither_scope_is_rejected(): void {
    $this->apiReturns(['data' => ['authorization' => ['scopes' => ['READ']]]]);

    $this->assertFalse(BlinkApiHelper::verifyApiKey('blink', 'key-123'));
  }

  public function test_a_custodial_invoice_status_is_returned(): void {
    $this->configureCustodial();
    $this->apiReturns([
      'data' => [
        'lnInvoicePaymentStatusByHash' => ['status' => 'PAID', 'paymentHash' => 'abc123'],
      ],
    ]);

    $status = (new BlinkApiHelper())->getInvoiceCustodial('abc123');

    $this->assertSame('PAID', $status['status']);
  }

  /** A reply that is not an array is not a status, and must not read as one. */
  public function test_a_non_array_invoice_status_is_discarded(): void {
    $this->configureCustodial();
    $this->apiReturns(['data' => ['lnInvoicePaymentStatusByHash' => 'nonsense']]);

    $this->assertNull((new BlinkApiHelper())->getInvoiceCustodial('abc123'));
  }

  public function test_a_graphql_error_yields_no_invoice_status(): void {
    $this->configureCustodial();
    $this->apiReturns(['errors' => [['message' => 'not authorised']]]);

    $this->assertNull((new BlinkApiHelper())->getInvoiceCustodial('abc123'));
  }
}
