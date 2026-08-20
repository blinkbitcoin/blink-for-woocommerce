#!/usr/bin/env bash
#
# Serves the end-to-end site with PHP's built-in server.
#
# PHP_CLI_SERVER_WORKERS is not optional. The plugin fetches the fake LNURL
# server over HTTP from inside a WordPress request -- a loopback request to
# this same server. With the default single worker that deadlocks: the server
# is busy handling the outer request and cannot answer the inner one.

set -euo pipefail

WP_CORE_DIR=${WP_CORE_DIR:-${TMPDIR:-/tmp}/wordpress}
E2E_PORT=${E2E_PORT:-8889}

export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-8}

exec php -S "localhost:${E2E_PORT}" -t "${WP_CORE_DIR%/}" \
  "$(dirname "$0")/e2e-router.php"
