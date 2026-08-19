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
  public const OUTSTANDING_INVOICES = '_blink_outstanding_invoices';

  public const STATUS = '_blink_status';
  public const STATUS_AT = '_blink_status_at';
  public const ATTEMPTS = '_blink_attempts';
  public const ERRORS = '_blink_errors';
  public const SETTLED_AT = '_blink_settled_at';
  public const SETTLED_PAYMENT_HASH = '_blink_settled_payment_hash';
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
    self::OUTSTANDING_INVOICES,
    self::STATUS,
    self::STATUS_AT,
    self::ATTEMPTS,
    self::ERRORS,
    self::SETTLED_AT,
    self::SETTLED_PAYMENT_HASH,
    self::PREIMAGE,
    self::TERMINAL,
    UnpaidOrderGuard::HOLD_NOTICE,
    'blink_redirect',
  ];

  public function __construct(private ClockInterface $clock) {
  }

  public function isNonCustodial(OrderRecord $order): bool {
    return $order->getMeta(self::ACCOUNT_TYPE) === self::ACCOUNT_TYPE_NON_CUSTODIAL;
  }

  /**
   * Whether this order was created under a known account type.
   *
   * Distinct from isNonCustodial(), which cannot tell a custodial order from
   * an order that has no invoice at all. The gateway needs that distinction to
   * route an existing order by what it is rather than by what the merchant has
   * since switched the setting to.
   */
  public function hasStoredAccountType(OrderRecord $order): bool {
    return (string) $order->getMeta(self::ACCOUNT_TYPE) !== '';
  }

  /**
   * Whether this order should be finished non-custodially.
   *
   * An order in flight has to be finished the way it was started, so a stored
   * account type always wins. $settingDefault -- the merchant's current account
   * type setting -- decides only for an order that has no invoice yet.
   *
   * It arrives as a bool rather than as BlinkApiHelper so that this class stays
   * free of WordPress, which is what lets the unit tier reach these branches.
   */
  public function resolvesNonCustodial(OrderRecord $order, bool $settingDefault): bool {
    if ($this->hasStoredAccountType($order)) {
      return $this->isNonCustodial($order);
    }

    // Released custodial orders predate the account-type marker, but they do
    // carry blink_id for webhook lookup. Treating one as a brand-new order
    // after the merchant changes settings would replace the issued invoice
    // and sever that webhook association. Non-custodial invoices always store
    // their explicit marker alongside this shared payment-hash key.
    if ((string) $order->getMeta(self::PAYMENT_HASH) !== '') {
      return false;
    }

    return $settingDefault;
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
    $this->writeCurrent($order, $invoice);
    $order->setMeta(self::ATTEMPTS, 0);
    $order->setMeta(self::ERRORS, 0);
    $order->save();
  }

  /**
   * Makes a new invoice current without forgetting a predecessor that can
   * still be paid.
   */
  public function replace(OrderRecord $order, StoredInvoice $invoice): void {
    $previous = $this->load($order);
    $outstanding = $this->outstanding($order);

    if ($this->terminalStatus($order) !== null) {
      $outstanding = [];
    } elseif (
      $previous !== null &&
      !in_array(
        $previous->paymentHash,
        array_map(
          static fn(StoredInvoice $stored): string => $stored->paymentHash,
          $outstanding
        ),
        true
      )
    ) {
      $outstanding[] = $previous;
    }

    foreach (self::INVOICE_KEYS as $key) {
      if ($key !== self::OUTSTANDING_INVOICES) {
        $order->deleteMeta($key);
      }
    }

    $this->writeCurrent($order, $invoice);
    $this->storeOutstanding($order, $outstanding);
    $order->setMeta(self::ATTEMPTS, 0);
    $order->setMeta(self::ERRORS, 0);
    $order->save();
  }

  /** @return list<StoredInvoice> */
  public function outstanding(OrderRecord $order): array {
    $stored = $order->getMeta(self::OUTSTANDING_INVOICES);
    if (!is_array($stored)) {
      return [];
    }

    $invoices = [];
    foreach ($stored as $value) {
      if (!is_array($value)) {
        continue;
      }

      $invoice = $this->invoiceFromArray($value);
      if ($invoice !== null) {
        $invoices[] = $invoice;
      }
    }

    return $invoices;
  }

  /** @return list<StoredInvoice> */
  public function tracked(OrderRecord $order): array {
    $invoices = $this->outstanding($order);
    $current = $this->load($order);
    if ($current !== null) {
      $invoices[] = $current;
    }

    return $invoices;
  }

  public function removeOutstanding(OrderRecord $order, string $paymentHash): void {
    $remaining = array_values(
      array_filter(
        $this->outstanding($order),
        static fn(StoredInvoice $invoice): bool =>
          !hash_equals($invoice->paymentHash, $paymentHash)
      )
    );

    $this->storeOutstanding($order, $remaining);
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

  /**
   * Records a background check.
   *
   * Only the background job writes here, because these counters exist to
   * decide when *it* should give up. A customer refreshing the pay page says
   * nothing about how much work the background job has done, and letting it
   * spend this budget used to kill background settlement mid-invoice.
   */
  public function recordAttempt(OrderRecord $order, bool $wasError): void {
    $order->setMeta(self::ATTEMPTS, $this->attempts($order) + 1);
    // Errors are counted consecutively: one good answer means the endpoint is
    // reachable again, so the budget should not stay half spent.
    $order->setMeta(self::ERRORS, $wasError ? $this->consecutiveErrors($order) + 1 : 0);
    $order->save();
  }

  /**
   * Records that the endpoint answered, whoever asked it.
   *
   * The counterpart to the rule above, and deliberately asymmetric: a
   * foreground check may not spend the background budget, but it may prove the
   * endpoint is reachable. Clearing the error count on that proof can only
   * prevent the background job giving up too early, never cause it.
   */
  public function recordEndpointReachable(OrderRecord $order): void {
    if ($this->consecutiveErrors($order) === 0) {
      return;
    }

    $order->setMeta(self::ERRORS, 0);
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
  public function markSettled(
    OrderRecord $order,
    string $paymentHash,
    ?string $preimage
  ): bool {
    if ((int) $order->getMeta(self::SETTLED_AT) > 0) {
      return false;
    }

    $order->setMeta(self::SETTLED_AT, $this->clock->now());
    $order->setMeta(self::SETTLED_PAYMENT_HASH, $paymentHash);
    if ($preimage !== null && $preimage !== '') {
      $order->setMeta(self::PREIMAGE, $preimage);
    }
    $order->save();

    return true;
  }

  public function settledPaymentHash(OrderRecord $order): string {
    return (string) $order->getMeta(self::SETTLED_PAYMENT_HASH);
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
      self::normaliseTotal($order->total()) ===
        self::normaliseTotal($invoice->orderTotal);
  }

  /**
   * Puts a WooCommerce total into one comparable form.
   *
   * Both sides originate from WC_Order::get_total(), so they differ only in
   * trailing zeros -- "10.00" against "10". Comparing as normalised decimal
   * strings keeps that tolerance and no more. The previous fixed epsilon of
   * 0.00001 was roughly a thousand satoshis against a BTC-denominated store,
   * so an order edited from 0.00001000 to 0.00001900 BTC read as unchanged and
   * could be completed on the invoice for the lower amount.
   */
  private static function normaliseTotal(string $total): string {
    $trimmed = trim($total);
    if (!str_contains($trimmed, '.')) {
      return $trimmed;
    }

    return rtrim(rtrim($trimmed, '0'), '.');
  }

  private function writeCurrent(OrderRecord $order, StoredInvoice $invoice): void {
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
  }

  /** @param list<StoredInvoice> $invoices */
  private function storeOutstanding(OrderRecord $order, array $invoices): void {
    if ($invoices === []) {
      $order->deleteMeta(self::OUTSTANDING_INVOICES);

      return;
    }

    $order->setMeta(
      self::OUTSTANDING_INVOICES,
      array_map([$this, 'invoiceToArray'], $invoices)
    );
  }

  /** @return array<string,int|string> */
  private function invoiceToArray(StoredInvoice $invoice): array {
    return [
      'paymentHash' => $invoice->paymentHash,
      'paymentRequest' => $invoice->paymentRequest,
      'verifyUrl' => $invoice->verifyUrl,
      'lnAddress' => $invoice->lnAddress,
      'amountMsat' => $invoice->amountMsat,
      'satoshis' => $invoice->satoshis,
      'createdAt' => $invoice->createdAt,
      'expiresAt' => $invoice->expiresAt,
      'orderTotal' => $invoice->orderTotal,
      'orderCurrency' => $invoice->orderCurrency,
    ];
  }

  /** @param array<mixed> $value */
  private function invoiceFromArray(array $value): ?StoredInvoice {
    foreach (['paymentHash', 'paymentRequest', 'verifyUrl', 'lnAddress'] as $key) {
      if (!isset($value[$key]) || !is_string($value[$key]) || $value[$key] === '') {
        return null;
      }
    }

    foreach (
      ['amountMsat', 'satoshis', 'createdAt', 'expiresAt']
      as $key
    ) {
      if (!isset($value[$key]) || !is_int($value[$key])) {
        return null;
      }
    }

    if (
      !isset($value['orderTotal'], $value['orderCurrency']) ||
      !is_string($value['orderTotal']) ||
      !is_string($value['orderCurrency'])
    ) {
      return null;
    }

    return new StoredInvoice(
      $value['paymentHash'],
      $value['paymentRequest'],
      $value['verifyUrl'],
      $value['lnAddress'],
      $value['amountMsat'],
      $value['satoshis'],
      $value['createdAt'],
      $value['expiresAt'],
      $value['orderTotal'],
      $value['orderCurrency']
    );
  }
}
