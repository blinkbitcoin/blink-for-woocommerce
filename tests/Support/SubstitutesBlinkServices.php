<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\WcOrderRecord;
use Blink\WC\Services;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\FakeScheduler;
use Blink\WC\Tests\Support\Fake\FixedSatsRateProvider;

/**
 * Substitutes the outside world -- HTTP, the clock, DNS -- through the same
 * service filters a site owner could use, so tests exercise real WooCommerce
 * behaviour without a network or real time.
 */
trait SubstitutesBlinkServices {
  /** Fixed point in time every substituted clock starts from. */

  protected FakeClock $clock;
  protected FakeHttpClient $http;
  protected ?FakeScheduler $fakeScheduler = null;
  protected FixedSatsRateProvider $rates;

  protected string $preimage = 'ab00112233445566778899aabbccddeeff00112233445566778899aabbccddee';
  protected string $paymentHash;

  protected function substituteBlinkServices(): void {
    $this->paymentHash = hash('sha256', (string) hex2bin($this->preimage));
    $this->clock = new FakeClock(TestTime::NOW);
    $this->http = new FakeHttpClient();

    add_filter('blink_service_clock', fn() => $this->clock);
    add_filter('blink_service_http', fn() => $this->http);
    add_filter(
      'blink_service_dnsResolver',
      fn() => new FakeDnsResolver([], ['93.184.216.34'])
    );
    $this->rates = new FixedSatsRateProvider();
    add_filter('blink_service_satsRateProvider', fn() => $this->rates);

    Services::replace(new Services());

    update_option('blink_account_type', 'non_custodial');
    update_option('blink_ln_address', 'shop@blink.sv');
    update_option('blink_env', 'blink');
  }

  protected function restoreBlinkServices(): void {
    Services::replace(null);
    delete_option('blink_account_type');
    delete_option('blink_ln_address');
    delete_option('blink_order_states');
    delete_option('blink_protect_order_status');
  }

  protected function useFakeScheduler(): FakeScheduler {
    $this->fakeScheduler = new FakeScheduler();
    add_filter('blink_service_scheduler', fn() => $this->fakeScheduler);
    Services::replace(new Services());

    return $this->fakeScheduler;
  }

  protected function services(): Services {
    return Services::instance();
  }

  protected function makeOrder(
    string $total = '10.00',
    string $currency = 'USD'
  ): \WC_Order {
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

  protected function storeInvoice(
    \WC_Order $order,
    array $overrides = []
  ): StoredInvoice {
    $hash = $overrides['paymentHash'] ?? $this->paymentHash;
    $invoice = new StoredInvoice(
      $hash,
      $overrides['paymentRequest'] ?? 'lnbc100u1xyz',
      $overrides['verifyUrl'] ?? 'https://blink.sv/verify/' . $hash,
      $overrides['lnAddress'] ?? 'shop@blink.sv',
      $overrides['amountMsat'] ?? 10000000,
      $overrides['satoshis'] ?? 10000,
      $overrides['createdAt'] ?? TestTime::NOW,
      $overrides['expiresAt'] ?? TestTime::NOW + 3600,
      $overrides['orderTotal'] ?? (string) $order->get_total(),
      $overrides['orderCurrency'] ?? (string) $order->get_currency()
    );

    $this->repository()->store($this->record($order), $invoice);

    return $invoice;
  }

  protected function reload(\WC_Order $order): \WC_Order {
    return wc_get_order($order->get_id());
  }

  /** @return list<string> */
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
}
