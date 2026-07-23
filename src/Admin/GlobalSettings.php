<?php

declare(strict_types=1);

namespace Blink\WC\Admin;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\OrderStates;
use Blink\WC\Helpers\BlinkApiHelper;

class GlobalSettings extends \WC_Settings_Page {
  private BlinkApiHelper $apiHelper;

  public function __construct() {
    $this->id = 'blink_settings';
    $this->label = 'Blink Settings';
    $this->apiHelper = new BlinkApiHelper();

    // NOTE: The custom field renderers (order_states, blink_custom_markup) are
    // registered once in the plugin bootstrap (blink-for-woocommerce.php), NOT here.
    // WooCommerce instantiates the settings page multiple times per request via the
    // woocommerce_get_settings_pages filter; registering the render callbacks in this
    // constructor would attach a distinct per-instance callback each time. The
    // "blink_custom_markup" type is namespaced to avoid colliding with other plugins
    // (e.g. BTCPay for WooCommerce) that use a generic "custom_markup" type/hook.

    if (is_admin()) {
      // Register and include JS.
      wp_enqueue_script('blink_global_settings');
      wp_localize_script('blink_global_settings', 'BlinkGlobalSettings', [
        'url' => admin_url('admin-ajax.php'),
        'apiNonce' => wp_create_nonce('blink-api-url-nonce'),
      ]);

      // Register and include CSS.
      wp_register_style(
        'blink_admin_styles',
        BLINK_PLUGIN_URL . 'assets/css/admin.css',
        [],
        BLINK_VERSION
      );
      wp_enqueue_style('blink_admin_styles');
    }
    parent::__construct();
  }

  public function output(): void {
    echo '<h1>Blink Payment settings</h1>';
    $settings = $this->get_settings_for_default_section();
    \WC_Admin_Settings::output_fields($settings);
  }

  public function get_settings_for_default_section(): array {
    return $this->getGlobalSettings();
  }

  private function connectedMarkup(): string {
    return '<p class="blink-connection-success">Connected.</p>';
  }

  private function notConnectedMarkup(string $message): string {
    return '<p class="blink-connection-error">' . $message . '</p>';
  }

  private function getSetupStatusMarkup(): string {
    $accountType = get_option('blink_account_type', 'custodial');

    if ($accountType === 'non_custodial') {
      $lnAddress = get_option('blink_ln_address');
      if ($lnAddress && BlinkApiHelper::verifyLnAddress($lnAddress)) {
        return $this->connectedMarkup();
      }
      return $this->notConnectedMarkup(
        'Not connected. Please configure your Blink lightning address.'
      );
    }

    $env = get_option('blink_env');
    $apiKey = get_option('blink_api_key');
    if ($env && $apiKey && BlinkApiHelper::verifyApiKey($env, $apiKey)) {
      return $this->connectedMarkup();
    }
    return $this->notConnectedMarkup('Not connected. Please configure your api key.');
  }

