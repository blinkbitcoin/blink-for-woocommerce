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

  public function __construct(private \wpdb $db, private ClockInterface $clock) {}

  public function hit(string $bucket, int $limit, int $windowSeconds): bool {
    if ($limit <= 0) {
      return false;
    }

    $windowSeconds = max(1, $windowSeconds);
    $windowStart = intdiv($this->clock->now(), $windowSeconds) * $windowSeconds;
    $name = $this->keyFor($bucket, $windowStart);

    $this->run(
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
    $cursor = self::PREFIX;

    do {
      $query = $this->db->prepare(
        "SELECT option_name FROM {$this->db->options}
         WHERE option_name LIKE %s AND option_name > %s
         ORDER BY option_name ASC
         LIMIT 500",
        $this->db->esc_like(self::PREFIX) . '%',
        $cursor
      );
      if ($query === null) {
        return;
      }

      $names = $this->normalizeOptionNames($this->db->get_col($query));
      if ($names === []) {
        return;
      }

      foreach ($names as $name) {
        $name = (string) $name;
        $windowStart = $this->windowStartFrom($name);
        if ($windowStart !== null && $windowStart < $cutoff) {
          $this->run(
            $this->db->prepare(
              "DELETE FROM {$this->db->options} WHERE option_name = %s",
              $name
            )
          );
          wp_cache_delete($name, 'options');
        }
      }

      // Advance independently of deletion success. A failed delete must not
      // make the next batch select the same rows forever.
      $cursor = (string) end($names);
    } while (count($names) === 500);
  }

  /**
   * Normalizes the database boundary, whose runtime can be less strict than
   * the WordPress stub's declared array return type.
   *
   * @return array<mixed>
   */
  private function normalizeOptionNames(mixed $names): array {
    return is_array($names) ? $names : [];
  }

  /**
   * Runs a prepared statement.
   *
   * prepare() returns null when the statement could not be built, which is a
   * programming error; running "nothing" instead would leave a counter
   * silently un-incremented and quietly disable the budget.
   */
  private function run(?string $query): void {
    if ($query === null) {
      return;
    }

    $this->db->query($query);
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
