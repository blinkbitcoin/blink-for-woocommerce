<?php

declare( strict_types=1 );

namespace Blink\WC\Gateway;

use Blink\WC\Helpers\Logger;
use Blink\WC\Helpers\GaloyApiHelper;

class BlinkLnGateway extends \WC_Payment_Gateway {
	const ICON_MEDIA_OPTION = 'icon_media_id';
	private $apiHelper;

	public function __construct() {
		// Set the id first.
		$this->id = 'galoy_blink_default';

    // Debugging & informational settings.
		$this->debug_php_version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$this->debug_plugin_version = BLINK_VERSION;

    // General gateway setup.
		$this->icon              = $this->getIcon();
		$this->has_fields        = false;
		// $this->order_button_text = __( 'Proceed to Blink', 'blink-for-woocommerce' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

    // Define user facing set variables.
		$this->title        = $this->getTitle();
		$this->description  = $this->getDescription();

    // Admin facing title and description.
		$this->method_title = 'Blink (Lightning)';
		$this->method_description = __('Blink Bitcoin Lightning gateway.', 'blink-for-woocommerce');

    $this->apiHelper = new GaloyApiHelper();

		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->getId(), [$this, 'process_admin_options']);

    // Supported features.
		$this->supports = [ 'products' ];
  }

  /**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'       => __( 'Enabled/Disabled', 'blink-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable this payment gateway.', 'blink-for-woocommerce' ),
				'default'     => 'no',
				'value'       => 'yes',
				'desc_tip'    => false,
			],
			'title'       => [
				'title'       => __( 'Title', 'blink-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'Controls the name of this payment method as displayed to the customer during checkout.', 'blink-for-woocommerce' ),
				'default'     => $this->getTitle(),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Customer Message', 'blink-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'blink-for-woocommerce' ),
				'default'     => $this->getDescription(),
				'desc_tip'    => true,
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
		return $this->get_option('description', 'You will be redirected to Blink to complete your purchase.');
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
		 * Process webhooks from Galoy.
		 */
	public function processWebhook() {

	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->payment_complete();

		// Remove cart.
		// WC()->cart->empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
