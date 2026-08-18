<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * A lock built on the UNIQUE index of wp_options.option_name.
 *
 * The obvious WordPress options are all unsafe here:
 *
 * - get_transient() followed by set_transient() is read-then-write, so two
 *   browser tabs both read "free" and both proceed. That is what the code this
 *   replaces did, with a 2s TTL that was shorter than the 3s poll interval and
 *   far shorter than the 20s HTTP timeout it was supposedly guarding.
 * - wp_cache_add() is atomic only when a persistent object cache is installed.
 *   On a default WordPress the object cache is a per-request PHP array, so
 *   every concurrent request succeeds and the lock silently does nothing.
 * - add_option() looks atomic but calls get_option() first, so it is
 *   check-then-act as well.
 * - MySQL GET_LOCK() is genuinely atomic, but is restricted or unavailable on
 *   several managed database products and does not behave predictably with
 *   persistent connections.
 *
 * INSERT IGNORE against the unique index is atomic on every MySQL that
 * WordPress supports, needs no extra infrastructure, and degrades safely.
 *
 * Rows are stored as "<zero-padded expiry>:<token>". The zero padding makes a
 * string comparison order locks by expiry, which lets a stale lock be taken
 * over with one conditional UPDATE.
 */
final class DbLock implements LockInterface {
  private const PREFIX = 'blink_lock_';

  public function __construct(
    private \wpdb $db,
    private ClockInterface $clock
  ) {}

  public function acquire(string $key, int $ttl): ?string {
    $name = self::PREFIX . $key;
    $token = $this->newToken();
    $value = $this->encode($this->clock->now() + $ttl, $token);

    // Uncontended case: atomic at the unique index.
    $inserted = $this->db->query(
      $this->db->prepare(
        "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload)
         VALUES (%s, %s, 'no')",
        $name,
        $value
      )
    );

    if ($inserted === 1) {
      $this->forgetCache($name);

      return $token;
    }

    // Contended: take over only if the existing lock has already expired.
    $takenOver = $this->db->query(
      $this->db->prepare(
        "UPDATE {$this->db->options} SET option_value = %s
         WHERE option_name = %s AND option_value < %s",
        $value,
        $name,
        $this->expiryPrefix($this->clock->now())
      )
    );

    if ($takenOver === 1) {
      $this->forgetCache($name);

      return $token;
    }

    return null;
  }

  public function release(string $key, string $token): void {
    $name = self::PREFIX . $key;

    // Matching on the token means a caller whose lock already expired and was
    // taken over cannot delete the new holder's lock.
    $this->db->query(
      $this->db->prepare(
        "DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value LIKE %s",
        $name,
        '%:' . $this->db->esc_like($token)
      )
    );

    $this->forgetCache($name);
  }

  public function collectGarbage(): void {
    $this->db->query(
      $this->db->prepare(
        "DELETE FROM {$this->db->options}
         WHERE option_name LIKE %s AND option_value < %s",
        $this->db->esc_like(self::PREFIX) . '%',
        $this->expiryPrefix($this->clock->now() - HOUR_IN_SECONDS)
      )
    );
  }

  private function newToken(): string {
    return bin2hex(random_bytes(8));
  }

  private function encode(int $expiresAt, string $token): string {
    return sprintf('%010d:%s', $expiresAt, $token);
  }

  private function expiryPrefix(int $timestamp): string {
    return sprintf('%010d:', $timestamp);
  }

  /**
   * These rows are never read through get_option(), but WordPress caches
   * option lookups aggressively and a stale entry elsewhere would be
   * confusing. Clearing both caches keeps the table the single source of
   * truth.
   */
  private function forgetCache(string $name): void {
    wp_cache_delete($name, 'options');

    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
      unset($notoptions[$name]);
      wp_cache_set('notoptions', $notoptions, 'options');
    }
  }
}
