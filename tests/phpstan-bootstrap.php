<?php
/**
 * Constants PHPStan needs to analyse the plugin without booting WordPress.
 */

declare(strict_types=1);

define('BLINK_VERSION', '0.3.0');
define('BLINK_VERSION_KEY', 'blink_version');
define('BLINK_PLUGIN_ID', 'blink-for-woocommerce');
define('BLINK_PLUGIN_URL', 'https://example.test/');
define('BLINK_PLUGIN_FILE_PATH', __DIR__ . '/../blink-for-woocommerce.php');
