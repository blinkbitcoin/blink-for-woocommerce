<?php

namespace Blink\WC\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Blink\WC\Gateway\BlinkLnGateway;
use Blink\WC\Helpers\Logger;

/**
 * Blink payment method integration
 *
 * @since 3.0.0
 */
final class BlinkLnGatewayBlocks extends AbstractPaymentMethodType {
  /**
   * The gateway instance.
   */
  private $gateway;

  /**
   * Payment method name/id/slug.
   */
  protected $name = 'blink_default';

  /**
   * Initializes the payment method type.
   */
  public function initialize(): void {
    $this->settings = get_option('woocommerce_blink_default_settings', []);
    $gateways = \WC()->payment_gateways->payment_gateways();
    $this->gateway = $gateways[$this->name];
  }

  /**
   * Returns if this payment method should be active. If false, the scripts will not be enqueued.
   */
  public function is_active(): bool {
    return $this->gateway->is_available();
  }

  /**
   * Returns an array of supported features.
   *
   * @return string[]
   */
  public function get_supported_features() {
    return $this->gateway->supports;
  }

  /**
   * Returns an array of scripts/handles to be registered for this payment method.
   */
  public function get_payment_method_script_handles(): array {
    $script_url = BLINK_PLUGIN_URL . 'assets/js/frontend/blocks.js';
    $script_asset_path = BLINK_PLUGIN_FILE_PATH . 'assets/js/frontend/blocks.asset.php';
    $script_asset = $this->loadScriptAsset($script_asset_path);

    wp_register_script(
      'blink-gateway-blocks',
      $script_url,
      $script_asset['dependencies'],
      $script_asset['version'],
      true
    );

    if (function_exists('wp_set_script_translations')) {
      wp_set_script_translations(
        'blink-gateway-blocks',
        'blink-for-woocommerce',
        BLINK_PLUGIN_FILE_PATH . 'languages/'
      );
    }

    return ['blink-gateway-blocks'];
  }

  /**
   * Loads the optional dependency manifest emitted by the WordPress build.
   *
   * @return array{dependencies: list<string>, version: string}
   */
  private function loadScriptAsset(string $path): array {
    if (!is_file($path)) {
      return [
        'dependencies' => [],
        'version' => BLINK_VERSION
      ];
    }

    $asset = require $path;
    if (!is_array($asset)) {
      return [
        'dependencies' => [],
        'version' => BLINK_VERSION
      ];
    }

    $dependencies = [];
    if (isset($asset['dependencies']) && is_array($asset['dependencies'])) {
      foreach ($asset['dependencies'] as $dependency) {
        if (is_string($dependency)) {
          $dependencies[] = $dependency;
        }
      }
    }

    return [
      'dependencies' => $dependencies,
      'version' =>
        isset($asset['version']) && is_string($asset['version'])
          ? $asset['version']
          : BLINK_VERSION
    ];
  }

  /**
   * Returns an array of key=>value pairs of data made available to the payment methods script.
   */
  public function get_payment_method_data(): array {
    return [
      'title' => $this->get_setting('title'),
      'description' => $this->get_setting('description'),
      'supports' => $this->get_supported_features()
    ];
  }
}
