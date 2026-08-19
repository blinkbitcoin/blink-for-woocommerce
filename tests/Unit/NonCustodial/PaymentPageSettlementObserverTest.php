<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\CachedPaymentPageSettlementObserver;
use Blink\WC\NonCustodial\FixedSettlementModeProvider;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\LivePaymentPageSettlementObserver;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\PollBudget;
use Blink\WC\NonCustodial\SettlementMode;
use Blink\WC\NonCustodial\SettlementOutcome;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\Tests\Support\Fake\ArrayLock;
use Blink\WC\Tests\Support\Fake\ArrayRateLimiter;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\FakeOrder;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

final class PaymentPageSettlementObserverTest extends TestCase {
  private const NOW = 1700000000;

  private FakeClock $clock;
  private FakeHttpClient $http;
  private FakeOrder $order;
  private InvoiceRepository $repository;
  private SettlementService $settlement;
  private PollBudget $budget;
  private ArrayLock $lock;

  protected function setUp(): void {
    $this->clock = new FakeClock(self::NOW);
    $this->http = new FakeHttpClient();
    $this->order = new FakeOrder(42);
    $this->repository = new InvoiceRepository($this->clock);
    $limiter = new ArrayRateLimiter($this->clock);
    $this->budget = new PollBudget($limiter);
    $this->lock = new ArrayLock($this->clock);
    $log = new SpyLogger();
    $lnurl = new LnurlClient(
      $this->http,
      new UrlPolicy((new FakeDnsResolver())->fallbackTo('93.184.216.34'), $log),
      $log
    );
    $this->settlement = new SettlementService(
      $lnurl,
      $this->repository,
      $this->budget,
      $this->lock,
      $this->clock,
      $log
    );

    $hash = str_repeat('ab', 32);
    $this->repository->store(
      $this->order,
      new StoredInvoice(
        $hash,
        'lnbc100u1xyz',
        'https://blink.sv/verify/' . $hash,
        'shop@blink.sv',
        10000000,
        10000,
        self::NOW,
        self::NOW + 3600,
        '10.00',
        'USD'
      )
    );
  }

  public function testModesExposeOnlyTheirTriggerChoices(): void {
    $this->assertSame('browser_only', SettlementMode::BrowserOnly->value);
    $this->assertSame('hybrid', SettlementMode::Hybrid->value);
    $this->assertSame('worker_only', SettlementMode::WorkerOnly->value);
    $this->assertFalse(SettlementMode::BrowserOnly->usesBackgroundWorker());
    $this->assertTrue(SettlementMode::BrowserOnly->allowsPaymentPageVerification());
    $this->assertTrue(SettlementMode::Hybrid->usesBackgroundWorker());
    $this->assertTrue(SettlementMode::Hybrid->allowsPaymentPageVerification());
    $this->assertTrue(SettlementMode::WorkerOnly->usesBackgroundWorker());
    $this->assertFalse(SettlementMode::WorkerOnly->allowsPaymentPageVerification());

    $provider = new FixedSettlementModeProvider(SettlementMode::Hybrid);
    $this->assertSame(SettlementMode::Hybrid, $provider->mode());
  }

  public function testLiveObserverChecksAStaleStatusAndThenUsesItsFreshCache(): void {
    $observer = new LivePaymentPageSettlementObserver($this->settlement, $this->budget);
    $this->http->queueJson(['settled' => false]);

    $first = $observer->observe($this->order, '198.51.100.7');
    $second = $observer->observe($this->order, '198.51.100.7');

    $this->assertSame(SettlementStatus::Pending, $first->status);
    $this->assertSame(SettlementStatus::Pending, $second->status);
    $this->assertSame(1, $this->http->requestCount());
  }

  public function testLiveObserverFallsBackToCacheWhenTheLockIsHeld(): void {
    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Pending, self::NOW - 60)
    );
    $this->assertNotNull($this->lock->acquire('verify_42', SettlementService::LOCK_TTL));
    $observer = new LivePaymentPageSettlementObserver($this->settlement, $this->budget);

    $outcome = $observer->observe($this->order, '198.51.100.7');

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testCachedObserverCanNeverInitiateARequest(): void {
    $this->repository->cacheStatus(
      $this->order,
      new SettlementOutcome(SettlementStatus::Paid, self::NOW, 'settled', true)
    );
    $observer = new CachedPaymentPageSettlementObserver($this->settlement);

    $outcome = $observer->observe($this->order, '198.51.100.7');

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertSame(0, $this->http->requestCount());
  }
}
