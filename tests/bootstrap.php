<?php
/**
 * Bootstrap for the integration test suite.
 *
 * Runs against a real WordPress + WooCommerce install (see bin/install-wp-tests.sh
 * and bin/install-woocommerce.sh). Tests that do not need WordPress belong in
 * tests/Unit, which runs through tests/bootstrap-unit.php instead.
 *
 * @package Blink_For_Woocommerce
 */

// Composer's autoloader must be available before anything else: the WordPress
// test library does not load it, and the plugin only registers its own
// autoloader at plugins_loaded, which is far too late for test helpers.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * WooCommerce must load before the Blink plugin, which extends its gateway and
 * settings base classes at include time.
 */
function _manually_load_plugin() {
	$wc_plugin_dir = getenv( 'WC_PLUGIN_DIR' );
	if ( ! $wc_plugin_dir ) {
		$wc_plugin_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins/woocommerce';
	}

	$wc_bootstrap = $wc_plugin_dir . '/woocommerce.php';
	if ( ! file_exists( $wc_bootstrap ) ) {
		echo "Could not find WooCommerce at {$wc_bootstrap}, have you run bin/install-woocommerce.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}

	require $wc_bootstrap;
	require dirname( __DIR__ ) . '/blink-for-woocommerce.php';
}

/**
 * Selects WooCommerce's order storage backend.
 *
 * WooCommerce can keep orders in the posts table or in its own tables (HPOS).
 * The plugin reads and writes order meta either way, so CI runs the suite
 * against both rather than assuming they behave identically.
 */
function _blink_set_order_storage() {
	if ( getenv( 'BLINK_TEST_HPOS' ) !== 'yes' ) {
		return;
	}

	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
	}
}

tests_add_filter( 'setup_theme', '_blink_set_order_storage', 20 );

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Create WooCommerce's tables (including Action Scheduler's) once the test
 * environment is up. Without this, wc_get_order() and the scheduler have no
 * storage to talk to.
 */
function _install_woocommerce() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	// Suppress the output WC_Install emits while creating tables.
	ob_start();
	WC_Install::install();
	if ( function_exists( 'WC' ) ) {
		WC()->init();
	}
	ob_end_clean();
}

tests_add_filter( 'setup_theme', '_install_woocommerce' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
