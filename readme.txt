=== Blink For WooCommerce ===
Contributors: blink
Tags: Bitcoin, Lightning Network, WooCommerce, payment gateway
Requires at least: 4.5
Tested up to: 6.6.1
Requires PHP: 5.6
Stable tag: 0.1.1
License: MIT
License URI: https://github.com/blinkbitcoin/blink-for-woocommerce/blob/main/license.txt

A simple, fast and secure Bitcoin payment gateway for WooCommerce using [Blink](https://www.blink.sv/).

== Description ==

Blink For WooCommerce is a plugin that allows WooCommerce merchants to accept Bitcoin payments through the Lightning Network using [Blink](https://www.blink.sv/).

Key features of Blink For WooCommerce include:

* Instant Payments: Leveraging the Lightning Network, [Blink](https://www.blink.sv/) ensures that Bitcoin payments are processed instantly, providing a smooth checkout experience for customers.
* Low Transaction Fees: Enjoy significantly lower transaction fees compared to traditional payment methods, helping you save on processing costs.
* Stablesats Integration: Offers the ability to receive payments in Bitcoin while maintaining a stable value pegged to the US Dollar, reducing volatility risks.
* Easy Integration: Simple setup and configuration within WooCommerce, allowing you to start accepting Bitcoin payments quickly and easily.

For more information please visit [Plugin Repository](https://github.com/blinkbitcoin/blink-for-woocommerce/).

### Important Notice

This plugin relies on third-party APIs to function correctly. Specifically, it interacts with the following endpoints:

- **Blink API**: Used for processing payments through the Blink wallet.
  - **Service URL**: [https://api.blink.sv/graphql](https://api.blink.sv/graphql)
  - **Terms of Use**: [Blink Terms of Use](https://www.blink.sv/en/terms-conditions)
  - **Privacy Policy**: [Blink Privacy Policy](https://www.blink.sv/en/privacy-policy)

- **Galoy API (Staging Environment)**: Used during development and testing phases.
  - **Service URL**: [https://api.staging.galoy.io/graphql](https://api.staging.galoy.io/graphql)
  - **Terms of Use**: [Galoy Terms of Use](https://www.galoy.io/terms-conditions)
  - **Privacy Policy**: [Galoy Privacy Policy](https://www.galoy.io/privacy-policy)

Please review these links to ensure that you are compliant with all legal requirements related to data transmission and usage.

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload and unzip `blink-for-woocommerce.zip` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings via the WooCommerce settings page.
4. Ensure your Blink or Staging account is set up and linked with the plugin.

You can find more details on our [WooCommerce documentation](https://dev.blink.sv/examples/woocommerce-plugin/).

== Frequently Asked Questions ==

= How do I set up the plugin? =

Follow the installation steps and configure your Blink settings within the plugin options.

= What are the benefits of using Blink? =

Blink offers instant payments, low transaction fees and Stablesats integration for stable value payments.

= Is there support for troubleshooting? =

Yes, visit the [Blink website](https://www.blink.sv/) for support and troubleshooting resources.

== Screenshots ==

1. Plugin Settings Page - Configure your Blink payment settings.
2. Payment Checkout Page - Customers can choose to pay with Bitcoin via the Lightning Network during checkout.

== Changelog ==

= 0.1.1 =
* Minor content updates.

= 0.1.0 =
* Beta release for testing and feedback.

== Additional Information ==

For more details and support, visit [Blink](https://www.blink.sv/).
