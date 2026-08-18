<?php
/**
 * Router for PHP's built-in server.
 *
 * Serves real files as themselves and hands everything else to WordPress, so
 * that /.well-known/lnurlp/... reaches the fake LNURL server's init hook.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = rtrim((string) getenv('WP_CORE_DIR'), '/') . $path;

if ($path !== '/' && $file !== '' && is_file($file)) {
  return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require rtrim((string) getenv('WP_CORE_DIR'), '/') . '/index.php';
