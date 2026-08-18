<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\UnpaidOrderGuard;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeOrder;
use PHPUnit\Framework\TestCase;

final class UnpaidOrderGuardTest extends TestCase {
  private const NOW = 1700000000;

  private FakeClock $clock;
  private InvoiceRepository $repository;
  private FakeOrder $order;
  private UnpaidOrderGuard $guard;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FakeClock(self::NOW);
    $this->repository = new InvoiceRepository($this->clock);
    $this->order = new FakeOrder(42, '10.00', 'USD');
    $this->guard = new UnpaidOrderGuard($this->repository, $this->clock);
  }

  private function storeInvoice(int $expiresIn = 3600): StoredInvoice {
    $hash = str_repeat('ab', 32);
    $invoice = new StoredInvoice(
      $hash,
      'lnbc100u1xyz',
      'https://blink.sv/verify/' . $hash,
      'shop@blink.sv',
      10000000,
      10000,
      self::NOW,
      $expiresIn === 0 ? 0 : self::NOW + $expiresIn,
      '10.00',
      'USD'
    );
    $this->repository->store($this->order, $invoice);

    return $invoice;
  }

  /** The defect: the stock timer cancelling an invoice the customer can still pay. */
  public function testAPayableInvoiceMayNotBeAutoCancelled(): void {
    $this->storeInvoice();

    $this->assertFalse($this->guard->mayCancel($this->order, true));
  }

  public function testTheVetoHoldsThroughTheGracePeriod(): void {
    $this->storeInvoice(60);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS);

    $this->assertFalse(
      $this->guard->mayCancel($this->order, true),
      'the grace window is still part of the payable life of the invoice'
    );
  }

  /** The veto has to release itself, or an order escapes stock management forever. */
  public function testAnInvoicePastItsWindowMayBeAutoCancelledOnceItWasAnswered(): void {
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, false);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertTrue($this->guard->mayCancel($this->order, true));
    $this->assertSame([], $this->order->notes);
  }

  public function testAnInvoiceTheEndpointNeverAnsweredIsHeld(): void {
    $this->storeInvoice(60);
    // Every background check failed to reach the endpoint, so nothing is known
    // about whether the customer paid.
    $this->repository->recordAttempt($this->order, true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertFalse(
      $this->guard->mayCancel($this->order, true),
      'an order nobody could get an answer about must not be cancelled'
    );
  }

  public function testAnErrorRunFollowedByAnAnswerReleasesTheHold(): void {
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, true);
    $this->repository->recordAttempt($this->order, false);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertTrue($this->guard->mayCancel($this->order, true));
  }

  public function testAnInvoiceNeverCheckedAtAllIsHeld(): void {
    // Action Scheduler never ran, so the zero error count is an absence of
    // observations rather than a clean bill of health.
    $this->storeInvoice(60);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertFalse($this->guard->mayCancel($this->order, true));
  }

  public function testTheHeldOrderIsExplainedExactlyOnce(): void {
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->guard->mayCancel($this->order, true);
    $this->guard->mayCancel($this->order, true);
    $this->guard->mayCancel($this->order, true);

    $this->assertCount(1, $this->order->notes);
    $this->assertStringContainsString('could never be confirmed', $this->order->notes[0]);
  }

  public function testAReplacementInvoiceCanBeExplainedAgain(): void {
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);
    $this->guard->mayCancel($this->order, true);

    $this->repository->clear($this->order);
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, true);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertFalse($this->guard->mayCancel($this->order, true));
    $this->assertCount(2, $this->order->notes);
  }

  public function testAResolvedInvoiceMayBeAutoCancelled(): void {
    $this->storeInvoice();
    $this->repository->markTerminal($this->order, SettlementStatus::Expired);

    $this->assertTrue($this->guard->mayCancel($this->order, true));
  }

  /**
   * Without a recorded expiry there is no evidence the invoice is dead, and
   * settlement's own ceilings are what bound it.
   */
  public function testAnInvoiceWithoutAnExpiryIsTreatedAsStillPayable(): void {
    $this->storeInvoice(0);

    $this->assertFalse($this->guard->mayCancel($this->order, true));
  }

  public function testACustodialOrderIsLeftToWooCommerce(): void {
    $this->assertTrue($this->guard->mayCancel($this->order, true));
  }

  public function testANonCustodialOrderWithNoInvoiceIsLeftToWooCommerce(): void {
    $this->order->setMeta(
      InvoiceRepository::ACCOUNT_TYPE,
      InvoiceRepository::ACCOUNT_TYPE_NON_CUSTODIAL
    );

    $this->assertTrue($this->guard->mayCancel($this->order, true));
  }

  /**
   * The filter may only ever narrow WooCommerce's decision. Forcing a
   * cancellation it did not want would override rules that are none of this
   * plugin's business -- an order created by a subscription renewal, say.
   */
  public function testADecisionNotToCancelIsNeverOverturned(): void {
    $this->storeInvoice(60);
    $this->repository->recordAttempt($this->order, false);
    $this->clock->travel(60 + SettlementService::EXPIRY_GRACE_SECONDS + 1);

    $this->assertFalse($this->guard->mayCancel($this->order, false));
  }
}
