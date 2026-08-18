<?php
/**
 * Constants the plugin's own classes expect to exist.
 *
 * The unit tier deliberately runs without WordPress, so the handful of
 * constants that src/ reads are defined here instead. Keep this list minimal:
 * a growing list is a signal that production code is reaching for globals it
 * should be receiving as a collaborator.
 */

declare(strict_types=1);

// WordPress time helpers.
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);

// Guard used by the plugin to refuse direct file access.
defined('ABSPATH') || define('ABSPATH', '/tmp/wordpress/');

// Plugin identity, normally defined by blink-for-woocommerce.php.
defined('BLINK_VERSION') || define('BLINK_VERSION', '0.2.2');
defined('BLINK_VERSION_KEY') || define('BLINK_VERSION_KEY', 'blink_version');
defined('BLINK_PLUGIN_ID') || define('BLINK_PLUGIN_ID', 'blink-for-woocommerce');
defined('BLINK_PLUGIN_URL') || define('BLINK_PLUGIN_URL', 'http://example.test/wp-content/plugins/blink-for-woocommerce/');
defined('BLINK_PLUGIN_FILE_PATH') || define('BLINK_PLUGIN_FILE_PATH', dirname(__DIR__, 3) . '/blink-for-woocommerce.php');
