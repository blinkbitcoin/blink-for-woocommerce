<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Unit\NonCustodial;

use Blink\WC\NonCustodial\PollBudget;
use Blink\WC\Tests\Support\Fake\ArrayRateLimiter;
use Blink\WC\Tests\Support\Fake\FakeClock;
use PHPUnit\Framework\TestCase;

final class PollBudgetTest extends TestCase {
  private FakeClock $clock;
  private ArrayRateLimiter $limiter;
  private PollBudget $budget;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FakeClock(1700000000);
    $this->limiter = new ArrayRateLimiter($this->clock);
    $this->budget = new PollBudget($this->limiter);
  }

  public function testAllowsRequestsUpToThePerIpLimit(): void {
    for ($i = 0; $i < PollBudget::PER_IP_LIMIT; $i++) {
      $this->assertTrue($this->budget->allowIp('198.51.100.7'), 'hit ' . ($i + 1));
    }

    $this->assertFalse($this->budget->allowIp('198.51.100.7'), 'the limit must be a ceiling');
  }

  public function testBudgetsAreTrackedPerIpAddress(): void {
    for ($i = 0; $i < PollBudget::PER_IP_LIMIT; $i++) {
      $this->budget->allowIp('198.51.100.7');
    }

    $this->assertFalse($this->budget->allowIp('198.51.100.7'));
    $this->assertTrue(
      $this->budget->allowIp('203.0.113.9'),
      'one exhausted client must not lock everyone else out'
    );
  }

  public function testTheWindowRollsOver(): void {
    for ($i = 0; $i < PollBudget::PER_IP_LIMIT; $i++) {
      $this->budget->allowIp('198.51.100.7');
    }
    $this->assertFalse($this->budget->allowIp('198.51.100.7'));

    $this->clock->travel(PollBudget::WINDOW_SECONDS);

    $this->assertTrue($this->budget->allowIp('198.51.100.7'));
  }

  public function testAllowsRequestsUpToThePerDomainLimit(): void {
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $this->assertTrue($this->budget->allowDomain('blink.sv'));
    }

    $this->assertFalse($this->budget->allowDomain('blink.sv'));
  }

  public function testDomainBudgetsAreCaseInsensitive(): void {
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $this->budget->allowDomain('blink.sv');
    }

    $this->assertFalse(
      $this->budget->allowDomain('BLINK.SV'),
      'case must not be a way around the budget'
    );
  }

  public function testDomainBudgetsAreTrackedSeparately(): void {
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $this->budget->allowDomain('blink.sv');
    }

    $this->assertFalse($this->budget->allowDomain('blink.sv'));
    $this->assertTrue($this->budget->allowDomain('pay.example.com'));
  }

  public function testAllowsRequestsUpToTheGlobalLimit(): void {
    for ($i = 0; $i < PollBudget::GLOBAL_LIMIT; $i++) {
      $this->assertTrue($this->budget->allowGlobal());
    }

    $this->assertFalse($this->budget->allowGlobal());
  }

  public function testOutboundRequiresBothTheDomainAndGlobalBudgets(): void {
    $this->assertTrue($this->budget->allowOutbound('blink.sv'));
  }

  public function testOutboundIsRefusedOnceTheDomainBudgetIsSpent(): void {
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $this->budget->allowDomain('blink.sv');
    }

    $this->assertFalse($this->budget->allowOutbound('blink.sv'));
  }

  public function testOutboundIsRefusedOnceTheGlobalBudgetIsSpent(): void {
    for ($i = 0; $i < PollBudget::GLOBAL_LIMIT; $i++) {
      $this->budget->allowGlobal();
    }

    $this->assertFalse($this->budget->allowOutbound('a-fresh-domain.example'));
  }

  /**
   * A refused request still counts, so a client cannot keep a budget from
   * filling by being refused.
   */
  public function testARefusedOutboundRequestStillConsumesBothCounters(): void {
    for ($i = 0; $i < PollBudget::PER_DOMAIN_LIMIT; $i++) {
      $this->budget->allowDomain('blink.sv');
    }

    $this->budget->allowOutbound('blink.sv');

    // The domain loop above never touched the global counter, so exactly one
    // global slot should have been consumed -- by the refused call.
    $remaining = 0;
    while ($this->budget->allowGlobal()) {
      $remaining++;
    }

    $this->assertSame(PollBudget::GLOBAL_LIMIT - 1, $remaining);
  }
}
