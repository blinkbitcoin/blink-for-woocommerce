<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\ClockInterface;

/**
 * The only place that knows how a non-custodial invoice is stored on an order.
 *
 * All new keys are underscore-prefixed, which WordPress treats as protected
 * meta and hides from the order editor's custom-fields box. That is not
 * cosmetic: without it any user who can edit orders can change the verify URL
 * -- a value this plugin fetches over HTTP and trusts for settlement -- or
 * move the expiry. The keys are new in an unreleased version, so renaming them
 * costs nothing.
 *
 * blink_id keeps its old name deliberately: the custodial webhook looks orders
 * up by it, and renaming it would break both in-flight custodial orders and
 * every order created by an earlier release.
 */
final class InvoiceRepository {
  public const PAYMENT_HASH = 'blink_id';
  public const PAYMENT_REQUEST = 'blink_payment_request';

  public const ACCOUNT_TYPE = '_blink_account_type';
  public const VERIFY_URL = '_blink_verify_url';
  public const LN_ADDRESS = '_blink_ln_address';
  public const AMOUNT_MSAT = '_blink_amount_msat';
  public const SATOSHIS = '_blink_satoshis';
  public const CREATED_AT = '_blink_created_at';
  public const EXPIRES_AT = '_blink_expires_at';
  public const ORDER_TOTAL = '_blink_order_total';
  public const ORDER_CURRENCY = '_blink_order_currency';

  public const STATUS = '_blink_status';
  public const STATUS_AT = '_blink_status_at';
  public const ATTEMPTS = '_blink_attempts';
  public const ERRORS = '_blink_errors';
  public const SETTLED_AT = '_blink_settled_at';
  public const PREIMAGE = '_blink_preimage';
  public const TERMINAL = '_blink_terminal';

  public const ACCOUNT_TYPE_NON_CUSTODIAL = 'non_custodial';

  /** Keys cleared when an invoice is replaced. */
  private const INVOICE_KEYS = [
    self::PAYMENT_HASH,
    self::PAYMENT_REQUEST,
    self::ACCOUNT_TYPE,
    self::VERIFY_URL,
    self::LN_ADDRESS,
    self::AMOUNT_MSAT,
    self::SATOSHIS,
    self::CREATED_AT,
    self::EXPIRES_AT,
    self::ORDER_TOTAL,
    self::ORDER_CURRENCY,
    self::STATUS,
    self::STATUS_AT,
    self::ATTEMPTS,
    self::ERRORS,
    self::SETTLED_AT,
    self::PREIMAGE,
    self::TERMINAL,
    'blink_redirect',
  ];

  public function __construct(private ClockInterface $clock) {}

  public function isNonCustodial(OrderRecord $order): bool {
    return $order->getMeta(self::ACCOUNT_TYPE) === self::ACCOUNT_TYPE_NON_CUSTODIAL;
  }

  public function load(OrderRecord $order): ?StoredInvoice {
    $paymentHash = (string) $order->getMeta(self::PAYMENT_HASH);
    $verifyUrl = (string) $order->getMeta(self::VERIFY_URL);
    $lnAddress = (string) $order->getMeta(self::LN_ADDRESS);

    if ($paymentHash === '' || $verifyUrl === '' || $lnAddress === '') {
      return null;
    }

    return new StoredInvoice(
      $paymentHash,
      (string) $order->getMeta(self::PAYMENT_REQUEST),
      $verifyUrl,
      $lnAddress,
      (int) $order->getMeta(self::AMOUNT_MSAT),
      (int) $order->getMeta(self::SATOSHIS),
      (int) $order->getMeta(self::CREATED_AT),
      (int) $order->getMeta(self::EXPIRES_AT),
      (string) $order->getMeta(self::ORDER_TOTAL),
      (string) $order->getMeta(self::ORDER_CURRENCY)
    );
  }

