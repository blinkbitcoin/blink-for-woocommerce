<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * The slice of a WooCommerce order this plugin actually touches.
 *
 * Narrowing the surface keeps the invoice repository testable without booting
 * WooCommerce, and makes it obvious that nothing here reaches for order state
 * it has no business reading.
 */
interface OrderRecord {
  public function id(): int;

  public function getMeta(string $key): mixed;

  public function setMeta(string $key, mixed $value): void;

  public function deleteMeta(string $key): void;

  public function save(): void;

  /** The order total, as WooCommerce stores it: a decimal string. */
  public function total(): string;

  public function currency(): string;
}
