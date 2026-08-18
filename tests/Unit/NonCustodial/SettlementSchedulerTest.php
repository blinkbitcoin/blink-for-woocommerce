<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\PollBudget;
use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\Support\RandomJitter;
use Blink\WC\Tests\Support\Fake\ArrayLock;
use Blink\WC\Tests\Support\Fake\ArrayRateLimiter;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeDnsResolver;
use Blink\WC\Tests\Support\Fake\FakeHttpClient;
use Blink\WC\Tests\Support\Fake\FakeOrder;
use Blink\WC\Tests\Support\Fake\FakeRandomSource;
use Blink\WC\Tests\Support\Fake\FakeScheduler;
use Blink\WC\Tests\Support\Fake\RecordingOutcomeApplier;
use Blink\WC\Tests\Support\Fake\SpyLogger;
use PHPUnit\Framework\TestCase;

final class SettlementSchedulerTest extends TestCase {
  private const NOW = 1700000000;

  private string $preimage;
  private string $paymentHash;

  private FakeClock $clock;
  private FakeHttpClient $http;
  private FakeScheduler $scheduler;
  private InvoiceRepository $repository;
  private SettlementService $settlement;
  private SpyLogger $log;
  private FakeOrder $order;
  private RecordingOutcomeApplier $outcomeApplier;

  protected function setUp(): void {
    parent::setUp();
    $this->preimage = str_repeat('ab', 32);
    $this->paymentHash = hash('sha256', (string) hex2bin($this->preimage));

    $this->clock = new FakeClock(self::NOW);
    $this->http = new FakeHttpClient();
    $this->log = new SpyLogger();
    $this->scheduler = new FakeScheduler();
    $this->repository = new InvoiceRepository($this->clock);
    $this->order = new FakeOrder(42, '10.00', 'USD');
    $this->outcomeApplier = new RecordingOutcomeApplier();

    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $this->settlement = new SettlementService(
      new LnurlClient($this->http, $policy, $this->log),
      $this->repository,
      new PollBudget(new ArrayRateLimiter($this->clock)),
      new ArrayLock($this->clock),
      $this->clock,
      $this->log
    );
  }

  /** Jitter is scripted at its midpoint so scheduled times are exact. */
  private function makeScheduler(array $draws = null): SettlementScheduler {
    return new SettlementScheduler(
      $this->scheduler,
      $this->settlement,
      $this->repository,
      $this->outcomeApplier,
      $this->clock,
      new RandomJitter(new FakeRandomSource($draws ?? array_fill(0, 50, 0.5))),
      $this->log
    );
  }

  private function storeInvoice(int $expiresIn = 3600): StoredInvoice {
    $invoice = new StoredInvoice(
      $this->paymentHash,
      'lnbc100u1xyz',
      'https://blink.sv/verify/' . $this->paymentHash,
      'shop@blink.sv',
      10000000,
      10000,
      self::NOW,
      self::NOW + $expiresIn,
      '10.00',
      'USD'
    );
    $this->repository->store($this->order, $invoice);

    return $invoice;
  }

  public function testAnInvoiceSchedulesItsFirstCheck(): void {
    $invoice = $this->storeInvoice();

    $this->makeScheduler()->onInvoiceCreated($this->order, $invoice);

    $this->assertCount(1, $this->scheduler->scheduled);
    $this->assertSame(self::NOW + 20, $this->scheduler->scheduled[0]['timestamp']);
    $this->assertSame(SettlementScheduler::HOOK, $this->scheduler->scheduled[0]['hook']);
    $this->assertSame([42], $this->scheduler->scheduled[0]['args']);
    $this->assertSame(
      SettlementScheduler::GROUP,
      $this->scheduler->scheduled[0]['group']
    );
  }

  /** A replaced invoice must not leave the old one's checks running. */
  public function testCreatingAnInvoiceClearsAnyPreviousSchedule(): void {
    $invoice = $this->storeInvoice();
    $scheduler = $this->makeScheduler();

    $scheduler->onInvoiceCreated($this->order, $invoice);
    $scheduler->onInvoiceCreated($this->order, $invoice);

    $this->assertCount(2, $this->scheduler->unscheduled);
    $this->assertCount(1, $this->scheduler->scheduled, 'only the newest check survives');
  }

  public function testWithoutASchedulerNothingIsScheduledAndNothingFails(): void {
    $this->scheduler = new FakeScheduler(available: false);
    $invoice = $this->storeInvoice();

    $this->makeScheduler()->onInvoiceCreated($this->order, $invoice);

    $this->assertSame([], $this->scheduler->scheduled);
    $this->assertTrue($this->log->hasMessageContaining('No background scheduler'));
  }

  /**
   * The whole point of this class: settlement happens with no browser
   * involved at all.
   */
  public function testAnUnattendedOrderSettlesAcrossSeveralChecks(): void {
    $invoice = $this->storeInvoice();
    $scheduler = $this->makeScheduler();
    $scheduler->onInvoiceCreated($this->order, $invoice);

    $this->http->queueJson(['settled' => false]);
    $this->clock->travel(20);
    $this->assertTrue($scheduler->tick($this->order));

    $this->http->queueJson(['settled' => false]);
    $this->clock->travel(25);
    $this->assertTrue($scheduler->tick($this->order));

    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->clock->travel(45);
    $this->assertFalse(
      $scheduler->tick($this->order),
      'a settled order needs no further checks'
    );

    $this->assertSame(
      SettlementStatus::Paid,
      $this->repository->terminalStatus($this->order)
    );
  }

