<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial\Bolt11;

/**
 * Decodes the parts of a BOLT11 invoice needed to check that it matches what
 * was asked for.
 *
 * Signatures are deliberately NOT verified, and that is a decision rather than
 * an omission. The invoice arrives over TLS from a host inside the merchant's
 * own Lightning-address domain, so a valid signature would only prove that
 * some node signed it -- which is not a fact this plugin needs. What it needs
 * is binding: that the invoice asks for the amount that was requested, that it
 * matches the payment hash settlement will be checked against, and that it is
 * bound to the LNURL metadata. InvoiceValidator establishes exactly that, and
 * settlement additionally proves the preimage hashes to the payment hash.
 *
 * Skipping signature recovery also keeps secp256k1 out of the dependency list,
 * which matters for a plugin installed on arbitrary shared hosting.
 */
final class Bolt11Decoder {
  /** Signature plus recovery id, in 5-bit groups, at the end of the data part. */
  private const SIGNATURE_LENGTH = 104;

  /** Timestamp, in 5-bit groups, at the start of the data part. */
  private const TIMESTAMP_LENGTH = 7;

  /**
   * Multiplier values in pico-bitcoin. 1 BTC = 10^12 pico-bitcoin, and
   * 1 msat = 10 pico-bitcoin.
   */
  private const MULTIPLIERS = [
    'm' => 1000000000,
    'u' => 1000000,
    'n' => 1000,
    'p' => 1,
  ];

  /** @throws Bolt11Exception */
  public function decode(string $invoice): DecodedInvoice {
    $invoice = trim($invoice);
    [$hrp, $data] = Bech32::decode($invoice);

    if (!str_starts_with($hrp, 'ln')) {
      throw new Bolt11Exception('not a lightning invoice');
    }

    [$network, $amountMsat] = $this->parseHrp(substr($hrp, 2));

    if (count($data) < self::TIMESTAMP_LENGTH + self::SIGNATURE_LENGTH) {
      throw new Bolt11Exception('invoice data is too short to contain a timestamp and signature');
    }

    $timestamp = Bech32::toInt(array_slice($data, 0, self::TIMESTAMP_LENGTH));
    $tagged = array_slice(
      $data,
      self::TIMESTAMP_LENGTH,
      count($data) - self::TIMESTAMP_LENGTH - self::SIGNATURE_LENGTH
    );

    $paymentHash = null;
    $descriptionHash = null;
    $description = null;
    $expiry = null;

    $offset = 0;
    $total = count($tagged);
    while ($offset + 3 <= $total) {
      $type = $tagged[$offset];
      $length = ($tagged[$offset + 1] << 5) | $tagged[$offset + 2];
      $offset += 3;

      if ($offset + $length > $total) {
        throw new Bolt11Exception('truncated tagged field');
      }

      $value = array_slice($tagged, $offset, $length);
      $offset += $length;

      // BOLT11: the first occurrence of a field wins; later ones are ignored.
      switch (self::CHARSET[$type] ?? '') {
        case 'p':
          if ($paymentHash === null && $length === 52) {
            $paymentHash = $this->toHex($value, 32);
          }
          break;
        case 'h':
          if ($descriptionHash === null && $length === 52) {
            $descriptionHash = $this->toHex($value, 32);
          }
          break;
        case 'd':
          if ($description === null) {
            $description = $this->toUtf8($value);
          }
          break;
        case 'x':
          if ($expiry === null) {
            $expiry = Bech32::toInt($value);
          }
          break;
        default:
          // Unknown fields are skipped by length, as the spec requires.
          break;
      }
    }

    return new DecodedInvoice(
      $network,
      $amountMsat,
      $timestamp,
      $expiry ?? DecodedInvoice::DEFAULT_EXPIRY_SECONDS,
      $paymentHash,
      $descriptionHash,
      $description
    );
  }

  private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

  /**
   * Splits the human-readable part after "ln" into a network and an amount.
   *
   * @return array{string,int|null}
   */
  private function parseHrp(string $rest): array {
    if (!preg_match('/^(bcrt|bc|tbs|tb|sb)(\d*)([munp]?)$/', $rest, $m)) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception message, not output.
      throw new Bolt11Exception('unrecognised invoice prefix: ln' . $rest);
    }

    [, $network, $digits, $multiplier] = $m;

    if ($digits === '') {
      if ($multiplier !== '') {
        throw new Bolt11Exception('invoice has a multiplier but no amount');
      }

      // An amountless invoice: legal in BOLT11, refused later by the
      // validator because this plugin always requests an exact amount.
      return [$network, null];
    }

    $amount = (int) $digits;
    if ($multiplier === '') {
      // Bare amount is in bitcoin.
      return [$network, $amount * 100000000000];
    }

    $picoBitcoin = $amount * self::MULTIPLIERS[$multiplier];
    if ($multiplier === 'p' && $amount % 10 !== 0) {
      throw new Bolt11Exception('amount is not representable in millisatoshi');
    }

    // 1 pico-bitcoin = 0.01 msat; 10 pico-bitcoin = 0.1 msat.
    return [$network, intdiv($picoBitcoin, 10)];
  }

  /** @param list<int> $value */
  private function toHex(array $value, int $bytes): string {
    $converted = Bech32::convertBits($value, 5, 8, false);
    $binary = '';
    foreach (array_slice($converted, 0, $bytes) as $byte) {
      $binary .= chr($byte);
    }

    return bin2hex($binary);
  }

  /** @param list<int> $value */
  private function toUtf8(array $value): string {
    $binary = '';
    foreach (Bech32::convertBits($value, 5, 8, false) as $byte) {
      $binary .= chr($byte);
    }

    return $binary;
  }
}
