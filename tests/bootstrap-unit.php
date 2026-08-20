<?php
/**
 * Bootstrap for the unit test suite.
 *
 * The unit tier runs WITHOUT WordPress: no database, no core bootstrap, no
 * WooCommerce. WordPress functions are faked per-test by Brain Monkey, and the
 * few classes and constants the plugin's own code touches are stubbed here.
 *
 * Anything that genuinely needs WordPress belongs in tests/Integration.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Constants first: stub classes below may reference them.
require_once __DIR__ . '/Support/Stub/wp-constants.php';

// Global-namespace class stubs. Required explicitly rather than autoloaded so
// they can never leak into the integration tier and collide with the real
// WooCommerce classes.
require_once __DIR__ . '/Support/Stub/WC_Logger.php';
