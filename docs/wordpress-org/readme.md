# Blink For WooCommerce #
**Contributors:** [blink](https://profiles.wordpress.org/blink/)  
**Tags:** Bitcoin, Lightning Network, WooCommerce, payment gateway  
**Requires at least:** 6.5  
**Tested up to:** 7.0  
**Requires PHP:** 8.1  
**Stable tag:** 0.3.0  
**License:** MIT  
**License URI:** https://github.com/blinkbitcoin/blink-for-woocommerce/blob/main/license.txt  

A simple, fast and secure Bitcoin payment gateway for WooCommerce using [Blink](https://www.blink.sv/).

## Description ##

Blink For WooCommerce is a plugin that allows WooCommerce merchants to accept Bitcoin payments through the Lightning Network using [Blink](https://www.blink.sv/).

Key features of Blink For WooCommerce include:

* Instant Payments: Leveraging the Lightning Network, [Blink](https://www.blink.sv/) ensures that Bitcoin payments are processed instantly, providing a smooth checkout experience for customers.
* Low Transaction Fees: Enjoy significantly lower transaction fees compared to traditional payment methods, helping you save on processing costs.
* Stablesats Integration: Offers the ability to receive payments in Bitcoin while maintaining a stable value pegged to the US Dollar, reducing volatility risks.
* Easy Integration: Simple setup and configuration within WooCommerce, allowing you to start accepting Bitcoin payments quickly and easily.
* Custodial or Non-custodial: Connect a custodial Blink account with an API key, or a non-custodial (self-custodial) account using just your Blink lightning address. Non-custodial payments are shown as a Lightning QR code on your store and require no API key.

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

- **The domain of your Lightning address (non-custodial mode only)**: When you configure a lightning address such as `you@example.com`, your shop makes requests to `example.com` to fetch payment details, request invoices, and check whether a payment has settled. This is not necessarily Blink: it is whichever provider runs the address you enter. Order totals and an order reference are sent as part of requesting an invoice. Please review that provider's own terms and privacy policy.

Please review these links to ensure that you are compliant with all legal requirements related to data transmission and usage.

## Installation ##

This section describes how to install the plugin and get it working.

The PHP cURL extension is required. Payments to a Lightning address pin each
request to the IP addresses that passed validation, which the plugin can only
do through cURL; without it those requests are refused rather than sent
unpinned.

1. Upload and unzip `blink-for-woocommerce.zip` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings via the WooCommerce settings page.
4. Ensure your Blink or Staging account is set up and linked with the plugin.

You can find more details on our [WooCommerce documentation](https://dev.blink.sv/examples/woocommerce-plugin/).

## Frequently Asked Questions ##

### How do I set up the plugin? ###

Follow the installation steps and configure your Blink settings within the plugin options.

### What are the benefits of using Blink? ###

Blink offers instant payments, low transaction fees and Stablesats integration for stable value payments.

### Is there support for troubleshooting? ###

Yes, visit the [Blink website](https://www.blink.sv/) for support and troubleshooting resources.

## Screenshots ##

1. Plugin Settings Page - Configure your Blink payment settings.
2. Payment Checkout Page - Customers can choose to pay with Bitcoin via the Lightning Network during checkout.

## Changelog ##

### 0.3.0 :: 2026-08-18 ###
* Fixed: non-custodial orders now settle in the background. Previously settlement only happened while the customer's pay page was open, so a buyer who paid from a phone and closed the tab could have their paid order cancelled when hold-stock expired.
* Fixed: an unreachable verification endpoint no longer causes orders to be cancelled. Uncertainty leaves an order pending for review rather than expiring it.
* Fixed: the pay page's expiry deadline works. It was passed to the browser as a string, so the check never matched and the page polled indefinitely without ever showing the expiry message.
* Fixed: the pay page no longer declares a still-payable invoice expired. The client cut-off was shorter than the invoice itself.
* Fixed: changing the account type or lightning address no longer strands orders that are already in flight; each order settles against the configuration it was created with.
* Security: the invoice returned by a lightning address is now decoded and checked -- amount, payment hash, expiry and metadata binding -- instead of being trusted.
* Security: settlement verifies the payment preimage against the invoice's payment hash.
* Security: the same-domain check no longer collapses multi-part suffixes, so an address at example.co.uk no longer trusts every .co.uk host.
* Security: HTTPS is required for callback and verification URLs, not only for the initial lookup.
* Security: carrier-grade NAT, IPv4-mapped IPv6 and NAT64 addresses are rejected, and requests are pinned to the addresses that were validated.
* Security: settlement-critical order data is stored as protected meta, so it cannot be edited from the order screen.
* Security: the settlement polling endpoint rejects orders that are not non-custodial Blink orders.
* Changed: the polling endpoint serves a cached status and enforces per-IP, per-domain and site-wide request budgets, so many open tabs no longer multiply outbound traffic.
* Changed: the pay page backs off between checks, pauses while the tab is hidden, and offers a manual retry instead of failing silently.
* Changed: an order edited after its invoice was created is held for review rather than completed automatically.
* Fixed: a hung Blink API no longer blocks checkout indefinitely; the API client has request timeouts.
* Fixed: GraphQL errors report their actual message instead of a class-not-found error.
* Added: unit, integration, JavaScript and end-to-end test suites, with a 100% line and branch coverage gate in CI.


### 0.2.2 ###
* Security: validate LNURL callback/verify URLs (same domain + no private/loopback IPs) and disable redirects to prevent SSRF
* Honor the server-advertised LUD-12 commentAllowed limit when creating non-custodial invoices
* Recreate expired non-custodial invoices on checkout retry instead of reusing an unpayable one
* Rate limit the public settlement-poll AJAX endpoint
* Add an absolute client-side polling timeout on the pay page
* Validate the account type setting against the allowed values

### 0.2.1 ###
* Fix duplicate custom rows (Webhook Url, Setup status) on the Blink settings page
* Light visual cleanup of the non-custodial on-site pay page
* Clarify that the custodial Blink dashboard callback endpoint must be enabled

### 0.2.0 ###
* Add support for non-custodial Blink accounts via lightning address (LNURL-pay + LUD-21 verify)
* Add "Account Type" setting to choose between custodial (API key) and non-custodial (lightning address)
* Render Lightning invoice with QR code on an on-site pay page for non-custodial orders
* Detect settlement for non-custodial orders by polling the LUD-21 verify endpoint

### 0.1.3 ###
* Update PHP min version

### 0.1.2 ###
* Update Blink Logo
* Add warning note about API key scopes
* Fix feedback notification close
* Rename stablesats to USD

### 0.1.1 ###
* Minor content updates.

### 0.1.0 ###
* Beta release for testing and feedback.

## Additional Information ##

For more details and support, visit [Blink](https://www.blink.sv/).
