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
  public function testAnOrderWithNoInvoiceHasNoStoredAccountType(): void {
    $clock = new FakeClock(1700000000);
    $repository = new InvoiceRepository($clock);
    $order = new FakeOrder(42, '10.00', 'USD');

    $this->assertFalse($repository->hasStoredAccountType($order));
    $this->assertFalse($repository->isNonCustodial($order));
  }

  public function testACustodialOrderHasAStoredAccountTypeThatIsNotNonCustodial(): void {
    $clock = new FakeClock(1700000000);
    $repository = new InvoiceRepository($clock);
    $order = new FakeOrder(42, '10.00', 'USD');
    $order->setMeta(InvoiceRepository::ACCOUNT_TYPE, 'custodial');

    $this->assertTrue(
      $repository->hasStoredAccountType($order),
      'the gateway needs this to tell a custodial order from a brand new one'
    );
    $this->assertFalse($repository->isNonCustodial($order));
  }

  public function testAnOrderWithNoInvoiceFollowsTheAccountTypeSetting(): void {
    $repository = new InvoiceRepository(new FakeClock(1700000000));
    $order = new FakeOrder(42, '10.00', 'USD');

    $this->assertTrue($repository->resolvesNonCustodial($order, true));
    $this->assertFalse($repository->resolvesNonCustodial($order, false));
  }

  public function testALegacyCustodialInvoiceOverridesTheNonCustodialSetting(): void {
    $repository = new InvoiceRepository(new FakeClock(1700000000));
    $order = new FakeOrder(42, '10.00', 'USD');
    $order->setMeta(InvoiceRepository::PAYMENT_HASH, 'legacy-custodial-hash');

    $this->assertFalse(
      $repository->resolvesNonCustodial($order, true),
      'blink_id predates account-type metadata and must preserve the custodial route'
    );
  }

  /**
   * The whole reason resolvesNonCustodial() exists. A merchant who switches the
   * setting while an order is in flight would otherwise send that order down
   * the other path: a non-custodial order routed custodially has its LNURL
   * payment hash queried through the Blink API, and the buyer is redirected to
   * a Blink-hosted checkout that was never created.
   */
  public function testAnOrderInFlightIgnoresASettingChangedUnderIt(): void {
    $repository = new InvoiceRepository(new FakeClock(1700000000));

    $custodialOrder = new FakeOrder(42, '10.00', 'USD');
    $custodialOrder->setMeta(InvoiceRepository::ACCOUNT_TYPE, 'custodial');
    $this->assertFalse(
      $repository->resolvesNonCustodial($custodialOrder, true),
      'a custodial order must stay custodial after the setting flips'
    );

    $nonCustodialOrder = new FakeOrder(43, '10.00', 'USD');
    $nonCustodialOrder->setMeta(
      InvoiceRepository::ACCOUNT_TYPE,
      InvoiceRepository::ACCOUNT_TYPE_NON_CUSTODIAL
    );
    $this->assertTrue(
      $repository->resolvesNonCustodial($nonCustodialOrder, false),
      'a non-custodial order must stay non-custodial after the setting flips'
    );
  }

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

  public function testReplacingKeepsThePreviousInvoiceOutstanding(): void {
    $previous = $this->invoice();
    $current = $this->invoice([
      'paymentHash' => str_repeat('cd', 32),
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
      'createdAt' => self::NOW + 10,
      'expiresAt' => self::NOW + 3610,
    ]);
    $this->repository->store($this->order, $previous);

    $this->repository->replace($this->order, $current);

    $this->assertSame($current->paymentHash, $this->repository->load($this->order)?->paymentHash);
    $this->assertEquals([$previous], $this->repository->outstanding($this->order));
    $this->assertEquals(
      [$previous, $current],
      $this->repository->tracked($this->order)
    );
  }

  public function testRepeatedReplacementTracksEveryDistinctInvoice(): void {
    $first = $this->invoice();
    $second = $this->invoice([
      'paymentHash' => str_repeat('cd', 32),
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
    ]);
    $third = $this->invoice([
      'paymentHash' => str_repeat('ef', 32),
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('ef', 32),
    ]);
    $this->repository->store($this->order, $first);
    $this->repository->replace($this->order, $second);

    $this->repository->replace($this->order, $third);

    $this->assertEquals(
      [$first, $second],
      $this->repository->outstanding($this->order)
    );
  }

  public function testAResolvedInvoiceIsNotCarriedIntoANewAttempt(): void {
    $this->repository->store($this->order, $this->invoice());
    $this->repository->markTerminal($this->order, SettlementStatus::Expired);
    $current = $this->invoice([
      'paymentHash' => str_repeat('cd', 32),
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
    ]);

    $this->repository->replace($this->order, $current);

    $this->assertSame([], $this->repository->outstanding($this->order));
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testMalformedOutstandingInvoiceMetadataIsIgnored(): void {
    $otherwiseValid = [
      'paymentHash' => str_repeat('ab', 32),
      'paymentRequest' => 'lnbc100u1xyz',
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('ab', 32),
      'lnAddress' => 'shop@blink.sv',
      'amountMsat' => 10000000,
      'satoshis' => 10000,
      'createdAt' => self::NOW,
      'expiresAt' => self::NOW + 3600,
      'orderTotal' => '10.00',
      'orderCurrency' => 'USD',
    ];
    $this->order->setMeta(InvoiceRepository::OUTSTANDING_INVOICES, [
      'not an invoice',
      ['paymentHash' => str_repeat('ab', 32)],
      array_merge($otherwiseValid, ['amountMsat' => 'not an integer']),
      array_merge($otherwiseValid, ['orderTotal' => []]),
    ]);

    $this->assertSame([], $this->repository->outstanding($this->order));
  }

  /**
   * Leaving the account-type marker behind meant a cleared order still looked
   * non-custodial to the pay page and the poll endpoint, but had no invoice.
   */
  public function testClearRemovesEverythingIncludingTheAccountTypeMarker(): void {
    $this->repository->store($this->order, $this->invoice());
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
      ])
    );
    $this->repository->markSettled($this->order, str_repeat('ab', 32), 'ff00');
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
   * What a foreground check is allowed to record: proof the endpoint answered,
   * without spending any of the background job's budget.
   */
  public function testRecordingReachabilityClearsErrorsWithoutSpendingAnAttempt(): void {
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, true);

    $this->repository->recordEndpointReachable($this->order);

    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
    $this->assertSame(2, $this->repository->attempts($this->order));
  }

  public function testRecordingReachabilityWithNothingToClearDoesNotTouchTheOrder(): void {
    $saves = $this->order->saves;

    $this->repository->recordEndpointReachable($this->order);

    $this->assertSame(
      $saves,
      $this->order->saves,
      'no write when there is nothing to clear'
    );
    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
  }

  /**
   * The latch that stops the scheduler tick and the browser poll both applying
   * the same payment.
   */
  public function testSettlementIsRecordedExactlyOnce(): void {
    $this->assertTrue(
      $this->repository->markSettled($this->order, str_repeat('ab', 32), 'ff00')
    );
    $this->assertFalse(
      $this->repository->markSettled($this->order, str_repeat('ab', 32), 'ff00')
    );
    $this->assertFalse(
      $this->repository->markSettled(
        $this->order,
        str_repeat('ab', 32),
        'different'
      )
    );
  }

  public function testSettlementRecordsWhenItHappenedAndThePreimage(): void {
    $this->repository->markSettled($this->order, str_repeat('ab', 32), 'ff00');

    $this->assertSame(self::NOW, $this->order->meta[InvoiceRepository::SETTLED_AT]);
    $this->assertSame(
      str_repeat('ab', 32),
      $this->repository->settledPaymentHash($this->order)
    );
    $this->assertSame('ff00', $this->order->meta[InvoiceRepository::PREIMAGE]);
  }

  public function testSettlementWithoutAPreimageStoresNoPreimage(): void {
    $this->repository->markSettled($this->order, str_repeat('ab', 32), null);

    $this->assertArrayNotHasKey(InvoiceRepository::PREIMAGE, $this->order->meta);
    $this->assertSame(self::NOW, $this->order->meta[InvoiceRepository::SETTLED_AT]);
  }

  public function testEmptyPreimageIsNotStored(): void {
    $this->repository->markSettled($this->order, str_repeat('ab', 32), '');

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

  /**
   * The safeguard was defeated for any store priced in bitcoin: the old fixed
   * tolerance of 0.00001 is close to a thousand satoshis, so an edit well
   * inside it let the order complete on the invoice for the lower amount.
   */
  public function testASmallChangeToABitcoinTotalIsDetected(): void {
    $this->order->setTotal('0.00001900')->setCurrency('BTC');

    $this->assertFalse(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderTotal' => '0.00001000', 'orderCurrency' => 'BTC'])
      )
    );
  }

  public function testTrailingZerosOnABitcoinTotalAreStillNotAChange(): void {
    $this->order->setTotal('0.00001')->setCurrency('BTC');

    $this->assertTrue(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderTotal' => '0.00001000', 'orderCurrency' => 'BTC'])
      )
    );
  }

  public function testAWholeNumberTotalMatchesItsPaddedForm(): void {
    $this->order->setTotal('10.000');

    $this->assertTrue(
      $this->repository->totalsUnchanged(
        $this->order,
        $this->invoice(['orderTotal' => '10'])
      )
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
