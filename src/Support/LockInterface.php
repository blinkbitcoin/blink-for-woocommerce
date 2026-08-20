<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * A mutual-exclusion lock with a time-to-live.
 *
 * Used to keep one verify request in flight per order however many browser
 * tabs and scheduler ticks arrive at once. The TTL must outlive the HTTP
 * timeout of the request it guards, or a slow response lets a second caller
 * through while the first is still waiting.
 */
interface LockInterface {
  /**
   * Attempts to take the lock.
   *
   * @return string|null An opaque token when the lock was taken, null when
   *                     someone else holds it.
   */
  public function acquire(string $key, int $ttl): ?string;

  /** Releases the lock, but only if this token still owns it. */
  public function release(string $key, string $token): void;

  /** Removes expired lock rows. */
  public function collectGarbage(): void;
}
