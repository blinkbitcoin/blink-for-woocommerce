<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

/**
 * Bech32 decoding, as much of it as a BOLT11 invoice needs.
 *
 * Note the deliberate omission: bech32 normally caps a string at 90
 * characters, and BOLT11 explicitly exempts itself from that limit. Enforcing
 * it is the most common bug in naive ports and would reject every real
 * invoice, so the cap is not implemented here.
 */
final class Bech32 {
  private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

  private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

  /**
   * Splits a bech32 string into its human-readable part and 5-bit data,
   * verifying the checksum.
   *
   * @return array{string,list<int>}
   * @throws Bolt11Exception
   */
  public static function decode(string $input): array {
    if ($input === '') {
      throw new Bolt11Exception('empty bech32 string');
    }

    $hasLower = preg_match('/[a-z]/', $input) === 1;
    $hasUpper = preg_match('/[A-Z]/', $input) === 1;
    if ($hasLower && $hasUpper) {
      throw new Bolt11Exception('mixed case bech32 string');
    }
    $input = strtolower($input);

    foreach (str_split($input) as $char) {
      $ord = ord($char);
      if ($ord < 33 || $ord > 126) {
        throw new Bolt11Exception('bech32 string contains an out-of-range character');
      }
    }

    // The separator is the LAST '1', because the human-readable part may
    // contain one (an invoice amount of 1 sat, say).
    $separator = strrpos($input, '1');
    if ($separator === false || $separator === 0) {
      throw new Bolt11Exception('bech32 string has no human-readable part');
    }

    $hrp = substr($input, 0, $separator);
    $dataPart = substr($input, $separator + 1);
    if (strlen($dataPart) < 6) {
      throw new Bolt11Exception('bech32 data part is shorter than its checksum');
    }

    $data = [];
    foreach (str_split($dataPart) as $char) {
      $index = strpos(self::CHARSET, $char);
      if ($index === false) {
        throw new Bolt11Exception('bech32 data contains a character outside the charset');
      }
      $data[] = $index;
    }

    if (self::polymod(array_merge(self::hrpExpand($hrp), $data)) !== 1) {
      throw new Bolt11Exception('bech32 checksum mismatch');
    }

    return [$hrp, array_slice($data, 0, -6)];
  }

  /**
   * Regroups values from one bit width to another.
   *
   * @param list<int> $data
   * @return list<int>
   * @throws Bolt11Exception
   */
  public static function convertBits(array $data, int $from, int $to, bool $pad): array {
    $acc = 0;
    $bits = 0;
    $result = [];
    $maxValue = (1 << $to) - 1;
    $maxAcc = (1 << $from + $to - 1) - 1;

    foreach ($data as $value) {
      if ($value < 0 || $value >> $from !== 0) {
        throw new Bolt11Exception('value out of range for bit conversion');
      }
      $acc = (($acc << $from) | $value) & $maxAcc;
      $bits += $from;
      while ($bits >= $to) {
        $bits -= $to;
        $result[] = ($acc >> $bits) & $maxValue;
      }
    }

    if ($pad) {
      if ($bits > 0) {
        $result[] = ($acc << $to - $bits) & $maxValue;
      }
    } elseif ($bits >= $from || (($acc << $to - $bits) & $maxValue) !== 0) {
      throw new Bolt11Exception('invalid padding in bit conversion');
    }

    return $result;
  }

  /**
   * Reads a big-endian integer out of 5-bit groups.
   *
   * @param list<int> $data
   */
  public static function toInt(array $data): int {
    $value = 0;
    foreach ($data as $part) {
      $value = ($value << 5) | $part;
    }

    return $value;
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
