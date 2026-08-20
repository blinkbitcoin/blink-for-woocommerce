<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support\Fake;

use Blink\WC\Support\LoggerInterface;

/**
 * Captures log output so a test can assert that a failure was reported, which
 * matters for the paths that deliberately swallow an error and carry on.
 */
final class SpyLogger implements LoggerInterface {
  /** @var list<array{level:string,message:string}> */
  public array $records = [];

  public function debug(string $message): void {
    $this->records[] = ['level' => 'debug', 'message' => $message];
  }

  public function error(string $message): void {
    $this->records[] = ['level' => 'error', 'message' => $message];
  }

  /** @return list<string> */
  public function messages(?string $level = null): array {
    $records =
      $level === null
        ? $this->records
        : array_filter(
          $this->records,
          static fn(array $r): bool => $r['level'] === $level
        );

    return array_values(
      array_map(static fn(array $r): string => $r['message'], $records)
    );
  }

  public function hasMessageContaining(string $needle, ?string $level = null): bool {
    foreach ($this->messages($level) as $message) {
      if (str_contains($message, $needle)) {
        return true;
      }
    }

    return false;
  }
}
