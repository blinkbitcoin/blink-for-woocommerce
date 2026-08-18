<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

/**
 * A wpdb that answers however a test needs, so the failure branches of DbLock
 * and DbRateLimiter can be reached.
 *
 * Both classes guard against $wpdb->prepare() returning null, which real wpdb
 * does when a statement cannot be built. That guard is not decoration: passing
 * null to query() runs nothing, and for a lock "nothing ran" is indistinguishable
 * from "the lock was taken" unless the guard converts it to a refusal. It cannot
 * be provoked through the real wpdb here, because these classes build their own
 * SQL and always pass matching arguments -- hence the double.
 */
class StubWpdb extends \wpdb {
  /**
   * Named ranQueries rather than queries: wpdb already declares an untyped
   * $queries, and redeclaring it with a type is a fatal error.
   *
   * @var list<string>
   */
  public array $ranQueries = [];

  public bool $prepareReturnsNull = false;

  /** @var mixed */
  public $colResult = [];

  /** @var mixed */
  public $varResult = 0;

  /**
   * Deliberately does not call the parent constructor: that one connects to
   * MySQL, and nothing here talks to a database.
   */
  public function __construct(string $optionsTable = 'wp_options') {
    $this->options = $optionsTable;
  }

  /**
   * @param string $query
   * @param mixed  ...$args
   * @return string|null
   */
  public function prepare($query, ...$args) {
    if ($this->prepareReturnsNull) {
      return null;
    }

    return $query;
  }

  /**
   * @param string $query
   * @return int
   */
  public function query($query) {
    $this->ranQueries[] = (string) $query;

    return 0;
  }

  /**
   * @param string|null $query
   * @param int         $x
   * @return mixed
   */
  public function get_col($query = null, $x = 0) {
    return $this->colResult;
  }

  /**
   * @param string|null $query
   * @param int         $x
   * @param int         $y
   * @return mixed
   */
  public function get_var($query = null, $x = 0, $y = 0) {
    return $this->varResult;
  }

  /**
   * @param string $text
   * @return string
   */
  public function esc_like($text) {
    return addcslashes((string) $text, '_%\\');
  }
}
