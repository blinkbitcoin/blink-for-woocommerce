<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\InvoiceReusePolicy;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\Tests\Support\Fake\FakeClock;
use Blink\WC\Tests\Support\Fake\FakeOrder;
use PHPUnit\Framework\TestCase;

/**
 * The decision behind every repeat visit to checkout: pay the invoice this
 * order already has, or make a new one.
 *
 * These branches used to live on BlinkLnGateway, where the coverage gate
 * measures lines but not branches. They are here so the gate can hold them.
 */
final class InvoiceReusePolicyTest extends TestCase {
  private const NOW = 1700000000;

  private FakeClock $clock;
  private InvoiceRepository $repository;
  private InvoiceReusePolicy $policy;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FakeClock(self::NOW);
    $this->repository = new InvoiceRepository($this->clock);
    $this->policy = new InvoiceReusePolicy($this->repository, $this->clock);
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

  public function testAFreshInvoiceOnAnUnchangedOrderIsReused(): void {
    $order = new FakeOrder(42, '10.00', 'USD');
    $this->repository->store($order, $this->invoice());

    $this->assertTrue($this->policy->isReusable($order));
  }

  public function testAnOrderWithNoInvoiceNeedsANewOne(): void {
    $this->assertFalse($this->policy->isReusable(new FakeOrder(42, '10.00', 'USD')));
  }

  /**
   * A terminal status means this order is done being paid -- expired, or
   * settled and applied. Showing the old QR again invites a second payment.
   */
  public function testATerminalOrderIsNeverReused(): void {
    $order = new FakeOrder(42, '10.00', 'USD');
    $this->repository->store($order, $this->invoice());
    $this->repository->markTerminal($order, SettlementStatus::Expired);

    $this->assertFalse($this->policy->isReusable($order));
  }

  public function testAnInvoiceAboutToExpireIsReplaced(): void {
    $order = new FakeOrder(42, '10.00', 'USD');
    $this->repository->store($order, $this->invoice(['expiresAt' => self::NOW + 60]));

    $this->assertFalse($this->policy->isReusable($order));
  }

  /**
   * The boundary is worth pinning: the rule is "fewer than MIN_SECONDS_LEFT
   * remaining", so exactly that many is still payable.
   */
  public function testAnInvoiceWithExactlyTheMinimumLeftIsStillReused(): void {
    $order = new FakeOrder(42, '10.00', 'USD');
    $this->repository->store(
      $order,
      $this->invoice(['expiresAt' => self::NOW + InvoiceReusePolicy::MIN_SECONDS_LEFT])
    );

    $this->assertTrue($this->policy->isReusable($order));
  }

  /**
   * An invoice that never carried an expiry cannot be judged on one, and an
   * absent expiry is not a reason to throw a payable invoice away. Without
   * this case the `expiresAt > 0` half of the check is never taken false.
   */
  public function testAnInvoiceWithoutAnExpiryIsJudgedOnItsTotalsAlone(): void {
    $order = new FakeOrder(42, '10.00', 'USD');
    $this->repository->store($order, $this->invoice(['expiresAt' => 0]));

    $this->assertTrue($this->policy->isReusable($order));

    $edited = new FakeOrder(43, '25.00', 'USD');
    $this->repository->store($edited, $this->invoice(['expiresAt' => 0]));

    $this->assertFalse(
      $this->policy->isReusable($edited),
      'an order edited after the invoice was made still needs a new one'
    );
  }

  /**
   * Reusing an invoice made out for the old total would mark the order paid
   * for an amount the customer no longer owes.
   */
  public function testAnOrderEditedSinceTheInvoiceNeedsANewOne(): void {
    $order = new FakeOrder(42, '25.00', 'USD');
    $this->repository->store($order, $this->invoice(['orderTotal' => '10.00']));

    $this->assertFalse($this->policy->isReusable($order));
  }
}
