<?php

declare(strict_types=1);

namespace Blink\WC\Gateway;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\BlinkApiHelper;
use Blink\WC\Helpers\OrderStates;

class BlinkLnGateway extends \WC_Payment_Gateway {
  const ICON_MEDIA_OPTION = 'icon_media_id';
  private $apiHelper;
  public $debug_php_version;
  public $debug_plugin_version;

  public function __construct() {
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
    $icon = null;
    if ($mediaId = $this->get_option(self::ICON_MEDIA_OPTION)) {
      if ($customIcon = wp_get_attachment_image_src($mediaId)[0]) {
        $icon = $customIcon;
      }
    }

    return $icon ?? BLINK_PLUGIN_URL . 'assets/images/blink-logo.png';
  }

  public function process_admin_options() {
    // Store media id.
    $iconFieldName = 'woocommerce_' . $this->getId() . '_' . self::ICON_MEDIA_OPTION;

    // nonce validation is not required here because it is done by parent::process_admin_options()
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

    $order = wc_get_order($order_id);
    if ($order->get_id() === 0) {
      $message = 'Could not load order id ' . $orderId . ', aborting.';
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
    if ($invoice = $this->createInvoice($order)) {
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
  }

  /**
   * Processes a payment for a non-custodial (lightning address) account.
   *
   * Creates a BOLT11 invoice via the LNURL flow and sends the buyer to the
   * on-site order-pay page, where the invoice is displayed and polled.
   */
  protected function processPaymentNonCustodial(\WC_Order $order): array {
    // Reuse an existing, still-valid invoice if present.
    if (!$order->get_meta('blink_id') || !$order->get_meta('blink_verify_url')) {
      Logger::debug('Creating non-custodial invoice on Blink');
      if (!$this->createInvoice($order)) {
        throw new \Exception(
          "Can't create the Lightning invoice. Please try again or contact us if the problem persists."
        );
      }
    }

    return [
      'result' => 'success',
      'redirect' => $order->get_checkout_payment_url(true),
    ];
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
        'meta_key' => 'blink_id',
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
    if ($invoiceId = $order->get_meta('blink_id')) {
      try {
        Logger::debug(
          'Trying to fetch existing invoice from Blink for hash ' . $invoiceId
        );
        $invoice = $this->apiHelper->getInvoice($invoiceId);
        $invalidStates = ['EXPIRED'];
        if (in_array($invoice['status'], $invalidStates)) {
          return false;
        }

        return true;
      } catch (\Throwable $e) {
        Logger::debug($e->getMessage());
      }
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

      $order->save();

      return $invoice;
    } catch (\Throwable $e) {
      Logger::debug($e->getMessage(), true);
    }

    return null;
  }

  protected function processOrderStatus(\WC_Order $order): string {
    Logger::debug('Updating status for order: ' . $order->get_id());
    // Check if the order is already in a final state, if so do not update it if the orders are protected.
    $protectOrders = get_option('blink_protect_order_status', 'no');

    Logger::debug('Protect order: ' . $protectOrders);

    if ($protectOrders === 'yes') {
      // Check if the order status is either 'processing' or 'completed'
      if ($order->has_status(['processing', 'completed'])) {
        $note =
          'Webhook received from Blink, but the order is already processing or completed, skipping to update order status. Please manually check if everything is alright.';
        $order->add_order_note($note);
        return $order->has_status('completed') ? 'PAID' : 'PENDING';
      }
    }

    if ($invoiceId = $order->get_meta('blink_id')) {
      // Get configured order states or fall back to defaults.
      if (!($configuredOrderStates = get_option('blink_order_states'))) {
        $configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
      }
      Logger::debug('Configured Order States: ' . implode(', ', $configuredOrderStates));

      try {
        Logger::debug(
          'Trying to fetch existing invoice from Blink for hash ' . $invoiceId
        );
        // Non-custodial orders are settled by polling their LUD-21 verify URL.
        $verifyUrl = $order->get_meta('blink_verify_url') ?: null;
        $invoice = $this->apiHelper->getInvoice($invoiceId, $verifyUrl);
        $invoiceStatus = $invoice['status'];
        Logger::debug('Invoice status: ' . $invoiceStatus);

        if ($invoiceStatus === 'EXPIRED') {
          $this->updateWCOrderStatus(
            $order,
            $configuredOrderStates[OrderStates::EXPIRED]
          );
          $order->add_order_note('Invoice expired.');
          return 'EXPIRED';
        }

        if ($invoiceStatus === 'PAID') {
          $this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PAID]);
          $order->add_order_note('Invoice payment settled.');
          return 'PAID';
        }
      } catch (\Throwable $e) {
        Logger::debug($e->getMessage(), true);
      }
    }

    return 'PENDING';
  }

  /**
   * Update WC order status (if a valid mapping is set).
   */
  public function updateWCOrderStatus(\WC_Order $order, string $status): void {
    if ($status !== OrderStates::IGNORE) {
      Logger::debug(
        'Updating order status from ' . $order->get_status() . ' to ' . $status
      );
      $order->update_status($status);
    }
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

    // Only handle non-custodial orders here; custodial orders redirect off-site.
    if ($order->get_meta('blink_account_type') !== 'non_custodial') {
      return;
    }

    $paymentRequest = $order->get_meta('blink_payment_request');
    $paymentHash = $order->get_meta('blink_id');
    if (!$paymentRequest || !$paymentHash) {
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
    $satoshis = (int) $order->get_meta('blink_satoshis');

    wp_localize_script('blink-pay', 'BlinkPay', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('blink-pay-nonce'),
      'orderId' => $order->get_id(),
      'orderKey' => $order->get_order_key(),
      'paymentRequest' => $paymentRequest,
      'lightningUri' => $lightningUri,
      'redirectUrl' => $order->get_checkout_order_received_url(),
      'pollInterval' => 3000,
    ]);
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
  public function ajaxCheckInvoice(): void {
    check_ajax_referer('blink-pay-nonce', 'nonce');

    $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $orderKey = isset($_POST['order_key'])
      ? sanitize_text_field(wp_unslash($_POST['order_key']))
      : '';

    $order = $orderId ? wc_get_order($orderId) : null;
    if (!$order || !hash_equals($order->get_order_key(), $orderKey)) {
      wp_send_json_error(['message' => 'Invalid order.'], 403);
    }

    if ($order->is_paid()) {
      wp_send_json_success([
        'status' => 'PAID',
        'redirect' => $order->get_checkout_order_received_url(),
      ]);
    }

    $status = $this->processOrderStatus($order);

    wp_send_json_success([
      'status' => $status,
      'redirect' => $status === 'PAID' ? $order->get_checkout_order_received_url() : null,
    ]);
  }
}
