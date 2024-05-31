<?php

declare(strict_types=1);

namespace Blink\WC\Gateway;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\GaloyApiHelper;
use Blink\WC\Helpers\OrderStates;

class BlinkLnGateway extends \WC_Payment_Gateway {
  const ICON_MEDIA_OPTION = 'icon_media_id';
  private $apiHelper;
  public $debug_php_version;
  public $debug_plugin_version;

  public function __construct() {
    // Set the id first.
    $this->id = 'galoy_blink_default';

    // Debugging & informational settings.
    $this->debug_php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $this->debug_plugin_version = BLINK_VERSION;

    // General gateway setup.
    $this->icon = $this->getIcon();
    $this->has_fields = false;
    // $this->order_button_text = __( 'Proceed to Blink', 'blink-for-woocommerce' );

    // Load the settings.
    $this->init_form_fields();
    $this->init_settings();

    // Define user facing set variables.
    $this->title = $this->getTitle();
    $this->description = $this->getDescription();

    // Admin facing title and description.
    $this->method_title = 'Blink (Lightning)';
    $this->method_description = __(
      'Blink Bitcoin Lightning gateway.',
      'blink-for-woocommerce'
    );

    $this->apiHelper = new GaloyApiHelper();

    // Actions.
    add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [
      $this,
      'process_admin_options',
    ]);
    add_action('woocommerce_api_' . $this->getId(), [$this, 'processWebhook']);

