<?php
/**
 * Plugin Name:     Blink For Woocommerce
 * Plugin URI:      https://wordpress.org/plugins/blink-for-woocommerce/
 * Description:     Blink is a free and open-source bitcoin wallet which allows you to receive payments in Bitcoin and stablesats directly, with no fees or transaction cost.
 * Author:          Blink
 * Author URI:      https://blink.sv
 * License:         MIT
 * License URI:     https://github.com/blinkbitcoin/blink-for-woocommerce/blob/main/license.txt
 * Text Domain:     blink-for-woocommerce
 * Domain Path:     /languages
 * Version:         0.1.1
 *
 * @package         Blink_For_Woocommerce
 */

use Blink\WC\Admin\Notice;
use Blink\WC\Helpers\Logger;
use Blink\WC\Gateway\BlinkLnGateway;

defined('ABSPATH') || exit();
define('BLINK_VERSION', '0.1.1');
define('BLINK_VERSION_KEY', 'blink_version');
define('BLINK_PLUGIN_FILE_PATH', plugin_dir_path(__FILE__));
define('BLINK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BLINK_PLUGIN_ID', 'blink-for-woocommerce');

class BlinkWCPlugin {
  private static $instance;

  public function __construct() {
    $this->includes();

    add_action(
      'woocommerce_thankyou_blink_default',
      ['BlinkWCPlugin', 'orderStatusThankYouPage'],
      10,
      1
    );
    add_action('wp_ajax_blink_notifications', [$this, 'processAjaxNotification']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);

    // TODO: add process to run the updates.
    // \Blink\WC\Helper\UpdateManager::processUpdates();

    if (is_admin()) {
      // Register our custom global settings page.
      add_filter('woocommerce_get_settings_pages', function ($settings) {
        $settings[] = new \Blink\WC\Admin\GlobalSettings();

        return $settings;
      });

      $this->dependenciesNotification();
      $this->notConfiguredNotification();
      $this->submitReviewNotification();
    }
  }

  public function includes(): void {
    $autoloader = BLINK_PLUGIN_FILE_PATH . 'vendor/autoload.php';
    if (file_exists($autoloader)) {
      /** @noinspection PhpIncludeInspection */
      require_once $autoloader;
    }

    // Make sure WP internal functions are available.
    if (!function_exists('is_plugin_active')) {
      include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
  }

  /**
   * Add scripts to admin pages.
   */
  public function enqueueAdminScripts(): void {
    wp_enqueue_script(
      'blink-notifications',
      plugin_dir_url(__FILE__) . 'assets/js/backend/notifications.js',
      ['jquery'],
      BLINK_VERSION,
      true
    );
    wp_localize_script('blink-notifications', 'BlinkNotifications', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('blink-notifications-nonce'),
    ]);
  }

  public static function initPaymentGateways($gateways): array {
    $gateways[] = BlinkLnGateway::class;
    return $gateways;
  }

  /**
   * Handles the AJAX callback to dismiss review notification.
   */
  public function processAjaxNotification() {
    check_ajax_referer('blink-notifications-nonce', 'nonce');
    // Dismiss review notice for 30 days.
    set_transient('blink_review_dismissed', true, DAY_IN_SECONDS * 30);
    wp_send_json_success();
  }

  /**
   * Checks and displays notice on admin dashboard if PHP version is too low or WooCommerce not installed.
   */
  public function dependenciesNotification() {
    // Check PHP version.
    if (version_compare(PHP_VERSION, '7.4', '<')) {
      $versionMessage = sprintf(
        'Your PHP version is %s but Blink Payment plugin requires version 7.4+.',
        PHP_VERSION
      );
      Notice::addNotice('error', $versionMessage);
    }

    // Check if WooCommerce is installed.
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
      $wcMessage =
        'WooCommerce seems to be not installed. Make sure you do before you activate Blink Payment Gateway.';
      Notice::addNotice('error', $wcMessage);
    }

