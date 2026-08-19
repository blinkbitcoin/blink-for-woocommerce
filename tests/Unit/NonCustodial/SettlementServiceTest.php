<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\Http\HttpResponse;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\LnAddress;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\PollBudget;
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

final class SettlementServiceTest extends TestCase {
  private const NOW = 1700000000;

  /** sha256 of the 32-byte preimage below. */
  private string $preimage;
  private string $paymentHash;

  private FakeClock $clock;
  private FakeHttpClient $http;
  private SpyLogger $log;
  private ArrayLock $lock;
  private InvoiceRepository $repository;
  private SettlementService $service;
  private FakeOrder $order;

  protected function setUp(): void {
    parent::setUp();

    $this->preimage = str_repeat('ab', 32);
    $this->paymentHash = hash('sha256', (string) hex2bin($this->preimage));

    $this->clock = new FakeClock(self::NOW);
    $this->http = new FakeHttpClient();
    $this->log = new SpyLogger();
    $this->lock = new ArrayLock($this->clock);
    $this->repository = new InvoiceRepository($this->clock);
    $this->order = new FakeOrder(42, '10.00', 'USD');

    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $this->service = new SettlementService(
      new LnurlClient($this->http, $policy, $this->log),
      $this->repository,
      new PollBudget(new ArrayRateLimiter($this->clock)),
      $this->lock,
      $this->clock,
      $this->log
    );
  }

  private function invoice(array $overrides = []): StoredInvoice {
    return new StoredInvoice(
      $overrides['paymentHash'] ?? $this->paymentHash,
      'lnbc100u1xyz',
      $overrides['verifyUrl'] ??
        'https://blink.sv/verify/' . ($overrides['paymentHash'] ?? $this->paymentHash),
      $overrides['lnAddress'] ?? 'shop@blink.sv',
      10000000,
      10000,
      self::NOW,
      $overrides['expiresAt'] ?? self::NOW + 3600,
      $overrides['orderTotal'] ?? '10.00',
      $overrides['orderCurrency'] ?? 'USD'
    );
  }

  private function storeInvoice(array $overrides = []): StoredInvoice {
    $invoice = $this->invoice($overrides);
    $this->repository->store($this->order, $invoice);

    return $invoice;
  }

  // -------------------------------------------------------------- settlement

  public function testASettledInvoiceMarksTheOrderPaid(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertTrue($outcome->terminal);
    $this->assertSame(
      SettlementStatus::Paid,
      $this->repository->terminalStatus($this->order)
    );
  }

  public function testAnUnsettledInvoiceStaysPending(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertFalse($outcome->terminal);
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testSettlementIsAcceptedWithoutAPreimage(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => true]);

