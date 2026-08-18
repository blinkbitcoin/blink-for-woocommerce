<?php
/**
 * Minimal BOLT11 encoder for the end-to-end fake server.
 *
 * Mirrors tests/Support/Bolt11Encoder.php, which cannot be reused here because
 * this file runs inside the WordPress container with no Composer autoloader.
 * Kept deliberately small: it only needs the amount, payment hash, description
 * hash and expiry that InvoiceValidator checks.
 */

declare(strict_types=1);

defined('ABSPATH') || exit();

final class Blink_E2E_Bolt11 {
  private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

  private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

  public static function encode(
    int $amountMsat,
    string $paymentHash,
    string $descriptionHash,
    int $expirySeconds
  ): string {
    $hrp = 'lnbc' . self::amountToHrp($amountMsat);

    $data = self::intToGroups(time(), 7);
    $data = array_merge($data, self::tag('p', self::hexToGroups($paymentHash)));
    $data = array_merge($data, self::tag('h', self::hexToGroups($descriptionHash)));
    $data = array_merge($data, self::tag('x', self::intToMinimalGroups($expirySeconds)));
    // Filler standing in for the signature and recovery id.
    $data = array_merge($data, array_fill(0, 104, 0));

    $encoded = '';
    foreach (array_merge($data, self::checksum($hrp, $data)) as $group) {
      $encoded .= self::CHARSET[$group];
    }

    return $hrp . '1' . $encoded;
  }

  /**
   * Chooses the largest multiplier that keeps the amount a whole number.
   *
   * 1 msat is 10 pico-bitcoin, so the amount in pico is msat * 10.
   */
  private static function amountToHrp(int $amountMsat): string {
    $pico = $amountMsat * 10;

    foreach (['m' => 1000000000, 'u' => 1000000, 'n' => 1000, 'p' => 1] as $suffix => $unit) {
      if ($pico % $unit === 0) {
        return ((string) intdiv($pico, $unit)) . $suffix;
      }
    }

    return (string) $pico . 'p';
  }

  /** @return list<int> */
  private static function tag(string $tag, array $value): array {
    $type = strpos(self::CHARSET, $tag);
    $length = count($value);

    return array_merge([$type, ($length >> 5) & 31, $length & 31], $value);
  }

  /** @return list<int> */
  private static function hexToGroups(string $hex): array {
    $bytes = array_values(unpack('C*', (string) hex2bin($hex)));

    return self::convertBits($bytes, 8, 5);
  }

  /** @return list<int> */
  private static function intToGroups(int $value, int $groups): array {
    $result = [];
    for ($i = $groups - 1; $i >= 0; $i--) {
      $result[] = ($value >> ($i * 5)) & 31;
    }

    return $result;
  }

  /** @return list<int> */
  private static function intToMinimalGroups(int $value): array {
    $groups = [];
    while ($value > 0) {
      array_unshift($groups, $value & 31);
      $value >>= 5;
    }

    return $groups === [] ? [0] : $groups;
  }

  /** @return list<int> */
  private static function convertBits(array $data, int $from, int $to): array {
    $acc = 0;
    $bits = 0;
    $result = [];
    $max = (1 << $to) - 1;

    foreach ($data as $value) {
      $acc = ($acc << $from) | $value;
      $bits += $from;
      while ($bits >= $to) {
        $bits -= $to;
        $result[] = ($acc >> $bits) & $max;
      }
    }
    if ($bits > 0) {
      $result[] = ($acc << ($to - $bits)) & $max;
    }

    return $result;
  }

  /** @return list<int> */
  private static function checksum(string $hrp, array $data): array {
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
