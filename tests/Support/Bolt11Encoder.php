<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Support;

/**
 * Builds syntactically valid BOLT11 strings for tests.
 *
 * The signature is filler: the decoder deliberately does not verify one (see
 * Bolt11Decoder's class docblock), which is exactly what makes synthesising
 * invoices possible. That buys precise control over amounts, tags and expiry
 * that the spec's fixed vectors cannot give -- while the spec vectors, in
 * turn, prove the decoder handles real invoices.
 */
final class Bolt11Encoder {
  private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

  private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

  /** @var list<int> */
  private array $data = [];

  private function __construct(private string $hrp) {}

  public static function create(string $hrp = 'lnbc'): self {
    return new self($hrp);
  }

  public function timestamp(int $timestamp): self {
    $this->data = array_merge($this->data, self::fromInt($timestamp, 7));

    return $this;
  }

  /** Adds a tagged field from raw bytes. */
  public function tagBytes(string $tag, string $bytes): self {
    return $this->tagData($tag, self::convertBits(array_values(unpack('C*', $bytes)), 8, 5, true));
  }

  public function tagHex(string $tag, string $hex): self {
    return $this->tagBytes($tag, (string) hex2bin($hex));
  }

  public function tagInt(string $tag, int $value): self {
    $groups = [];
    while ($value > 0) {
      array_unshift($groups, $value & 31);
      $value >>= 5;
    }
    if ($groups === []) {
      $groups = [0];
    }

    return $this->tagData($tag, $groups);
  }

  /** @param list<int> $value */
  public function tagData(string $tag, array $value): self {
    $type = strpos(self::CHARSET, $tag);
    if ($type === false) {
      throw new \InvalidArgumentException('unknown tag ' . $tag);
    }
    $length = count($value);
    $this->data[] = $type;
    $this->data[] = ($length >> 5) & 31;
    $this->data[] = $length & 31;
    $this->data = array_merge($this->data, $value);

    return $this;
  }

  /** Appends arbitrary 5-bit groups, for deliberately malformed input. */
  public function raw(array $groups): self {
    $this->data = array_merge($this->data, $groups);

    return $this;
  }

  public function build(): string {
    // 104 groups of filler standing in for signature + recovery id.
    $withSignature = array_merge($this->data, array_fill(0, 104, 0));
    $checksum = self::createChecksum($this->hrp, $withSignature);

    $encoded = '';
    foreach (array_merge($withSignature, $checksum) as $group) {
      $encoded .= self::CHARSET[$group];
    }

    return $this->hrp . '1' . $encoded;
  }

  /** @return list<int> */
  private static function fromInt(int $value, int $groups): array {
    $result = [];
    for ($i = $groups - 1; $i >= 0; $i--) {
      $result[] = ($value >> ($i * 5)) & 31;
    }

    return $result;
  }

  /** @param list<int> $data @return list<int> */
  public static function convertBits(array $data, int $from, int $to, bool $pad): array {
    $acc = 0;
    $bits = 0;
    $result = [];
    $maxValue = (1 << $to) - 1;

    foreach ($data as $value) {
      $acc = ($acc << $from) | $value;
      $bits += $from;
      while ($bits >= $to) {
        $bits -= $to;
        $result[] = ($acc >> $bits) & $maxValue;
      }
    }
    if ($pad && $bits > 0) {
      $result[] = ($acc << ($to - $bits)) & $maxValue;
    }

    return $result;
  }

  /** @param list<int> $data @return list<int> */
  private static function createChecksum(string $hrp, array $data): array {
    $values = array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
    $polymod = self::polymod($values) ^ 1;
    $checksum = [];
    for ($i = 0; $i < 6; $i++) {
      $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
    }

    return $checksum;
  }

  /** @return list<int> */
  private static function hrpExpand(string $hrp): array {
    $high = [];
    $low = [];
    foreach (str_split($hrp) as $char) {
      $high[] = ord($char) >> 5;
      $low[] = ord($char) & 31;
    }

    return array_merge($high, [0], $low);
  }

  /** @param list<int> $values */
  private static function polymod(array $values): int {
    $chk = 1;
    foreach ($values as $value) {
      $top = $chk >> 25;
      $chk = (($chk & 0x1ffffff) << 5) ^ $value;
      for ($i = 0; $i < 5; $i++) {
        if ((($top >> $i) & 1) === 1) {
          $chk ^= self::GENERATOR[$i];
        }
      }
    }

    return $chk;
  }
}