    $this->assertSame(SettlementStatus::Paid, $this->service->poll($this->order)->status);
  }

  public function testAReplacedInvoiceCanStillSettleTheOrder(): void {
    $previous = $this->storeInvoice();
    $current = $this->invoice([
      'paymentHash' => str_repeat('cd', 32),
      'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32)
    ]);
    $this->repository->replace($this->order, $current);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertSame(
      $previous->paymentHash,
      $this->repository->settledPaymentHash($this->order)
    );
    $this->assertStringContainsString(
      $previous->paymentHash,
      (string) $this->http->lastUrl()
    );
  }

  public function testPaymentToAReplacedInvoiceForAnOldTotalIsHeldForReview(): void {
    $previous = $this->storeInvoice();
    $this->order->setTotal('25.00');
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
        'orderTotal' => '25.00'
      ])
    );
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Review, $outcome->status);
    $this->assertSame(
      $previous->paymentHash,
      $this->repository->settledPaymentHash($this->order)
    );
  }

  public function testCurrentInvoiceCanSettleWhileAReplacedInvoiceIsUncertain(): void {
    $this->storeInvoice();
    $currentPreimage = str_repeat('cd', 32);
    $currentHash = hash('sha256', (string) hex2bin($currentPreimage));
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => $currentHash,
        'verifyUrl' => 'https://blink.sv/verify/' . $currentHash
      ])
    );
    $this->http->queue(HttpResponse::transportFailure('old endpoint down'));
    $this->http->queueJson(['settled' => true, 'preimage' => $currentPreimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertSame($currentHash, $this->repository->settledPaymentHash($this->order));
    $this->assertSame(2, $this->http->requestCount());
  }

  public function testUncertainReplacedInvoicePreventsFalseExpiry(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
        'expiresAt' => self::NOW + 60
      ])
    );
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queue(HttpResponse::transportFailure('old endpoint down'));
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertNull($this->repository->terminalStatus($this->order));
    $this->assertCount(1, $this->repository->outstanding($this->order));
  }

  public function testDefinitivelyUnpaidReplacedInvoiceIsPruned(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32)
      ])
    );
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertSame([], $this->repository->outstanding($this->order));
  }

  public function testOrderExpiresOnlyAfterEveryTrackedInvoiceIsUnpayable(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32),
        'expiresAt' => self::NOW + 60
      ])
    );
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Expired, $outcome->status);
    $this->assertSame([], $this->repository->outstanding($this->order));
  }

  public function testSeveralInvoicesConsumeOneBackgroundAttemptPerCycle(): void {
    $this->storeInvoice();
    $this->repository->replace(
      $this->order,
      $this->invoice([
        'paymentHash' => str_repeat('cd', 32),
        'verifyUrl' => 'https://blink.sv/verify/' . str_repeat('cd', 32)
      ])
    );
    $this->http->queueJson(['settled' => false]);
    $this->http->queueJson(['settled' => false]);

    $this->service->pollAsBackgroundCheck($this->order);

    $this->assertSame(2, $this->http->requestCount());
    $this->assertSame(1, $this->repository->attempts($this->order));
  }

  /**
   * A preimage is a proof, so a wrong one means something is badly wrong and
   * the order must not be completed on that server's say-so.
   */
  public function testASettledClaimWithAMismatchedPreimageIsRefused(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => true, 'preimage' => str_repeat('cd', 32)]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertSame('preimage mismatch', $outcome->reason);
    $this->assertNull($this->repository->terminalStatus($this->order));
    $this->assertTrue($this->log->hasMessageContaining('does not hash', 'error'));
  }

  public function testAnUnparsablePreimageIsRefused(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => true, 'preimage' => 'not-hex']);

    $this->assertSame(
      SettlementStatus::Pending,
      $this->service->poll($this->order)->status
    );
  }

  /**
   * The scheduler tick and the browser poll can both observe the same payment.
   */
  public function testSettlementIsIdempotent(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(
      new HttpResponse(
        200,
        (string) json_encode(['settled' => true, 'preimage' => $this->preimage])
      )
    );

    $first = $this->service->poll($this->order);
    $second = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $first->status);
    $this->assertSame('settled', $first->reason);
    $this->assertSame(SettlementStatus::Paid, $second->status);
    $this->assertSame(
      'already resolved',
      $second->reason,
      'the terminal marker short-circuits'
    );
  }

  public function testAResolvedOrderIsNotPolledAgain(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);
    $this->service->poll($this->order);

    $requestsBefore = $this->http->requestCount();
    $this->service->poll($this->order);

    $this->assertSame(
      $requestsBefore,
      $this->http->requestCount(),
      'no further requests'
    );
  }

  /** An order edited after the invoice was made must not be auto-completed. */
  public function testATotalChangedAfterInvoiceCreationHoldsTheOrderForReview(): void {
    $this->storeInvoice();
    $this->order->setTotal('99.00');
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Review, $outcome->status);
    $this->assertTrue($outcome->terminal);
    $this->assertSame(
      SettlementStatus::Review,
      $this->repository->terminalStatus($this->order)
    );
    $this->assertTrue($this->log->hasMessageContaining('holding for review', 'error'));
  }

  public function testAChangedCurrencyAlsoHoldsForReview(): void {
    $this->storeInvoice();
    $this->order->setCurrency('EUR');
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $this->assertSame(
      SettlementStatus::Review,
      $this->service->poll($this->order)->status
    );
  }

  // ------------------------------------------------------------------ expiry

  public function testAnExpiredInvoiceRequiresACurrentUnpaidResponse(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Expired, $outcome->status);
    $this->assertTrue($outcome->terminal);
    $this->assertSame(1, $this->http->requestCount());
  }

  public function testADelayedFirstCheckCanStillDiscoverPaymentAfterTheDeadline(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertTrue($outcome->terminal);
    $this->assertSame(1, $this->http->requestCount());
  }

  public function testAnEarlierUnpaidObservationCannotExpireALaterPayment(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->http->queueJson(['settled' => false]);
    $this->service->poll($this->order);

    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertSame(2, $this->http->requestCount());
  }

  public function testTheGracePeriodIsHonouredBeforeExpiring(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS - 1);
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertSame(1, $this->http->requestCount(), 'it should still look once more');
  }

  public function testAnInvoiceWithoutAnExpiryRemainsPendingWhenUnsettled(): void {
    $this->storeInvoice(['expiresAt' => 0]);
    $this->clock->travel(86400);
    $this->http->queueJson(['settled' => false]);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  /**
   * The single most important behaviour in this class. A verify endpoint that
   * is unreachable around expiry must never cause an order the customer paid
   * for to be cancelled.
   *
   * @dataProvider inconclusiveResponses
   */
  public function testAnInconclusiveAnswerNeverExpiresAnOrder(callable $queue): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $queue($this->http);

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  /** @return array<string,array{callable}> */
  public static function inconclusiveResponses(): array {
    return [
      'connection timeout' => [
        static fn(FakeHttpClient $h) => $h->queue(
          HttpResponse::transportFailure('timeout')
        )
      ],
      'server error' => [
        static fn(FakeHttpClient $h) => $h->queue(new HttpResponse(500, ''))
      ],
      'bad gateway' => [
        static fn(FakeHttpClient $h) => $h->queue(new HttpResponse(502, ''))
      ],
      'malformed body' => [
        static fn(FakeHttpClient $h) => $h->queue(new HttpResponse(200, '{'))
      ],
      'oversized body' => [
        static fn(FakeHttpClient $h) => $h->queue(
          new HttpResponse(200, '{}', [], null, true)
        )
      ]
    ];
  }

  /**
   * The reviewer's scenario: verification is unavailable while the invoice
   * expires, and the payment lands. It must still be recoverable.
   */
  public function testAPaymentSettledDuringAnOutageIsStillRecovered(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 300]);

    // The endpoint is down as expiry approaches.
    $this->clock->travel(295);
    $this->http->queue(HttpResponse::transportFailure('connection refused'));
    $this->assertSame(
      SettlementStatus::Pending,
      $this->service->poll($this->order)->status
    );

    // Still down just past expiry, inside the grace window.
    $this->clock->travel(30);
    $this->http->queue(new HttpResponse(503, ''));
    $this->assertSame(
      SettlementStatus::Pending,
      $this->service->poll($this->order)->status
    );
    $this->assertNull(
      $this->repository->terminalStatus($this->order),
      'the order must not have been expired while the endpoint was unreachable'
    );

    // It comes back, and reports the payment.
    $this->clock->travel(30);
    $this->http->queueJson(['settled' => true, 'preimage' => $this->preimage]);

    $this->assertSame(SettlementStatus::Paid, $this->service->poll($this->order)->status);
  }

  public function testNotFoundBeforeExpiryIsTreatedAsPending(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 3600]);
    $this->http->queue(new HttpResponse(404, ''));

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testNotFoundAfterExpiryExpiresTheOrder(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(120);
    $this->http->queue(new HttpResponse(404, ''));

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Expired, $outcome->status);
    $this->assertTrue($outcome->terminal);
  }

  // ------------------------------------------------------------ single flight

  /**
   * Models a second browser tab arriving while the first request is still in
   * flight and the lock is held. This is an exact stand-in for concurrency,
   * without needing real parallelism.
   */
  public function testOnlyOneVerifyRequestIsInFlightPerOrder(): void {
    $this->storeInvoice();
    $reentrant = null;
    $this->http->onRequest(function () use (&$reentrant): void {
      $reentrant = $this->service->poll($this->order);
    });
    $this->http->queueJson(['settled' => false]);

    $first = $this->service->poll($this->order);

    $this->assertSame(1, $this->http->requestCount(), 'the second caller must not fetch');
    $this->assertSame(SettlementStatus::Pending, $first->status);
    $this->assertSame(SettlementStatus::Unknown, $reentrant?->status);
    $this->assertStringContainsString('already in flight', (string) $reentrant?->reason);
  }

  /** A lock shorter than the request it guards would let a second caller in. */
  public function testTheLockOutlivesTheRequestItGuards(): void {
    $this->storeInvoice();
    $observed = null;
    $this->http->onRequest(function () use (&$observed): void {
      $observed = $this->lock->ttlOf('verify_42');
    });
    $this->http->queueJson(['settled' => false]);

    $this->service->poll($this->order);

    $this->assertNotNull($observed);
    $this->assertGreaterThan(10.0, $observed, 'the HTTP timeout is 10s');
    $this->assertSame(SettlementService::LOCK_TTL, $observed);
  }

  public function testTheLockIsReleasedAfterASuccessfulPoll(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => false]);

    $this->service->poll($this->order);

    $this->assertFalse($this->lock->isHeld('verify_42'));
  }

  /** A thrown failure must not wedge the order for the whole lock lifetime. */
  public function testTheLockIsReleasedEvenWhenVerifyThrows(): void {
    $this->storeInvoice();
    // No scripted response: the fake client throws on exhaustion.
    try {
      $this->service->poll($this->order);
      $this->fail('expected the fake client to throw');
    } catch (\LogicException) {
      // expected
    }

    $this->assertFalse($this->lock->isHeld('verify_42'));
  }

  public function testLocksAreScopedPerOrder(): void {
    $this->storeInvoice();
    $other = new FakeOrder(43, '10.00', 'USD');
    $this->repository->store(
      $other,
      new StoredInvoice(
        $this->paymentHash,
        'lnbc1',
        'https://blink.sv/verify/' . $this->paymentHash,
        'shop@blink.sv',
        10000000,
        10000,
        self::NOW,
        self::NOW + 3600,
        '10.00',
        'USD'
      )
    );

    $seen = null;
    $this->http->onRequest(function () use (&$seen, $other): void {
      $seen = $this->service->poll($other);
    });
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    $this->service->poll($this->order);

    $this->assertSame(
      SettlementStatus::Pending,
      $seen?->status,
      'a lock on one order must not block another'
    );
    $this->assertSame(2, $this->http->requestCount());
  }

  // ----------------------------------------------------------------- budgets

  public function testAnExhaustedOutboundBudgetYieldsUnknownRatherThanAnError(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $limiter = new ArrayRateLimiter($this->clock);
    $budget = new PollBudget($limiter);
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $budget->allowDomain('blink.sv');
    }
    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $service = new SettlementService(
      new LnurlClient($this->http, $policy, $this->log),
      $this->repository,
      $budget,
      $this->lock,
      $this->clock,
      $this->log
    );

    $outcome = $service->poll($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertSame(0, $this->http->requestCount());
  }

  public function testPollingStopsAfterTooManyConsecutiveErrors(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS; $i++) {
      $this->service->pollAsBackgroundCheck($this->order);
    }
    $requestsBefore = $this->http->requestCount();

    $outcome = $this->service->pollAsBackgroundCheck($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertStringContainsString('budget', $outcome->reason);
    $this->assertSame($requestsBefore, $this->http->requestCount());
  }

  /** An unreachable endpoint must leave orders needing attention, not cancelled. */
  public function testAnExhaustedOrderIsNotExpired(): void {
    $this->storeInvoice(['expiresAt' => self::NOW + 60]);
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS + 2; $i++) {
      $this->service->pollAsBackgroundCheck($this->order);
    }

    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $outcome = $this->service->pollAsBackgroundCheck($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertNull($this->repository->terminalStatus($this->order));
  }

  public function testOneGoodAnswerClearsTheErrorBudget(): void {
    $this->storeInvoice();
    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS - 1; $i++) {
      $this->http->queue(HttpResponse::transportFailure('down'));
      $this->service->pollAsBackgroundCheck($this->order);
    }

    $this->http->queueJson(['settled' => false]);
    $this->service->pollAsBackgroundCheck($this->order);

    $this->assertFalse($this->service->exhausted($this->order));
    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
  }

  public function testPollingStopsAfterTheAttemptCeiling(): void {
    $this->storeInvoice();
    $this->order->setMeta(InvoiceRepository::ATTEMPTS, SettlementService::MAX_ATTEMPTS);

    $outcome = $this->service->pollAsBackgroundCheck($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertSame(0, $this->http->requestCount());
  }

  // --------------------------------------------------- foreground vs background

  /**
   * The regression this split exists for. A pay page left open used to spend
   * the background job's whole budget in about twenty minutes, after which the
   * scheduler stopped rescheduling and a payment made later was never seen.
   */
  public function testForegroundPollsNeverSpendTheBackgroundBudget(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(new HttpResponse(200, '{"settled":false}'));

    for ($i = 0; $i < SettlementService::MAX_ATTEMPTS + 10; $i++) {
      $this->clock->travel(SettlementService::CACHE_FRESH_SECONDS + 1);
      $this->service->poll($this->order);
    }

    $this->assertSame(0, $this->repository->attempts($this->order));
    $this->assertFalse($this->service->exhausted($this->order));
    $this->assertGreaterThan(
      SettlementService::MAX_ATTEMPTS,
      $this->http->requestCount(),
      'the checks really were made'
    );
  }

  /**
   * The tighter of the two ceilings, and the same bug: eight failures from a
   * watching browser used to end background settlement in under three minutes.
   */
  public function testForegroundFailuresDoNotCountTowardsGivingUp(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));

    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS + 5; $i++) {
      $this->service->poll($this->order);
    }

    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
    $this->assertFalse($this->service->exhausted($this->order));
  }

  /**
   * The asymmetry above was unreachable once the budget was actually spent:
   * the ceiling sat in the shared code path, so a run of background errors
   * silenced the pay page too, and only a foreground answer could have cleared
   * it. A customer paying after the provider came back watched the page spin
   * and nobody credited the order.
   */
  public function testAnExhaustedBackgroundBudgetDoesNotSilenceThePayPage(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));
    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS; $i++) {
      $this->service->pollAsBackgroundCheck($this->order);
    }
    $this->assertTrue($this->service->exhausted($this->order));

    $this->http->alwaysRespond(
      new HttpResponse(
        200,
        json_encode(['settled' => true, 'preimage' => $this->preimage])
      )
    );
    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Paid, $outcome->status);
    $this->assertSame(
      0,
      $this->repository->consecutiveErrors($this->order),
      'the answer also proved the endpoint is reachable again'
    );
  }

  public function testAnExhaustedBackgroundBudgetStillStopsTheBackgroundJob(): void {
    $this->storeInvoice();
    $this->http->alwaysRespond(HttpResponse::transportFailure('down'));
    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS; $i++) {
      $this->service->pollAsBackgroundCheck($this->order);
    }
    $requestsBefore = $this->http->requestCount();

    $outcome = $this->service->pollAsBackgroundCheck($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertSame($requestsBefore, $this->http->requestCount());
  }

  /**
   * The deliberate asymmetry: a foreground check may not spend the budget, but
   * it may prove the endpoint came back, which can only delay giving up.
   */
  public function testAForegroundAnswerClearsErrorsTheBackgroundAccrued(): void {
    $this->storeInvoice();
    for ($i = 0; $i < SettlementService::MAX_CONSECUTIVE_ERRORS - 1; $i++) {
      $this->http->queue(HttpResponse::transportFailure('down'));
      $this->service->pollAsBackgroundCheck($this->order);
    }
    $this->assertSame(
      SettlementService::MAX_CONSECUTIVE_ERRORS - 1,
      $this->repository->consecutiveErrors($this->order)
    );

    $attemptsBefore = $this->repository->attempts($this->order);
    $this->http->queueJson(['settled' => false]);
    $this->service->poll($this->order);

    $this->assertSame(0, $this->repository->consecutiveErrors($this->order));
    $this->assertSame(
      $attemptsBefore,
      $this->repository->attempts($this->order),
      'clearing the error count must not also spend an attempt'
    );
  }

  public function testAForegroundFailureLeavesAnExistingErrorCountAlone(): void {
    $this->storeInvoice();
    $this->http->queue(HttpResponse::transportFailure('down'));
    $this->service->pollAsBackgroundCheck($this->order);

    $this->http->queue(HttpResponse::transportFailure('down'));
    $this->service->poll($this->order);

    $this->assertSame(1, $this->repository->consecutiveErrors($this->order));
  }

  // ------------------------------------------------------------------- cache

  public function testTheLastObservationIsCached(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => false]);
    $this->service->poll($this->order);

    $cached = $this->service->cached($this->order);

    $this->assertSame(SettlementStatus::Pending, $cached->status);
    $this->assertSame(self::NOW, $cached->observedAt);
  }

  public function testAFreshCacheIsRecognised(): void {
    $this->storeInvoice();
    $this->http->queueJson(['settled' => false]);
    $this->service->poll($this->order);

    $this->assertTrue($this->service->isCacheFresh($this->order));

    $this->clock->travel(SettlementService::CACHE_FRESH_SECONDS);

    $this->assertFalse($this->service->isCacheFresh($this->order));
  }

  public function testNoCacheIsNeverFresh(): void {
    $this->storeInvoice();

    $this->assertFalse($this->service->isCacheFresh($this->order));
  }

  public function testCachedFallsBackToPendingWhenNothingWasObserved(): void {
    $this->storeInvoice();

    $this->assertSame(
      SettlementStatus::Pending,
      $this->service->cached($this->order)->status
    );
  }

  // ------------------------------------------------------------- degenerate

  public function testAnOrderWithNoInvoiceYieldsUnknown(): void {
    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertSame('no invoice stored', $outcome->reason);
  }

  public function testAnUnusableStoredAddressYieldsUnknown(): void {
    $this->storeInvoice();
    $this->order->setMeta(InvoiceRepository::LN_ADDRESS, 'not an address');

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Unknown, $outcome->status);
    $this->assertStringContainsString('unusable', $outcome->reason);
  }

  /** Settlement follows the order's stored address, not the shop's current one. */
  public function testTheStoredAddressIsUsedRatherThanCurrentConfiguration(): void {
    $this->storeInvoice(['lnAddress' => 'other@pay.example.com']);
    $policy = new UrlPolicy(
      (new FakeDnsResolver())->fallbackTo('93.184.216.34'),
      $this->log
    );
    $service = new SettlementService(
      new LnurlClient($this->http, $policy, $this->log),
      $this->repository,
      new PollBudget(new ArrayRateLimiter($this->clock)),
      $this->lock,
      $this->clock,
      $this->log
    );
    $this->order->setMeta(
      InvoiceRepository::VERIFY_URL,
      'https://pay.example.com/verify/' . $this->paymentHash
    );
    $this->http->queueJson(['settled' => false]);

    $outcome = $service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertStringContainsString('pay.example.com', (string) $this->http->lastUrl());
  }

  public function testAVerifyUrlRejectedByThePolicyIsPendingNotExpired(): void {
    $this->storeInvoice();
    $this->order->setMeta(
      InvoiceRepository::VERIFY_URL,
      'https://attacker.example/verify/x'
    );

    $outcome = $this->service->poll($this->order);

    $this->assertSame(SettlementStatus::Pending, $outcome->status);
    $this->assertSame(0, $this->http->requestCount());
    $this->assertNull($this->repository->terminalStatus($this->order));
  }
}