  public function testChecksFollowAnEscalatingSchedule(): void {
    $invoice = $this->storeInvoice();
    $scheduler = $this->makeScheduler();
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    $scheduler->onInvoiceCreated($this->order, $invoice);
    $times = [$this->scheduler->scheduled[0]['timestamp'] - self::NOW];

    foreach ([20, 45, 90, 180] as $elapsed) {
      $this->clock->freezeAt(self::NOW + $elapsed);
      $scheduler->tick($this->order);
      $latest = end($this->scheduler->scheduled);
      $times[] = $latest['timestamp'] - self::NOW;
    }

    $this->assertSame([20, 45, 90, 180, 300], $times);
  }

  public function testTheScheduleContinuesAtAFixedIntervalOnceExhausted(): void {
    $invoice = $this->storeInvoice(7200);
    $scheduler = $this->makeScheduler();
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    $this->clock->freezeAt(self::NOW + 3480);
    $scheduler->tick($this->order);

    $latest = end($this->scheduler->scheduled);
    $this->assertSame(self::NOW + 3480 + 300, $latest['timestamp']);
  }

  public function testChecksAreJitteredAroundTheirNominalTime(): void {
    $invoice = $this->storeInvoice();

    $early = $this->makeScheduler([0.0]);
    $early->onInvoiceCreated($this->order, $invoice);
    $this->assertSame(self::NOW + 15, $this->scheduler->scheduled[0]['timestamp']);

    $this->scheduler = new FakeScheduler();
    $late = $this->makeScheduler([1.0]);
    $late->onInvoiceCreated($this->order, $invoice);
    $this->assertSame(self::NOW + 25, $this->scheduler->scheduled[0]['timestamp']);
  }

  /**
   * The last check is pulled back to the deadline rather than being skipped,
   * so the order is always resolved rather than left pending forever.
   */
  public function testTheFinalCheckIsClampedToTheExpiryDeadline(): void {
    $invoice = $this->storeInvoice(60);
    $scheduler = $this->makeScheduler();
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));
    $deadline = self::NOW + 60 + SettlementService::EXPIRY_GRACE_SECONDS + 1;

    // Inside the grace window, so the poll still runs, but the next nominal
    // check (180s) would fall well beyond the deadline.
    $this->clock->freezeAt(self::NOW + 60 + SettlementService::EXPIRY_GRACE_SECONDS - 1);
    $this->assertTrue($scheduler->tick($this->order));

    $latest = end($this->scheduler->scheduled);
    $this->assertSame($deadline, $latest['timestamp']);

    // And that final check resolves the order instead of scheduling more.
    $this->clock->freezeAt($deadline);
    $this->assertFalse($scheduler->tick($this->order));
    $this->assertSame(
      SettlementStatus::Expired,
      $this->repository->terminalStatus($this->order)
    );
  }

  public function testAScheduledCheckIsNeverDueInThePast(): void {
    $invoice = $this->storeInvoice(7200);
    $scheduler = $this->makeScheduler(array_fill(0, 10, 0.0));
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    // Well past the last scheduled offset, with jitter pulling it earlier.
    $this->clock->freezeAt(self::NOW + 3600);
    $scheduler->tick($this->order);

    $latest = end($this->scheduler->scheduled);
    $this->assertGreaterThan($this->clock->now(), $latest['timestamp']);
  }

  public function testAnExpiredOrderStopsBeingChecked(): void {
    $invoice = $this->storeInvoice(60);
    $scheduler = $this->makeScheduler();
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertFalse($scheduler->tick($this->order));
    $this->assertSame(
      SettlementStatus::Expired,
      $this->repository->terminalStatus($this->order)
    );
  }

  public function testAnAlreadyResolvedOrderIsNotChecked(): void {
    $this->storeInvoice();
    $this->repository->markTerminal($this->order, SettlementStatus::Paid);

    $this->assertFalse($this->makeScheduler()->tick($this->order));
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testACustodialOrderIsIgnored(): void {
    $this->assertFalse($this->makeScheduler()->tick(new FakeOrder(7)));
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testAnOrderWithoutAnInvoiceIsIgnored(): void {
    $this->order->setMeta(
      InvoiceRepository::ACCOUNT_TYPE,
      InvoiceRepository::ACCOUNT_TYPE_NON_CUSTODIAL
    );

    $this->assertFalse($this->makeScheduler()->tick($this->order));
    $this->assertSame(0, $this->http->requestCount());
  }

  /** A shop whose endpoint is down gets orders needing attention, not cancelled orders. */
  public function testCheckingStopsAfterRepeatedFailuresWithoutChangingTheOrder(): void {
    $this->storeInvoice();
    $scheduler = $this->makeScheduler();
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    $result = true;
    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS; $i++) {
      $this->clock->travel(30);
      $result = $scheduler->tick($this->order);
    }

    $this->assertFalse($result);
    $this->assertNull($this->repository->terminalStatus($this->order));
    $this->assertTrue($this->log->hasMessageContaining('giving up', 'error'));
  }

  public function testCancellingRemovesScheduledChecks(): void {
    $invoice = $this->storeInvoice();
    $scheduler = $this->makeScheduler();
    $scheduler->onInvoiceCreated($this->order, $invoice);

    $scheduler->cancel(42);

    $this->assertSame([], $this->scheduler->scheduled);
  }

  public function testArgumentsAreASingleOrderIdSoUnschedulingMatches(): void {
    $this->assertSame([42], SettlementScheduler::argsFor(42));
  }
}
