<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

/**
 * Adapts a WC_Order onto the narrow OrderRecord surface.
 *
 * Only HPOS-safe accessors are used, so the plugin behaves identically under
 * both the legacy post storage and the high-performance order tables.
 */
final class WcOrderRecord implements OrderRecord {
  public function __construct(private \WC_Order $order) {
  }

  public function order(): \WC_Order {
    return $this->order;
  }

  public function id(): int {
    return (int) $this->order->get_id();
  }

  public function getMeta(string $key): mixed {
    return $this->order->get_meta($key);
  }

  public function setMeta(string $key, mixed $value): void {
    $this->order->update_meta_data($key, $value);
  }

  public function deleteMeta(string $key): void {
    $this->order->delete_meta_data($key);
  }

  public function save(): void {
    $this->order->save();
  }

  public function total(): string {
    return (string) $this->order->get_total();
  }

  public function currency(): string {
    return (string) $this->order->get_currency();
  }

  public function addNote(string $note): void {
    $this->order->add_order_note($note);
  }
}