    // Supported features.
    $this->supports = ['products'];
  }

  /**
   * Initialise Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title' => __('Enabled/Disabled', 'blink-for-woocommerce'),
        'type' => 'checkbox',
        'label' => __('Enable this payment gateway.', 'blink-for-woocommerce'),
        'default' => 'no',
        'value' => 'yes',
        'desc_tip' => false,
      ],
      'title' => [
        'title' => __('Title', 'blink-for-woocommerce'),
        'type' => 'safe_text',
        'description' => __(
          'Controls the name of this payment method as displayed to the customer during checkout.',
          'blink-for-woocommerce'
        ),
        'default' => $this->getTitle(),
        'desc_tip' => true,
      ],
      'description' => [
        'title' => __('Customer Message', 'blink-for-woocommerce'),
        'type' => 'textarea',
        'description' => __(
          'Message to explain how the customer will be paying for the purchase.',
          'blink-for-woocommerce'
        ),
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
    if ($mediaId = sanitize_key($_POST[$iconFieldName])) {
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
        'Galoy/Blink API connection not configured, aborting. Please go to settings and set it up.'
      );
      throw new \Exception(
        esc_html__(
          "Can't process order. Please contact us if the problem persists.",
          'blink-for-woocommerce'
        )
      );
    }

    $order = wc_get_order($order_id);
    if ($order->get_id() === 0) {
      $message = 'Could not load order id ' . $orderId . ', aborting.';
      Logger::debug($message, true);
      throw new \Exception(esc_html($message));
    }

    // Check for existing invoice and redirect instead.
    if ($this->validInvoiceExists($order)) {
      $existingInvoiceId = $order->get_meta('galoy_id');
      Logger::debug(
        'Found existing Galoy/Blink invoice and redirecting to it. Invoice id: ' .
          $existingInvoiceId
      );

      return [
        'result' => 'success',
        'invoiceId' => $existingInvoiceId,
        'orderCompleteLink' => $order->get_checkout_order_received_url(),
        'redirect' =>
          $this->apiHelper->getInvoiceRedirectUrl($existingInvoiceId) .
          '?callback=' .
          $order->get_checkout_order_received_url(),
      ];
    }

    // Create an invoice.
    Logger::debug('Creating invoice on Galoy/Blink');
    if ($invoice = $this->createInvoice($order)) {
      Logger::debug('Invoice creation successful, redirecting user.');
      return [
        'result' => 'success',
        'invoiceId' => $invoice['paymentHash'],
        'orderCompleteLink' => $order->get_checkout_order_received_url(),
        'redirect' =>
          $invoice['redirectUrl'] .
          '?callback=' .
          $order->get_checkout_order_received_url(),
      ];
    }
  }

  public function process_refund($order_id, $amount = null, $reason = '') {
    $errServer = 'Blink does not support refunds.';
    Logger::debug($errServer);
    return new \WP_Error('1', $errServer);
  }

  /**
   * Process webhooks from Galoy/Blink.
   */
  public function processWebhook() {
    Logger::debug('Galoy/Blink Webhook handler');
    if ($rawPostData = file_get_contents('php://input')) {
      try {
        $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);
        if (!isset($postData->transaction->initiationVia->paymentHash)) {
          Logger::debug('No Galoy/Blink invoiceId provided, aborting.');
          wp_die('No Galoy/Blink invoiceId provided, aborting.');
        }

        $invoiceId = $postData->transaction->initiationVia->paymentHash;
        if (empty($invoiceId)) {
          Logger::error('No Galoy/Blink invoiceId provided.');
          wp_die('No Galoy/Blink invoiceId provided, aborting.');
        }

        // Load the order by metadata field Blink_id
        $orders = wc_get_orders([
          'meta_key' => 'galoy_id',
          'meta_value' => $invoiceId,
        ]);

        // Abort if no orders found.
        if (count($orders) === 0) {
          Logger::debug(
            'Could not load order by Blink invoiceId: ' . $postData->invoiceId
          );
          wp_die('No order found for this invoiceId.', '', [
            'response' => 200,
          ]);
        }

        // Abort on multiple orders found.
        if (count($orders) > 1) {
          Logger::debug('Found multiple orders for invoiceId: ' . $postData->invoiceId);
          Logger::debug(print_r($orders, true));
          wp_die('Multiple orders found for this invoiceId, aborting.', '', [
            'response' => 200,
          ]);
        }

        $this->processOrderStatus($orders[0]);
      } catch (\Throwable $e) {
        Logger::debug('Error decoding webook payload: ' . $e->getMessage());
        Logger::debug($rawPostData);
      }
    }
  }

  /**
   * Checks if the order has already a Galoy/Blink invoice set and checks if it is still
   * valid to avoid creating multiple invoices for the same order on Blink end.
   *
   * @param int $orderId
   *
   * @return mixed Returns false if no valid invoice found or the invoice id.
   */
  protected function validInvoiceExists(\WC_Order $order): bool {
    if ($invoiceId = $order->get_meta('galoy_id')) {
      try {
        Logger::debug(
          'Trying to fetch existing invoice from Galoy/Blink for hash ' . $invoiceId
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
   * Create an invoice on Galoy/Blink.
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

      $order->update_meta_data('galoy_redirect', $invoice['redirectUrl']);
      $order->update_meta_data('galoy_id', $invoice['paymentHash']);
      $order->update_meta_data('galoy_payment_request', $invoice['paymentRequest']);
      $order->save();

      return $invoice;
    } catch (\Throwable $e) {
      Logger::debug($e->getMessage(), true);
    }

    return null;
  }

  protected function processOrderStatus(\WC_Order $order) {
    Logger::debug('Updating status for order: ' . $order->get_id());
    // Check if the order is already in a final state, if so do not update it if the orders are protected.
    $protectOrders = get_option('galoy_blink_protect_order_status', 'no');

    Logger::debug('Protect order: ' . $protectOrders);

    if ($protectOrders === 'yes') {
      // Check if the order status is either 'processing' or 'completed'
      if ($order->has_status(['processing', 'completed'])) {
        $note =
          'Webhook received from Galoy/Blink, but the order is already processing or completed, skipping to update order status. Please manually check if everything is alright.';
        $order->add_order_note($note);
        return;
      }
    }

    if ($invoiceId = $order->get_meta('galoy_id')) {
      // Get configured order states or fall back to defaults.
      if (!($configuredOrderStates = get_option('galoy_blink_order_states'))) {
        $configuredOrderStates = (new OrderStates())->getDefaultOrderStateMappings();
      }
      Logger::debug('Configured Order States: ' . implode(', ', $configuredOrderStates));

      try {
        Logger::debug(
          'Trying to fetch existing invoice from Galoy/Blink for hash ' . $invoiceId
        );
        $invoice = $this->apiHelper->getInvoice($invoiceId);
        $invoiceStatus = $invoice['status'];
        Logger::debug('Invoice status: ' . $invoiceStatus);

        if ($invoiceStatus === 'EXPIRED') {
          $this->updateWCOrderStatus(
            $order,
            $configuredOrderStates[OrderStates::EXPIRED]
          );
          $order->add_order_note(__('Invoice expired.', 'blink-for-woocommerce'));
          return;
        }

        if ($invoiceStatus === 'PAID') {
          $this->updateWCOrderStatus($order, $configuredOrderStates[OrderStates::PAID]);
          $order->add_order_note(__('Invoice payment settled.', 'blink-for-woocommerce'));
          return;
        }
      } catch (\Throwable $e) {
        Logger::debug($e->getMessage(), true);
      }
    }
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
}
