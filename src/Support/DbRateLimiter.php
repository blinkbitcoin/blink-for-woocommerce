<?php

declare(strict_types=1);

namespace Blink\WC\Support;

/**
 * Counter built on a single atomic statement.
 *
 * INSERT ... ON DUPLICATE KEY UPDATE increments without a read-modify-write,
 * so two concurrent requests cannot both observe the same count and each
 * conclude they are within budget.
 *
 * The window start is encoded in the key rather than tracked inside the row,
 * so a window expires simply by becoming unreferenced. Slight over-admission
 * at a window boundary is acceptable: this is abuse mitigation, not billing.
 */
final class DbRateLimiter implements RateLimiterInterface {
  public const PREFIX = 'blink_rl_';

  /** Rows older than this can never be referenced again. */
  private const RETENTION_SECONDS = 86400;

  public function __construct(
    private \wpdb $db,
    private ClockInterface $clock
  ) {}

  public function hit(string $bucket, int $limit, int $windowSeconds): bool {
    if ($limit <= 0) {
      return false;
    }

    $windowSeconds = max(1, $windowSeconds);
    $windowStart = intdiv($this->clock->now(), $windowSeconds) * $windowSeconds;
    $name = $this->keyFor($bucket, $windowStart);

    $this->db->query(
      $this->db->prepare(
        "INSERT INTO {$this->db->options} (option_name, option_value, autoload)
         VALUES (%s, '1', 'no')
         ON DUPLICATE KEY UPDATE option_value = option_value + 1",
        $name
      )
    );

    $count = (int) $this->db->get_var(
      $this->db->prepare(
        "SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
        $name
      )
    );

    wp_cache_delete($name, 'options');

    return $count <= $limit;
  }

  /**
   * Window starts are zero padded, so the name carries a sortable timestamp.
   * Names are read back and filtered in PHP because the bucket segment is
   * variable length, which makes a purely SQL comparison unreliable.
   */
  public function collectGarbage(): void {
    $cutoff = $this->clock->now() - self::RETENTION_SECONDS;

    $names = $this->db->get_col(
      $this->db->prepare(
        "SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s LIMIT 500",
        $this->db->esc_like(self::PREFIX) . '%'
      )
    );

    if (!is_array($names)) {
      return;
    }

    foreach ($names as $name) {
      $windowStart = $this->windowStartFrom((string) $name);
      if ($windowStart !== null && $windowStart < $cutoff) {
        $this->db->query(
          $this->db->prepare(
            "DELETE FROM {$this->db->options} WHERE option_name = %s",
            $name
          )
        );
        wp_cache_delete((string) $name, 'options');
      }
    }
  }

  private function keyFor(string $bucket, int $windowStart): string {
    return self::PREFIX . $bucket . '_' . sprintf('%010d', $windowStart);
  }

  private function windowStartFrom(string $name): ?int {
    $separator = strrpos($name, '_');
    if ($separator === false) {
      return null;
    }

    $suffix = substr($name, $separator + 1);

    return preg_match('/^\d{10}$/', $suffix) === 1 ? (int) $suffix : null;
  }
}
