<?php

declare(strict_types=1);

namespace Blink\WC\Gateway;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\BlinkApiHelper;
use Blink\WC\Helpers\OrderStates;
use Blink\WC\NonCustodial\LnAddress;
use Blink\WC\NonCustodial\LnurlFailure;
use Blink\WC\NonCustodial\SettlementStatus;
use Blink\WC\NonCustodial\StoredInvoice;
use Blink\WC\NonCustodial\WcOrderRecord;
use Blink\WC\Services;

class BlinkLnGateway extends \WC_Payment_Gateway {
  const ICON_MEDIA_OPTION = 'icon_media_id';
  private $apiHelper;
  private Services $services;
  public $debug_php_version;
  public $debug_plugin_version;

  /**
   * WooCommerce constructs gateways itself, so the services graph is an
   * optional argument defaulting to the shared one. Tests pass their own.
   */
  public function __construct(?Services $services = null) {
    $this->services = $services ?? Services::instance();

    // Set the id first.
    $this->id = 'blink_default';

    // Debugging & informational settings.
    $this->debug_php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $this->debug_plugin_version = BLINK_VERSION;

    // General gateway setup.
    $this->icon = $this->getIcon();
    $this->has_fields = false;

    // Load the settings.
    $this->init_form_fields();
    $this->init_settings();

    // Define user facing set variables.
    $this->title = $this->getTitle();
    $this->description = $this->getDescription();

    // Admin facing title and description.
    $this->method_title = 'Blink (Lightning)';
    $this->method_description = 'Blink Bitcoin Lightning gateway.';

    $this->apiHelper = new BlinkApiHelper();

    // Actions.
    add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [
      $this,
      'process_admin_options',
    ]);
    add_action('woocommerce_api_' . $this->getId(), [$this, 'processWebhook']);

    // Non-custodial (lightning address) on-site pay page + polling endpoint.
    add_action('woocommerce_receipt_' . $this->getId(), [$this, 'renderPayPage']);
    add_action('wp_ajax_blink_check_invoice', [$this, 'ajaxCheckInvoice']);
    add_action('wp_ajax_nopriv_blink_check_invoice', [$this, 'ajaxCheckInvoice']);