    // Check if PHP cURL is available.
    if (!function_exists('curl_init')) {
      $curlMessage =
        'The PHP cURL extension is not installed. Make sure it is available otherwise this plugin will not work.';
      Notice::addNotice('error', $curlMessage);
    }
  }

  /**
   * Displays notice (and link to config page) on admin dashboard if the plugin is not configured yet.
   */
  public function notConfiguredNotification(): void {
    if (!\Blink\WC\Helpers\BlinkApiHelper::getConfig()) {
      $message = sprintf(
        'Plugin not configured yet, please %1$sconfigure the plugin here%2$s',
        '<a href="' .
          esc_url(admin_url('admin.php?page=wc-settings&tab=blink_settings')) .
          '">',
        '</a>'
      );

      Notice::addNotice('error', $message);
    }
  }

  /**
   * Shows a notice on the admin dashboard to periodically ask for a review.
   */
  public function submitReviewNotification() {
    if (!get_transient('blink_review_dismissed')) {
      $reviewMessage = sprintf(
        'Thank you for using Blink for WooCommerce! If you like the plugin, we would love if you %1$sleave us a review%2$s.',
        '<a href="https://wordpress.org/support/plugin/blink-for-woocommerce/reviews/?filter=5#new-post" target="_blank">',
        '</a>'
      );

      Notice::addNotice('info', $reviewMessage, true, 'blink-review-notice');
    }
  }

  /**
   * Register WooCommerce Blocks support.
   */
  public static function blocksSupport() {
    if (
      class_exists(
        '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType'
      )
    ) {
      add_action('woocommerce_blocks_payment_method_type_registration', function (
        \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry
      ) {
        $payment_method_registry->register(new \Blink\WC\Blocks\BlinkLnGatewayBlocks());
      });
    }
  }

  /**
   * Gets the main plugin loader instance.
   *
   * Ensures only one instance can be loaded.
   */
  public static function instance(): \BlinkWCPlugin {
    if (null === self::$instance) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  /**
   * Displays the payment status on the thank you page.
   */
  public static function orderStatusThankYouPage($order_id) {
    if (!($order = wc_get_order($order_id))) {
      return;
    }

    $orderData = $order->get_data();
    $status = $orderData['status'];

    switch ($status) {
      case 'pending':
        $statusDesc = 'Waiting payment';
        break;
      case 'on-hold':
        $statusDesc = 'Waiting for payment settlement';
        break;
      case 'processing':
        $statusDesc = 'Payment settled';
        break;
      case 'completed':
        $statusDesc = 'Order completed';
        break;
      case 'failed':
      case 'cancelled':
        $statusDesc = 'Payment failed';
        break;
      default:
        $statusDesc = ucfirst($status);
        break;
    }

    echo "
      <section class='woocommerce-order-payment-status'>
          <h2 class='woocommerce-order-payment-status-title'>Order Status</h2>
          <p><strong>" .
      esc_html($statusDesc) .
      "</strong></p>
      </section>
    ";
  }
}

// Start everything up.
function init_blink_plugin() {
  \BlinkWCPlugin::instance();
}

/**
 * Bootstrap stuff on init.
 */
add_action('init', function () {
  // Adding textdomain and translation support.
  load_plugin_textdomain(
    'blink-for-woocommerce',
    false,
    dirname(plugin_basename(__FILE__)) . '/languages/'
  );
  // Setting up and handling custom endpoint for api key redirect from Blink.
  add_rewrite_endpoint('blink-settings-callback', EP_ROOT);
  // Flush rewrite rules only once after activation.
  if (!get_option('blink_permalinks_flushed')) {
    flush_rewrite_rules(false);
    update_option('blink_permalinks_flushed', 1);
  }
});

// Action links on plugin overview.
add_filter(
  'plugin_action_links_blink-for-woocommerce/blink-for-woocommerce.php',
  function ($links) {
    // Settings link.
    $settings_url = esc_url(
      add_query_arg(
        [
          'page' => 'wc-settings',
          'tab' => 'blink_settings',
        ],
        get_admin_url() . 'admin.php'
      )
    );

    $settings_link = sprintf('<a href="%s">Settings</a>', esc_url($settings_url));

    $logs_link = sprintf(
      '<a href="%s" target="_blank">Debug log</a>',
      esc_url(Logger::getLogFileUrl())
    );

    $docs_link = sprintf(
      '<a href="%s" target="_blank">Docs</a>',
      esc_url('https://dev.blink.sv/examples/woocommerce-plugin/')
    );

    $support_link = sprintf(
      '<a href="%s" target="_blank">Support</a>',
      esc_url('https://www.blink.sv/en/support')
    );

    array_unshift($links, $settings_link, $logs_link, $docs_link, $support_link);

    return $links;
  }
);

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function ($vars) {
  if (isset($vars['blink-settings-callback'])) {
    $vars['blink-settings-callback'] = true;
  }
  return $vars;
});

// Installation routine.
register_activation_hook(__FILE__, function () {
  update_option('blink_permalinks_flushed', 0);
  update_option(BLINK_VERSION_KEY, BLINK_VERSION);
});

// Initialize payment gateways and plugin.
add_filter('woocommerce_payment_gateways', ['BlinkWCPlugin', 'initPaymentGateways']);
add_action('plugins_loaded', 'init_blink_plugin', 0);

// Mark support for HPOS / COT.
add_action('before_woocommerce_init', function () {
  if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
      'custom_order_tables',
      __FILE__,
      true
    );
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
      'cart_checkout_blocks',
      __FILE__,
      true
    );
  }
});

// Register WooCommerce Blocks integration.
add_action('woocommerce_blocks_loaded', ['BlinkWCPlugin', 'blocksSupport']);
