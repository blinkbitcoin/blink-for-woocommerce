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

    // Register custom field type order_states with OrderStatesField class.
    add_action('woocommerce_admin_field_order_states', [
      new OrderStates(),
      'renderOrderStatesHtml',
    ]);
    add_action('woocommerce_admin_field_custom_markup', [
      $this,
      'output_custom_markup_field',
    ]);

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

  public function getGlobalSettings(): array {
    Logger::debug('Entering Global Settings form.');

    // Check setup status and prepare output.
    $storedApiKey = get_option('blink_api_key');
    $storedBlinkEnv = get_option('blink_env');

    $setupStatus = '<p class="blink-connection-error">
        Not connected. Please configure your api key.
      </p>';
    if ($storedBlinkEnv && $storedApiKey) {
      if (BlinkApiHelper::verifyApiKey($storedBlinkEnv, $storedApiKey)) {
        $setupStatus = '<p class="blink-connection-success">Connected.</p>';
      }
    }

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
          'stablesats' => 'Stablesats',
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
          'Your Blink API Key. If you do not have any yet use <a target="_blank" href="https://dashboard.blink.sv/api-keys">Blink dashboard</a> to get a new one.',
        'default' => '',
        'id' => 'blink_api_key',
      ],
      'webhook_url' => [
        'title' => 'Webhook Url',
        'type' => 'custom_markup',
        'markup' =>
          WC()->api_request_url('blink_default') .
          '<p class="description"> Please use <a target="_blank" href="https://dashboard.blink.sv/callback">Blink dashboard</a> to set it up.</p>',
        'id' => 'blink_webhook_url',
      ],
      'status' => [
        'title' => 'Setup status',
        'type' => 'custom_markup',
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
   * On saving the settings form make sure to check if the API key works and register a webhook if needed.
   */
  public function save() {
    // If we have url, storeID and apiKey we want to check if the api key works and register a webhook.
    Logger::debug('Saving GlobalSettings.');

    // nonce validation is not required here because it is done by parent::save()
    if (!empty($_POST['blink_env']) && !empty($_POST['blink_api_key'])) {
      $apiEnv = sanitize_text_field(wp_unslash($_POST['blink_env']));
      $apiKey = sanitize_text_field(wp_unslash($_POST['blink_api_key']));

      if (!BlinkApiHelper::verifyApiKey($apiEnv, $apiKey)) {
        $messageException =
          'Error fetching data for this API key from server. Please check if the API key is valid.';
        Notice::addNotice('error', $messageException);
        Logger::debug($messageException, true);
      }
    } else {
      $messageNotConnecting =
        'Did not try to connect to Blink API because one of the required information was missing: Environment or api key';
      Notice::addNotice('warning', $messageNotConnecting);
      Logger::debug($messageNotConnecting);
    }

    parent::save();
  }

  public function output_custom_markup_field($value) {
    echo '<tr valign="top">';
    if (!empty($value['title'])) {
      echo '<th scope="row" class="titledesc">' . esc_html($value['title']) . '</th>';
    } else {
      echo '<th scope="row" class="titledesc">&nbsp;</th>';
    }

    echo '<td class="forminp" id="' . esc_attr($value['id']) . '">';
    echo wp_kses_post($value['markup']);
    echo '</td>';
    echo '</tr>';
  }
}
