<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\LockInterface;

/**
 * In-memory lock with the same expiry semantics as DbLock.
 *
 * Single-flight behaviour is a property of the caller, not of the storage, so
 * exercising it does not need a database -- only a lock that refuses a second
 * holder and expires on a clock the test controls.
 */
final class ArrayLock implements LockInterface {
  /** @var array<string,array{expiresAt:int,token:string}> */
  private array $locks = [];

  public int $acquireCalls = 0;
  public int $releaseCalls = 0;

  public function __construct(private ClockInterface $clock) {}

  public function acquire(string $key, int $ttl): ?string {
    $this->acquireCalls++;
    $held = $this->locks[$key] ?? null;

    if ($held !== null && $held['expiresAt'] > $this->clock->now()) {
      return null;
    }

    $token = bin2hex(random_bytes(8));
    $this->locks[$key] = ['expiresAt' => $this->clock->now() + $ttl, 'token' => $token];

    return $token;
  }

  public function release(string $key, string $token): void {
    $this->releaseCalls++;
    if (($this->locks[$key]['token'] ?? null) === $token) {
      unset($this->locks[$key]);
    }
  }

  public function collectGarbage(): void {
    foreach ($this->locks as $key => $lock) {
      if ($lock['expiresAt'] <= $this->clock->now()) {
        unset($this->locks[$key]);
      }
    }
  }

  public function isHeld(string $key): bool {
    $held = $this->locks[$key] ?? null;

    return $held !== null && $held['expiresAt'] > $this->clock->now();
  }

  public function ttlOf(string $key): ?int {
    $held = $this->locks[$key] ?? null;

    return $held === null ? null : $held['expiresAt'] - $this->clock->now();
  }

  /** @return list<string> */
  public function keys(): array {
    return array_keys($this->locks);
  }
}
