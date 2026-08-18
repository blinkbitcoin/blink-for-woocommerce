<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\SettlementOutcome;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeOrder;
use PHPUnit\Framework\TestCase;

final class InvoiceRepositoryTest extends TestCase {
  private const NOW = 1700000000;

  private FakeClock $clock;
  private InvoiceRepository $repository;
  private FakeOrder $order;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FakeClock(self::NOW);
    $this->repository = new InvoiceRepository($this->clock);
    $this->order = new FakeOrder(42, '10.00', 'USD');
  }

  private function invoice(array $overrides = []): StoredInvoice {
    return new StoredInvoice(
      $overrides['paymentHash'] ?? str_repeat('ab', 32),
      $overrides['paymentRequest'] ?? 'lnbc100u1xyz',
      $overrides['verifyUrl'] ?? 'https://blink.sv/verify/' . str_repeat('ab', 32),
      $overrides['lnAddress'] ?? 'shop@blink.sv',
      $overrides['amountMsat'] ?? 10000000,
      $overrides['satoshis'] ?? 10000,
      $overrides['createdAt'] ?? self::NOW,
      $overrides['expiresAt'] ?? self::NOW + 3600,
      $overrides['orderTotal'] ?? '10.00',
      $overrides['orderCurrency'] ?? 'USD'
    );
  }

  public function testStoresAndLoadsAnInvoice(): void {
    $this->repository->store($this->order, $this->invoice());

    $loaded = $this->repository->load($this->order);

    $this->assertNotNull($loaded);
    $this->assertSame(str_repeat('ab', 32), $loaded->paymentHash);
    $this->assertSame('lnbc100u1xyz', $loaded->paymentRequest);
    $this->assertSame('shop@blink.sv', $loaded->lnAddress);
    $this->assertSame(10000000, $loaded->amountMsat);
    $this->assertSame(10000, $loaded->satoshis);
    $this->assertSame(self::NOW + 3600, $loaded->expiresAt);
    $this->assertSame('10.00', $loaded->orderTotal);
    $this->assertSame('USD', $loaded->orderCurrency);
  }

  public function testStoringMarksTheOrderNonCustodial(): void {
    $this->assertFalse($this->repository->isNonCustodial($this->order));

    $this->repository->store($this->order, $this->invoice());

    $this->assertTrue($this->repository->isNonCustodial($this->order));
  }

  /**
   * Protected meta keeps the verify URL out of the order editor's custom
   * fields box. It is fetched over HTTP and trusted for settlement, so a shop
   * manager must not be able to repoint it at a server that always answers
   * "settled".
   */
  public function testSettlementCriticalKeysAreProtectedMeta(): void {
    $this->repository->store($this->order, $this->invoice());

    foreach (
      [
        InvoiceRepository::VERIFY_URL,
        InvoiceRepository::LN_ADDRESS,
        InvoiceRepository::EXPIRES_AT,
        InvoiceRepository::AMOUNT_MSAT,
        InvoiceRepository::ACCOUNT_TYPE,
      ]
      as $key
    ) {
      $this->assertStringStartsWith('_', $key, $key . ' must be protected meta');
      $this->assertArrayHasKey($key, $this->order->meta);
    }
  }

  /** The custodial webhook looks orders up by this key, so it cannot move. */
  public function testThePaymentHashKeepsItsLegacyKeyName(): void {
    $this->repository->store($this->order, $this->invoice());

    $this->assertSame('blink_id', InvoiceRepository::PAYMENT_HASH);
    $this->assertSame(str_repeat('ab', 32), $this->order->meta['blink_id']);
  }

  public function testLoadReturnsNullWhenNothingIsStored(): void {
    $this->assertNull($this->repository->load($this->order));
  }

  /**
   * @dataProvider missingEssentials
   */
  public function testLoadReturnsNullWhenAnEssentialFieldIsMissing(string $key): void {
    $this->repository->store($this->order, $this->invoice());
    $this->order->deleteMeta($key);

    $this->assertNull($this->repository->load($this->order));
  }

  /** @return array<string,array{string}> */
  public static function missingEssentials(): array {
    return [
      'payment hash' => [InvoiceRepository::PAYMENT_HASH],
      'verify url' => [InvoiceRepository::VERIFY_URL],
      'lightning address' => [InvoiceRepository::LN_ADDRESS],
    ];
  }

  public function testStoringResetsTheAttemptCounters(): void {
    $this->repository->store($this->order, $this->invoice());
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, true);

    $this->repository->store($this->order, $this->invoice());

    $this->assertSame(0, $this->repository->attempts($this->order));
    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
  }

  /**
   * Leaving the account-type marker behind meant a cleared order still looked
   * non-custodial to the pay page and the poll endpoint, but had no invoice.
   */
  public function testClearRemovesEverythingIncludingTheAccountTypeMarker(): void {
    $this->repository->store($this->order, $this->invoice());
    $this->repository->markSettled($this->order, 'ff00');
    $this->repository->markTerminal($this->order, SettlementStatus::Paid);

    $this->repository->clear($this->order);

    $this->assertSame([], $this->order->meta, 'no non-custodial meta may survive');
    $this->assertFalse($this->repository->isNonCustodial($this->order));
    $this->assertNull($this->repository->load($this->order));
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testCachesAndReadsBackAStatus(): void {
    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Pending, self::NOW)
    );

    $cached = $this->repository->cachedStatus($this->order);

    $this->assertNotNull($cached);
    $this->assertSame(SettlementStatus::Pending, $cached->status);
    $this->assertSame(self::NOW, $cached->observedAt);
  }

  public function testNoCachedStatusBeforeAnythingIsObserved(): void {
    $this->assertNull($this->repository->cachedStatus($this->order));
  }

  /** Unknown means "we learned nothing", so it must not overwrite a real observation. */
  public function testUnknownIsNeverCached(): void {
    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Pending, self::NOW)
    );

    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Unknown, self::NOW + 10, 'locked')
    );

    $cached = $this->repository->cachedStatus($this->order);
    $this->assertSame(SettlementStatus::Pending, $cached?->status);
    $this->assertSame(self::NOW, $cached?->observedAt);
  }

  public function testAnUnrecognisedStoredStatusIsIgnored(): void {
    $this->order->setMeta(InvoiceRepository::STATUS, 'SOMETHING_ELSE');

    $this->assertNull($this->repository->cachedStatus($this->order));
  }

  public function testCachedStatusReportsTerminalityFromTheTerminalMarker(): void {
    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Paid, self::NOW)
    );
    $this->assertFalse($this->repository->cachedStatus($this->order)?->terminal);

    $this->repository->markTerminal($this->order, SettlementStatus::Paid);

    $this->assertTrue($this->repository->cachedStatus($this->order)?->terminal);
  }

  public function testAttemptsAccumulate(): void {
    $this->repository->recordAttempt($this->order, false);
    $this->repository->recordAttempt($this->order, false);

    $this->assertSame(2, $this->repository->attempts($this->order));
  }

  public function testConsecutiveErrorsAccumulate(): void {
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, true);

    $this->assertSame(3, $this->repository->consecutiveErrors($this->order));
  }

  /** One good answer means the endpoint is reachable again. */
  public function testASuccessfulAttemptResetsTheConsecutiveErrorCount(): void {
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, true);

    $this->repository->recordAttempt($this->order, false);

    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
    $this->assertSame(
      3,
      $this->repository->attempts($this->order),
      'attempts still count'
    );
  }

  /**
   * The latch that stops the scheduler tick and the browser poll both applying
   * the same payment.
   */
  public function testSettlementIsRecordedExactlyOnce(): void {
    $this->assertTrue($this->repository->markSettled($this->order, 'ff00'));
    $this->assertFalse($this->repository->markSettled($this->order, 'ff00'));
    $this->assertFalse($this->repository->markSettled($this->order, 'different'));
  }

  public function testSettlementRecordsWhenItHappenedAndThePreimage(): void {
    $this->repository->markSettled($this->order, 'ff00');

    $this->assertSame(self::NOW, $this->order->meta[InvoiceRepository::SETTLED_AT]);
    $this->assertSame('ff00', $this->order->meta[InvoiceRepository::PREIMAGE]);
  }

  public function testSettlementWithoutAPreimageStoresNoPreimage(): void {
    $this->repository->markSettled($this->order, null);

    $this->assertArrayNotHasKey(InvoiceRepository::PREIMAGE, $this->order->meta);
    $this->assertSame(self::NOW, $this->order->meta[InvoiceRepository::SETTLED_AT]);
  }

  public function testEmptyPreimageIsNotStored(): void {
    $this->repository->markSettled($this->order, '');

    $this->assertArrayNotHasKey(InvoiceRepository::PREIMAGE, $this->order->meta);
  }

  public function testTerminalStatusRoundTrips(): void {
    $this->assertNull($this->repository->terminalStatus($this->order));

    $this->repository->markTerminal($this->order, SettlementStatus::Expired);

    $this->assertSame(
      SettlementStatus::Expired,
      $this->repository->terminalStatus($this->order)
    );
  }

  public function testAnUnrecognisedTerminalMarkerIsIgnored(): void {
    $this->order->setMeta(InvoiceRepository::TERMINAL, 'NONSENSE');

    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testUnchangedTotalsAreRecognised(): void {
    $this->assertTrue($this->repository->totalsUnchanged($this->order, $this->invoice()));
  }

  public function testDecimalFormattingDifferencesAreNotTreatedAsAChange(): void {
    $this->order->setTotal('10');

    $this->assertTrue(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderTotal' => '10.00'])
      )
    );
  }

  /**
   * An order edited between invoice creation and settlement would otherwise be
   * completed for an amount nobody paid.
   */
  public function testAChangedTotalIsDetected(): void {
    $this->order->setTotal('99.00');

    $this->assertFalse(
      $this->repository->totalsUnchanged($this->order, $this->invoice())
    );
  }

  public function testAChangedCurrencyIsDetected(): void {
    $this->order->setCurrency('EUR');

    $this->assertFalse(
      $this->repository->totalsUnchanged($this->order, $this->invoice())
    );
  }

  /** Invoices stored before totals were recorded cannot be compared. */
  public function testAnInvoiceWithoutRecordedTotalsSkipsTheComparison(): void {
    $this->order->setTotal('99.00');

    $this->assertTrue(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderTotal' => ''])
      )
    );
    $this->assertTrue(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderCurrency' => ''])
      )
    );
  }

  public function testStoredInvoiceExposesItsAddress(): void {
    $address = $this->invoice()->address();

    $this->assertNotNull($address);
    $this->assertSame('blink.sv', $address->host);
  }

  public function testStoredInvoiceWithAnUnparsableAddressReturnsNull(): void {
    $this->assertNull($this->invoice(['lnAddress' => 'not an address'])->address());
  }
}