  public function store(OrderRecord $order, StoredInvoice $invoice): void {
    $order->setMeta(self::ACCOUNT_TYPE, self::ACCOUNT_TYPE_NON_CUSTODIAL);
    $order->setMeta(self::PAYMENT_HASH, $invoice->paymentHash);
    $order->setMeta(self::PAYMENT_REQUEST, $invoice->paymentRequest);
    $order->setMeta(self::VERIFY_URL, $invoice->verifyUrl);
    $order->setMeta(self::LN_ADDRESS, $invoice->lnAddress);
    $order->setMeta(self::AMOUNT_MSAT, $invoice->amountMsat);
    $order->setMeta(self::SATOSHIS, $invoice->satoshis);
    $order->setMeta(self::CREATED_AT, $invoice->createdAt);
    $order->setMeta(self::EXPIRES_AT, $invoice->expiresAt);
    $order->setMeta(self::ORDER_TOTAL, $invoice->orderTotal);
    $order->setMeta(self::ORDER_CURRENCY, $invoice->orderCurrency);
    $order->setMeta(self::ATTEMPTS, 0);
    $order->setMeta(self::ERRORS, 0);
    $order->save();
  }

  /**
   * Clears every non-custodial key, including the account-type marker.
   *
   * Leaving that marker behind was a real bug: the pay page and the AJAX
   * endpoint both branch on it, so a cleared order still looked like a
   * non-custodial order with no invoice.
   */
  public function clear(OrderRecord $order): void {
    foreach (self::INVOICE_KEYS as $key) {
      $order->deleteMeta($key);
    }
    $order->save();
  }

  public function cachedStatus(OrderRecord $order): ?SettlementOutcome {
    $status = (string) $order->getMeta(self::STATUS);
    if ($status === '') {
      return null;
    }

    $parsed = SettlementStatus::tryFrom($status);
    if ($parsed === null) {
      return null;
    }

    return new SettlementOutcome(
      $parsed,
      (int) $order->getMeta(self::STATUS_AT),
      'cached',
      $this->terminalStatus($order) !== null
    );
  }

  public function cacheStatus(OrderRecord $order, SettlementOutcome $outcome): void {
    // Unknown says nothing about the payment, so caching it would overwrite a
    // real observation with an absence of one.
    if ($outcome->status === SettlementStatus::Unknown) {
      return;
    }

    $order->setMeta(self::STATUS, $outcome->status->value);
    $order->setMeta(self::STATUS_AT, $outcome->observedAt);
    $order->save();
  }

  public function attempts(OrderRecord $order): int {
    return (int) $order->getMeta(self::ATTEMPTS);
  }

  public function consecutiveErrors(OrderRecord $order): int {
    return (int) $order->getMeta(self::ERRORS);
  }

  public function recordAttempt(OrderRecord $order, bool $wasError): void {
    $order->setMeta(self::ATTEMPTS, $this->attempts($order) + 1);
    // Errors are counted consecutively: one good answer means the endpoint is
    // reachable again, so the budget should not stay half spent.
    $order->setMeta(self::ERRORS, $wasError ? $this->consecutiveErrors($order) + 1 : 0);
    $order->save();
  }

  /**
   * Records settlement exactly once.
   *
   * The scheduler tick and the customer's browser can both observe the same
   * payment. Without this latch each would apply the paid status and add its
   * own order note.
   *
   * @return bool true the first time, false on every subsequent call.
   */
  public function markSettled(OrderRecord $order, ?string $preimage): bool {
    if ((int) $order->getMeta(self::SETTLED_AT) > 0) {
      return false;
    }

    $order->setMeta(self::SETTLED_AT, $this->clock->now());
    if ($preimage !== null && $preimage !== '') {
      $order->setMeta(self::PREIMAGE, $preimage);
    }
    $order->save();

    return true;
  }

  public function markTerminal(OrderRecord $order, SettlementStatus $status): void {
    $order->setMeta(self::TERMINAL, $status->value);
    $order->save();
  }

  public function terminalStatus(OrderRecord $order): ?SettlementStatus {
    $stored = (string) $order->getMeta(self::TERMINAL);

    return $stored === '' ? null : SettlementStatus::tryFrom($stored);
  }

  /**
   * Whether the order total still matches what the invoice was created for.
   *
   * An order edited between invoice creation and settlement would otherwise be
   * marked paid for the wrong amount.
   */
  public function totalsUnchanged(OrderRecord $order, StoredInvoice $invoice): bool {
    if ($invoice->orderTotal === '' || $invoice->orderCurrency === '') {
      return true;
    }

    return $order->currency() === $invoice->orderCurrency &&
      // Compare numerically: WooCommerce may hand back "10.00" or "10".
      abs((float) $order->total() - (float) $invoice->orderTotal) < 0.00001;
  }
}