  public function getGlobalSettings(): array {
    Logger::debug('Entering Global Settings form.');

    $setupStatus = $this->getSetupStatusMarkup();

    return [
      // Section connection.
      'title_connection' => [
        'title' => 'Connection settings',
        'type' => 'title',
        'desc' => sprintf(
          'This plugin version is %1$s and your PHP version is %2$s. Check out our <a href="https://dev.blink.sv/examples/woocommerce-plugin/" target="_blank">installation instructions</a>. If you need assistance, please come on our <a href="https://chat.blink.sv" target="_blank">chat</a>. Thank you for using Blink!',
          BLINK_VERSION,
          PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
        ),
        'id' => 'blink_connection',
      ],
      'blink_account_type' => [
        'title' => 'Account Type',
        'type' => 'select',
        'options' => [
          'custodial' => 'Custodial (API key)',
          'non_custodial' => 'Non-custodial (Lightning address)',
        ],
        'default' => 'custodial',
        'desc' =>
          'Custodial uses a Blink API key. Non-custodial uses your Blink lightning address (self-custody) and does not require an API key or a Blink dashboard webhook.',
        'desc_tip' => true,
        'id' => 'blink_account_type',
      ],
      'blink_env' => [
        'title' => 'Blink Environment',
        'type' => 'select',
        'options' => [
          'blink' => 'Blink',
          'staging' => 'Staging',
        ],
        'default' => 'Blink',
        'desc' => 'Blink instance.',
        'desc_tip' => true,
        'id' => 'blink_env',
      ],
      'blink_wallet_type' => [
        'title' => 'Blink Wallet',
        'type' => 'select',
        'options' => [
          'bitcoin' => 'Bitcoin',
          'stablesats' => 'USD',
        ],
        'default' => 'Blink',
        'desc' => 'Blink Wallet',
        'desc_tip' => true,
        'id' => 'blink_wallet_type',
      ],
      'api_key' => [
        'title' => 'Blink API Key',
        'type' => 'text',
        'desc' =>
          'Your Blink API Key (custodial account type only). If you do not have any yet use <a target="_blank" href="https://dashboard.blink.sv/api-keys">Blink dashboard</a> to get a new one.<br />IMPORTANT: Create an API key with only READ & RECEIVE scopes. <br />⚠️ Using an API key with WRITE scope could result in loss of funds ⚠️',
        'default' => '',
        'id' => 'blink_api_key',
      ],
      'ln_address' => [
        'title' => 'Blink Lightning Address',
        'type' => 'text',
        'desc' =>
          'Your Blink lightning address (non-custodial account type only), e.g. <code>yourname@blink.sv</code>. Payments are received directly to your self-custodial Blink wallet. No API key or Blink dashboard webhook is required.',
        'default' => '',
        'placeholder' => 'yourname@blink.sv',
        'id' => 'blink_ln_address',
      ],
      'webhook_url' => [
        'title' => 'Webhook Url',
        'type' => 'blink_custom_markup',
        'markup' =>
          WC()->api_request_url('blink_default') .
          '<p class="description"> Custodial account type only. Please use <a target="_blank" href="https://dashboard.blink.sv/callback">Blink dashboard</a> to set it up, and make sure the callback endpoint is <strong>enabled</strong> (a disabled endpoint silently stops payment notifications and orders will stay in "Pending payment"). Not required for non-custodial (lightning address) accounts.</p>',
        'id' => 'blink_webhook_url',
      ],
      'status' => [
        'title' => 'Setup status',
        'type' => 'blink_custom_markup',
        'markup' => $setupStatus,
        'id' => 'blink_status',
      ],
      'sectionend_connection' => [
        'type' => 'sectionend',
        'id' => 'blink_connection',
      ],
      // Section general.
      'title' => [
        'title' => 'General settings',
        'type' => 'title',
        'id' => 'blink_gf',
      ],
      'default_description' => [
        'title' => 'Default Customer Message',
        'type' => 'textarea',
        'desc' =>
          'Message to explain how the customer will be paying for the purchase. Can be overwritten on a per gateway basis.',
        'default' => 'You will be redirected to Blink to complete your purchase.',
        'desc_tip' => true,
        'id' => 'blink_default_description',
      ],
      'order_states' => [
        'type' => 'order_states',
        'id' => 'blink_order_states',
      ],
      'protect_orders' => [
        'title' => 'Protect order status',
        'type' => 'checkbox',
        'default' => 'yes',
        'desc' =>
          'Protects order status from changing if it is already "processing" or "completed". This will protect against orders getting cancelled via webhook if they were paid in the meantime with another payment gateway. Default is ON.',
        'id' => 'blink_protect_order_status',
      ],
      'debug' => [
        'title' => 'Debug Log',
        'type' => 'checkbox',
        'default' => 'yes',
        'desc' => sprintf(
          'Enable logging <a href="%s" class="button">View Logs</a>',
          Logger::getLogFileUrl()
        ),
        'id' => 'blink_debug',
      ],
      'sectionend' => [
        'type' => 'sectionend',
        'id' => 'blink_gf',
      ],
    ];
  }

  /**
   * On saving the settings form make sure the configured account works.
   */
  public function save() {
    Logger::debug('Saving GlobalSettings.');

    // Nonce validation is handled by parent::save().
    $accountType = !empty($_POST['blink_account_type'])
      ? sanitize_text_field(wp_unslash($_POST['blink_account_type']))
      : 'custodial';
    // Constrain to the known set so an unexpected value cannot be persisted.
    if (!in_array($accountType, ['custodial', 'non_custodial'], true)) {
      $accountType = 'custodial';
    }

    if ($accountType === 'non_custodial') {
      $this->validateNonCustodialOnSave();
    }
    if ($accountType === 'custodial') {
      $this->validateCustodialOnSave();
    }

    parent::save();
  }

  private function validateNonCustodialOnSave(): void {
    if (empty($_POST['blink_ln_address'])) {
      $message = 'Did not try to connect because the Blink lightning address is missing.';
      Notice::addNotice('warning', $message);
      Logger::debug($message);
      return;
    }

    $lnAddress = sanitize_text_field(wp_unslash($_POST['blink_ln_address']));
    if (BlinkApiHelper::verifyLnAddress($lnAddress)) {
      return;
    }

    $message =
      'Could not verify this Blink lightning address. Please check that it is valid and reachable (format: yourname@blink.sv).';
    Notice::addNotice('error', $message);
    Logger::debug($message, true);
  }

  private function validateCustodialOnSave(): void {
    if (empty($_POST['blink_env']) || empty($_POST['blink_api_key'])) {
      $message =
        'Did not try to connect to Blink API because one of the required information was missing: Environment or api key';
      Notice::addNotice('warning', $message);
      Logger::debug($message);
      return;
    }

    $apiEnv = sanitize_text_field(wp_unslash($_POST['blink_env']));
    $apiKey = sanitize_text_field(wp_unslash($_POST['blink_api_key']));
    if (BlinkApiHelper::verifyApiKey($apiEnv, $apiKey)) {
      return;
    }

    $message =
      'Error fetching data for this API key from server. Please check if the API key is valid.';
    Notice::addNotice('error', $message);
    Logger::debug($message, true);
  }

  public static function output_custom_markup_field($value) {
    $title = '&nbsp;';
    if (!empty($value['title'])) {
      $title = esc_html($value['title']);
    }

    echo '<tr valign="top">';
    echo '<th scope="row" class="titledesc">' . $title . '</th>';
    echo '<td class="forminp" id="' . esc_attr($value['id']) . '">';
    echo wp_kses_post($value['markup']);
    echo '</td>';
    echo '</tr>';
  }
}
