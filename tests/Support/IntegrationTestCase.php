<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\WcOrderRecord;
use Blink\WC\Services;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\FakeScheduler;
use WP_UnitTestCase;

/**
 * Base for tests that need real WordPress and WooCommerce.
 *
 * The outside world is still substituted -- HTTP, the clock, DNS -- through
 * the same service filters a site owner could use, so these tests exercise
 * genuine WooCommerce behaviour without touching the network or waiting on
 * real time.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {
  protected const NOW = 1700000000;

  protected FakeClock $clock;
  protected FakeHttpClient $http;
  protected ?FakeScheduler $fakeScheduler = null;

  /** Preimage and the payment hash it proves. */
  protected string $preimage = 'ab00112233445566778899aabbccddeeff00112233445566778899aabbccddee';
  protected string $paymentHash;

  public function set_up() {
    parent::set_up();

    $this->paymentHash = hash('sha256', (string) hex2bin($this->preimage));
    $this->clock = new FakeClock(self::NOW);
    $this->http = new FakeHttpClient();

    add_filter('blink_service_clock', fn() => $this->clock);
    add_filter('blink_service_http', fn() => $this->http);
    add_filter('blink_service_dnsResolver', fn() => new Fake\FakeDnsResolver([], ['93.184.216.34']));

    Services::replace(null);
    Services::replace(new Services());

    update_option('blink_account_type', 'non_custodial');
    update_option('blink_ln_address', 'shop@blink.sv');
    update_option('blink_env', 'blink');
  }

  public function tear_down() {
    Services::replace(null);
    delete_option('blink_account_type');
    delete_option('blink_ln_address');
    delete_option('blink_order_states');
    delete_option('blink_protect_order_status');

    parent::tear_down();
  }

  /** Substitutes a scheduler that records rather than enqueues. */
  protected function useFakeScheduler(): FakeScheduler {
    $this->fakeScheduler = new FakeScheduler();
    add_filter('blink_service_scheduler', fn() => $this->fakeScheduler);
    Services::replace(new Services());

    return $this->fakeScheduler;
  }

  protected function services(): Services {
    return Services::instance();
  }

  protected function makeOrder(string $total = '10.00', string $currency = 'USD'): \WC_Order {
    $order = wc_create_order();
    $order->set_currency($currency);
    $order->set_total($total);
    $order->set_status('pending');
    $order->save();

    return $order;
  }

  protected function record(\WC_Order $order): WcOrderRecord {
    return new WcOrderRecord($order);
  }

  protected function repository(): InvoiceRepository {
    return $this->services()->invoiceRepository();
  }

  /** Stores a ready-made non-custodial invoice on an order. */
  protected function storeInvoice(\WC_Order $order, array $overrides = []): StoredInvoice {
    $invoice = new StoredInvoice(
      $overrides['paymentHash'] ?? $this->paymentHash,
      $overrides['paymentRequest'] ?? 'lnbc100u1xyz',
      $overrides['verifyUrl'] ?? 'https://blink.sv/verify/' . ($overrides['paymentHash'] ?? $this->paymentHash),
      $overrides['lnAddress'] ?? 'shop@blink.sv',
      $overrides['amountMsat'] ?? 10000000,
      $overrides['satoshis'] ?? 10000,
      $overrides['createdAt'] ?? self::NOW,
      $overrides['expiresAt'] ?? self::NOW + 3600,
      $overrides['orderTotal'] ?? (string) $order->get_total(),
      $overrides['orderCurrency'] ?? (string) $order->get_currency()
    );

    $this->repository()->store($this->record($order), $invoice);

    return $invoice;
  }

  /** @return list<string> the order's note contents, newest first. */
  protected function noteContents(\WC_Order $order): array {
    $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 100]);

    return array_map(static fn($note): string => trim($note->content), $notes);
  }

  protected function countNotesContaining(\WC_Order $order, string $needle): int {
    $count = 0;
    foreach ($this->noteContents($order) as $note) {
      if (str_contains($note, $needle)) {
        $count++;
      }
    }

    return $count;
  }

  protected function reload(\WC_Order $order): \WC_Order {
    return wc_get_order($order->get_id());
  }
}