    // Supported features.
    $this->supports = ['products'];
  }

  /**
   * Initialise Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title' => 'Enabled/Disabled',
        'type' => 'checkbox',
        'label' => 'Enable this payment gateway.',
        'default' => 'no',
        'value' => 'yes',
        'desc_tip' => false,
      ],
      'title' => [
        'title' => 'Title',
        'type' => 'safe_text',
        'description' =>
          'Controls the name of this payment method as displayed to the customer during checkout.',
        'default' => $this->getTitle(),
        'desc_tip' => true,
      ],
      'description' => [
        'title' => 'Customer Message',
        'type' => 'textarea',
        'description' =>
          'Message to explain how the customer will be paying for the purchase.',
        'default' => $this->getDescription(),
        'desc_tip' => true,
      ],
    ];
  }

  public function getId(): string {
    return $this->id;
  }

  /**
   * Get customer visible gateway title.
   */
  public function getTitle(): string {
    return $this->get_option('title', 'Pay with Bitcoin (Lightning)');
  }

  /**
   * Get customer facing gateway description.
   */
  public function getDescription(): string {
    return $this->get_option(
      'description',
      'You will be redirected to Blink to complete your purchase.'
    );
  }

  /**
   * Get custom gateway icon, if any.
   */
  public function getIcon(): string {
    $defaultIcon = BLINK_PLUGIN_URL . 'assets/images/blink-logo.png';

    $mediaId = $this->get_option(self::ICON_MEDIA_OPTION);
    if (!$mediaId) {
      return $defaultIcon;
    }

    $customIcon = wp_get_attachment_image_src($mediaId);
    return $customIcon[0] ?? $defaultIcon;
  }

  public function process_admin_options() {
    // Store media id.
    $iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;

    // Nonce validation happens in parent::process_admin_options(), which runs
    // below; reading the posted value first is safe because it is only used
    // after that check passes.
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by parent::process_admin_options().
    if (
      !empty($_POST[$iconFieldName]) &&
      ($mediaId = sanitize_key($_POST[$iconFieldName]))
    ) {
      if ($mediaId !== $this->get_option(self::ICON_MEDIA_OPTION)) {
        $this->update_option(self::ICON_MEDIA_OPTION, $mediaId);
      }
    } else {
      // Reset to empty otherwise.
      $this->update_option(self::ICON_MEDIA_OPTION, '');
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    return parent::process_admin_options();
  }

  /**
   * Process the payment and return the result.
   *
   * @param int $order_id Order ID.
   * @return array
   */
  public function process_payment($order_id) {
    if (!$this->apiHelper->configured) {
      Logger::debug(
        'Blink API connection not configured, aborting. Please go to settings and set it up.'
      );
      throw new \Exception(
        "Can't process order. Please contact us if the problem persists."
      );
    }

    // wc_get_order() returns false for an unknown id, so this must be checked
    // before anything is called on it.
    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
      $message = 'Could not load order id ' . $order_id . ', aborting.';
      Logger::debug($message, true);
      throw new \Exception(esc_html($message));
    }

    // Non-custodial (lightning address) accounts are handled on our own pay page.
    if ($this->apiHelper->isNonCustodial()) {
      return $this->processPaymentNonCustodial($order);
    }

    // Check for existing invoice and redirect instead.
    if ($this->validInvoiceExists($order)) {
      $existingInvoiceId = $order->get_meta('blink_id');
      Logger::debug(
        'Found existing Blink invoice and redirecting to it. Invoice id: ' .
          $existingInvoiceId
      );

      return [
        'result' => 'success',
        'invoiceId' => $existingInvoiceId,
        'orderCompleteLink' => $order->get_checkout_order_received_url(),
        'redirect' =>
          $this->apiHelper->getInvoiceRedirectUrl($existingInvoiceId) .
          '?returnUrl=' .
          urlencode($order->get_checkout_order_received_url()),
      ];
    }

    // Create an invoice.
    Logger::debug('Creating invoice on Blink');
    $invoice = $this->createInvoice($order);

    // Returning nothing leaves WooCommerce with no result key, which it
    // reports to the customer as a silent failure.
    if (!$invoice) {
      $message = __(
        "Can't create the Lightning invoice. Please try again or contact us if the problem persists.",
        'blink-for-woocommerce'
      );

      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes notice text when it renders it.
      throw new \Exception($message);
    }

    Logger::debug('Invoice creation successful, redirecting user.');

    return [
      'result' => 'success',
      'invoiceId' => $invoice['paymentHash'],
      'orderCompleteLink' => $order->get_checkout_order_received_url(),
      'redirect' =>
        $invoice['redirectUrl'] .
        '?returnUrl=' .
        urlencode($order->get_checkout_order_received_url()),
    ];
  }

  /**
   * Processes a payment for a non-custodial (lightning address) account.
   *
   * Creates a BOLT11 invoice via the LNURL flow and sends the buyer to the
   * on-site order-pay page, where the invoice is displayed and polled.
   */
  protected function processPaymentNonCustodial(\WC_Order $order): array {
    $record = new WcOrderRecord($order);
    $repository = $this->services->invoiceRepository();

    if (!$this->hasReusableNonCustodialInvoice($order)) {
      $repository->clear($record);

      $invoice = $this->createNonCustodialInvoice($order, $record);
      if ($invoice instanceof LnurlFailure) {
        $message = __(
          "Can't create the Lightning invoice. Please try again or contact us if the problem persists.",
          'blink-for-woocommerce'
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes notice text when it renders it.
        throw new \Exception($message);
      }
    }

    return [
      'result' => 'success',
      'redirect' => $order->get_checkout_payment_url(true),
    ];
  }

  /**
   * @return StoredInvoice|LnurlFailure
   */
  private function createNonCustodialInvoice(\WC_Order $order, WcOrderRecord $record) {
    $config = BlinkApiHelper::getConfig();
    $address = LnAddress::parse((string) ($config['ln_address'] ?? ''));
    if ($address === null) {
      Logger::debug('Configured lightning address is not usable.', true);

      return new LnurlFailure('CONFIG_INVALID', 'lightning address is not usable');
    }

    $invoice = $this->services
      ->invoiceFactory()
      ->create(
        $address,
        (float) $order->get_total(),
        (string) $order->get_currency(),
        (string) $order->get_total(),
        (string) $order->get_currency(),
        'GW-' . $order->get_order_number()
      );

    if ($invoice instanceof LnurlFailure) {
      return $invoice;
    }

    $this->services->invoiceRepository()->store($record, $invoice);
    // Background settlement starts here, so the order no longer depends on the
    // customer keeping the pay page open.
    $this->services->settlementScheduler()->onInvoiceCreated($record, $invoice);

    return $invoice;
  }

  /**
   * Whether the order already has a non-custodial invoice that can still be paid.
   *
   * Deliberately decided from stored state alone. The previous implementation
   * made a synchronous verify request here, which put a third-party HTTP call
   * on the checkout submit path and, because a transport error surfaced as
   * PENDING, treated an unreachable server as "this invoice is fine".
   */
  protected function hasReusableNonCustodialInvoice(\WC_Order $order): bool {
    $record = new WcOrderRecord($order);
    $repository = $this->services->invoiceRepository();

    if ($repository->terminalStatus($record) !== null) {
      return false;
    }

    $invoice = $repository->load($record);
    if ($invoice === null) {
      return false;
    }

    // Leave enough time for the customer to actually pay it.
    $now = $this->services->clock()->now();
    if ($invoice->expiresAt > 0 && $invoice->expiresAt - $now < 120) {
      return false;
    }

    // An order edited since the invoice was made needs a new one.
    return $repository->totalsUnchanged($record, $invoice);
  }

  public function process_refund($order_id, $amount = null, $reason = '') {
    $errServer = 'Blink does not support refunds.';
    Logger::debug($errServer);
    return new \WP_Error('1', $errServer);
  }

  /**
   * Process webhooks from Blink.
   */
  public function processWebhook() {
    Logger::debug('Blink Webhook handler');

    try {
      $data = json_decode(file_get_contents('php://input'), true);

      // Check if the required fields are set in the $_POST array
      if (!isset($data['transaction']['initiationVia']['paymentHash'])) {
        Logger::debug('No Blink invoiceId provided, aborting.');
        wp_die('No Blink invoiceId provided, aborting.', '', ['response' => 200]);
      }

      $invoiceId = sanitize_text_field(
        $data['transaction']['initiationVia']['paymentHash']
      );
      if (empty($invoiceId)) {
        Logger::error('No Blink invoiceId provided.');
        wp_die('No Blink invoiceId provided, aborting.');
      }

      // Load the order by metadata field Blink_id
      $orders = wc_get_orders([
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the webhook has only the payment hash to identify the order by.
      'meta_key' => 'blink_id',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
      'meta_value' => $invoiceId,
      ]);

      // Abort if no orders found
      if (count($orders) === 0) {
        Logger::debug('Could not load order by Blink invoiceId: ' . esc_html($invoiceId));
        wp_die('No order found for this invoiceId.', '', ['response' => 200]);
      }

      // Abort on multiple orders found
      if (count($orders) > 1) {
        Logger::debug('Found multiple orders for invoiceId: ' . esc_html($invoiceId));
        Logger::debug(print_r($orders, true));
        wp_die('Multiple orders found for this invoiceId, aborting.', '', [
          'response' => 200,
        ]);
      }

      // Process the order
      $this->processOrderStatus($orders[0]);
    } catch (\Throwable $e) {
      Logger::debug('Error decoding webook payload: ' . $e->getMessage());
    }
  }

  /**
   * Checks if the order has already a Blink invoice set and checks if it is still
   * valid to avoid creating multiple invoices for the same order on Blink end.
   *
   * @param int $orderId
   *
   * @return mixed Returns false if no valid invoice found or the invoice id.
   */
  protected function validInvoiceExists(\WC_Order $order): bool {
    $invoiceId = $order->get_meta('blink_id');
    if (!$invoiceId) {
      return false;
    }

    try {
      Logger::debug('Trying to fetch existing invoice from Blink for hash ' . $invoiceId);
      $invoice = $this->apiHelper->getInvoiceCustodial($invoiceId);

      // TODO: on an API error this deliberately keeps today's behaviour and
      // reuses the existing invoice, which can leave a customer looking at a
      // QR code that is no longer payable. Returning false here would be more
      // correct, but it changes custodial checkout behaviour and belongs in a
      // change that can be verified against a live Blink account.
      if ($invoice === null) {
        return true;
      }

      return ($invoice['status'] ?? null) !== 'EXPIRED';
    } catch (\Throwable $e) {
      Logger::debug($e->getMessage());
    }

    return false;
  }

  /**
   * Create an invoice on Blink.
   */
  protected function createInvoice(\WC_Order $order) {
    // In case some plugins customizing the order number we need to pass that along, defaults to internal ID.
    $orderNumber = $order->get_order_number();
    Logger::debug(
      'Got order number: ' . $orderNumber . ' and order ID: ' . $order->get_id()
    );

    $redirectUrl = $this->get_return_url($order);
    Logger::debug('Redirect url to: ' . $redirectUrl);

    // unlike method signature suggests, it returns string.
    $amount = floatval($order->get_total());
    $currency = $order->get_currency();

    try {
      Logger::debug('Creating invoice with amount: ' . $amount);
      Logger::debug('Creating invoice with currency: ' . $currency);
      $invoice = $this->apiHelper->createInvoice($amount, $currency, $orderNumber);

      if (!$invoice) {
        Logger::debug('Invoice creation returned no invoice for order ' . $orderNumber);
        return null;
      }

      $order->update_meta_data('blink_redirect', $invoice['redirectUrl']);
      $order->update_meta_data('blink_id', $invoice['paymentHash']);
      $order->update_meta_data('blink_payment_request', $invoice['paymentRequest']);

      // Non-custodial invoices carry a LUD-21 verify URL used for polling.
      if (!empty($invoice['verifyUrl'])) {
        $order->update_meta_data('blink_account_type', 'non_custodial');
        $order->update_meta_data('blink_verify_url', $invoice['verifyUrl']);
      }
      if (!empty($invoice['satoshis'])) {
        $order->update_meta_data('blink_satoshis', $invoice['satoshis']);
      }
      if (!empty($invoice['createdAt'])) {
        $order->update_meta_data('blink_created_at', $invoice['createdAt']);
      }
      if (!empty($invoice['expiresAt'])) {
        $order->update_meta_data('blink_expires_at', $invoice['expiresAt']);
      }

      $order->save();

      return $invoice;
    } catch (\Throwable $e) {
      Logger::debug($e->getMessage(), true);
    }

    return null;
  }

  protected function processOrderStatus(
    \WC_Order $order,
    string $context = 'webhook'
  ): string {
    Logger::debug('Updating status for order: ' . $order->get_id());

    $applier = $this->services->statusApplier();
    if ($applier->isProtected($order)) {
      return $applier->apply($order, 'PENDING', $context);
    }

    $invoiceId = $order->get_meta('blink_id');
    if (!$invoiceId) {
      return 'PENDING';
    }

    try {
      Logger::debug('Trying to fetch existing invoice from Blink for hash ' . $invoiceId);
      $invoice = $this->apiHelper->getInvoiceCustodial($invoiceId);
      $status = is_array($invoice)
        ? (string) ($invoice['status'] ?? 'PENDING')
        : 'PENDING';
      Logger::debug('Invoice status: ' . $status);

      return $applier->apply($order, $status, $context);
    } catch (\Throwable $e) {
      Logger::debug($e->getMessage(), true);
    }

    return 'PENDING';
  }

  /**
   * Renders the on-site pay page for non-custodial (lightning address) orders.
   *
   * Displays the BOLT11 invoice as a QR code plus copy button and enqueues the
   * polling script that watches the LUD-21 verify endpoint for settlement.
   *
   * @param int $order_id Order ID (provided by woocommerce_receipt_{id}).
   */
  public function renderPayPage($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) {
      return;
    }

    $record = new WcOrderRecord($order);
    $repository = $this->services->invoiceRepository();

    // Only handle non-custodial orders here; custodial orders redirect off-site.
    if (!$repository->isNonCustodial($record)) {
      return;
    }

    $invoice = $repository->load($record);
    $paymentRequest = $invoice?->paymentRequest;
    if ($invoice === null || !$paymentRequest) {
      echo '<p>' .
        esc_html__(
          'Could not load the Lightning invoice for this order. Please contact the store.',
          'blink-for-woocommerce'
        ) .
        '</p>';
      return;
    }

    // If the order is already paid (e.g. page reloaded), send to the received page.
    if ($order->is_paid()) {
      wp_safe_redirect($order->get_checkout_order_received_url());
      exit();
    }

    $this->enqueuePayScripts();

    $lightningUri = 'lightning:' . strtoupper($paymentRequest);
    $satoshis = $invoice->satoshis;

    // The deadline is the invoice's own expiry plus the same grace the server
    // allows. The previous 30-minute cap was shorter than the invoice itself,
    // so a customer returning at minute 31 was told to place the order again
    // while the QR code on screen was still perfectly payable.
    $deadline =
      $invoice->expiresAt > 0
        ? $invoice->expiresAt +
          \Blink\WC\NonCustodial\SettlementService::EXPIRY_GRACE_SECONDS
        : time() + HOUR_IN_SECONDS;

    // wp_localize_script() casts every value to a string, which is why the
    // client's `typeof deadline === 'number'` check could never be true and
    // the page polled forever. wp_json_encode preserves the types.
    wp_add_inline_script(
      'blink-pay',
      'var BlinkPay = ' .
        wp_json_encode([
          'ajaxUrl' => admin_url('admin-ajax.php'),
          'nonce' => wp_create_nonce('blink-pay-nonce'),
          'orderId' => $order->get_id(),
          'orderKey' => $order->get_order_key(),
          'paymentRequest' => $paymentRequest,
          'lightningUri' => $lightningUri,
          'redirectUrl' => $order->get_checkout_order_received_url(),
          'pollInterval' => 3000,
          // Unix timestamp (seconds) after which the client stops polling.
          'deadline' => $deadline,
          'i18n' => [
            'copied' => __('Copied!', 'blink-for-woocommerce'),
            'copy' => __('Copy invoice', 'blink-for-woocommerce'),
            'paid' => __('Payment received! Redirecting…', 'blink-for-woocommerce'),
            'expired' => __(
              'This invoice has expired. Please place the order again.',
              'blink-for-woocommerce'
            ),
            'review' => __(
              'Payment received. This order is being reviewed before it is completed.',
              'blink-for-woocommerce'
            ),
            'unreachable' => __(
              'We cannot reach the payment server right now. Any payment you have made is safe.',
              'blink-for-woocommerce'
            ),
            'invalid' => __(
              'This payment session is no longer valid. Please reload the page.',
              'blink-for-woocommerce'
            ),
            'checkAgain' => __('Check again', 'blink-for-woocommerce'),
          ],
        ]) .
        ';',
      'before'
    );
    ?>
    <section class="blink-pay-container" id="blink-pay">
      <h2><?php echo esc_html__(
        'Pay with Bitcoin (Lightning)',
        'blink-for-woocommerce'
      ); ?></h2>
      <?php if ($satoshis > 0) { ?>
        <p class="blink-pay-amount">
          <?php echo esc_html(
            sprintf(
              /* translators: %s: amount in satoshis */
              __('%s sats', 'blink-for-woocommerce'),
              number_format_i18n($satoshis)
            )
          ); ?>
        </p>
      <?php } ?>
      <div class="blink-pay-qr" id="blink-pay-qr" data-uri="<?php echo esc_attr(
        $lightningUri
      ); ?>"></div>
      <p class="blink-pay-invoice">
        <code id="blink-pay-bolt11"><?php echo esc_html($paymentRequest); ?></code>
      </p>
      <div class="blink-pay-actions">
        <a class="button" href="<?php echo esc_attr($lightningUri); ?>">
          <?php echo esc_html__('Open in wallet', 'blink-for-woocommerce'); ?>
        </a>
        <button type="button" class="button" id="blink-pay-copy">
          <?php echo esc_html__('Copy invoice', 'blink-for-woocommerce'); ?>
        </button>
      </div>
      <p class="blink-pay-status" id="blink-pay-status">
        <?php echo esc_html__('Waiting for payment…', 'blink-for-woocommerce'); ?>
      </p>
    </section>
    <?php
  }

  /**
   * Registers and enqueues the pay-page assets (QR generator + poller).
   */
  protected function enqueuePayScripts(): void {
    wp_register_style(
      'blink-pay',
      BLINK_PLUGIN_URL . 'assets/css/pay.css',
      [],
      BLINK_VERSION
    );
    wp_enqueue_style('blink-pay');

    wp_register_script(
      'blink-qrcode',
      BLINK_PLUGIN_URL . 'assets/js/frontend/qrcode.min.js',
      [],
      BLINK_VERSION,
      true
    );
    wp_register_script(
      'blink-pay',
      BLINK_PLUGIN_URL . 'assets/js/frontend/pay.js',
      ['blink-qrcode'],
      BLINK_VERSION,
      true
    );
    wp_enqueue_script('blink-qrcode');
    wp_enqueue_script('blink-pay');
  }

  /**
   * AJAX endpoint polled by the pay page to detect settlement of a
   * non-custodial invoice via its LUD-21 verify URL.
   */
  /**
   * Reports settlement to the pay page.
   *
   * The browser no longer drives verification. It reads the last observation,
   * and only triggers a live check when that observation is stale, this client
   * is within its request budget, and no other request for the order is
   * already in flight. Ten open tabs therefore collapse to one outbound
   * request per cache window instead of ten every three seconds.
   */
  public function ajaxCheckInvoice(): void {
    check_ajax_referer('blink-pay-nonce', 'nonce');

    $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $orderKey = isset($_POST['order_key'])
      ? sanitize_text_field(wp_unslash($_POST['order_key']))
      : '';

    $order = $orderId ? wc_get_order($orderId) : null;
    if (
      !$order instanceof \WC_Order ||
      !hash_equals($order->get_order_key(), $orderKey)
    ) {
      wp_send_json_error(['message' => 'Invalid order.'], 403);
    }

    $record = new WcOrderRecord($order);

    // This endpoint exists for the non-custodial pay page. Without this check
    // any valid order id and key drives a settlement check, custodial orders
    // included.
    if (!$this->services->invoiceRepository()->isNonCustodial($record)) {
      wp_send_json_error(['message' => 'Invalid order.'], 403);
    }

    if ($order->is_paid()) {
      wp_send_json_success([
        'status' => 'PAID',
        'redirect' => $order->get_checkout_order_received_url(),
      ]);
    }

    $settlement = $this->services->settlement();
    $budget = $this->services->pollBudget();

    $useCached =
      $settlement->isCacheFresh($record) || !$budget->allowIp($this->clientIp());
    $outcome = $useCached ? $settlement->cached($record) : $settlement->poll($record);

    if ($outcome->status === SettlementStatus::Unknown) {
      // Nothing was learned; report the last real observation instead.
      $outcome = $settlement->cached($record);
    }
    $status = $outcome->status;

    // The same applier the background job uses, so a status reached through
    // the pay page and one reached by the scheduler behave identically.
    $this->services->outcomeApplier()->applyOutcome($record, $outcome);

    wp_send_json_success([
      'status' => $status->value,
      'redirect' =>
        $status === SettlementStatus::Paid
          ? $order->get_checkout_order_received_url()
          : null,
    ]);
  }

  /**
   * The client address used for per-IP budgeting.
   *
   * Only REMOTE_ADDR is trusted: honouring X-Forwarded-For by default would
   * make the budget trivially bypassable by anyone sending the header. Sites
   * behind a proxy can filter this.
   */
  private function clientIp(): string {
    $ip = isset($_SERVER['REMOTE_ADDR'])
      ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
      : '';

    return (string) apply_filters('blink_client_ip', $ip);
  }
}
