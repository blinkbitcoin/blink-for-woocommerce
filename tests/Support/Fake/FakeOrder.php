<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\NonCustodial\OrderRecord;

/**
 * In-memory order.
 *
 * The narrow OrderRecord surface means invoice storage can be tested without
 * booting WooCommerce, while the integration suite proves the real WC_Order
 * adapter behaves the same under both storage backends.
 */
final class FakeOrder implements OrderRecord {
  /** @var array<string,mixed> */
  public array $meta = [];

  /** @var list<string> */
  public array $notes = [];

  public int $saves = 0;

  public function __construct(
    private int $id = 1,
    private string $total = '10.00',
    private string $currency = 'USD'
  ) {
  }

  public function id(): int {
    return $this->id;
  }

  public function getMeta(string $key): mixed {
    return $this->meta[$key] ?? '';
  }

  public function setMeta(string $key, mixed $value): void {
    $this->meta[$key] = $value;
  }

  public function deleteMeta(string $key): void {
    unset($this->meta[$key]);
  }

  public function save(): void {
    $this->saves++;
  }

  public function total(): string {
    return $this->total;
  }

  public function currency(): string {
    return $this->currency;
  }

  public function addNote(string $note): void {
    $this->notes[] = $note;
  }

  public function setTotal(string $total): self {
    $this->total = $total;

    return $this;
  }

  public function setCurrency(string $currency): self {
    $this->currency = $currency;

    return $this;
  }
}
