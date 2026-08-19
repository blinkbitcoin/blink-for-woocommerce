#!/usr/bin/env bash
#
# Turns the WordPress install that bin/install-wp-tests.sh downloaded into a
# site that can actually be served, so the browser tests need no Docker.
#
# Usage: bin/install-e2e-site.sh
#
# Environment:
#   WP_CORE_DIR   where WordPress lives (default: $TMPDIR/wordpress)
#   DB_NAME       separate from the PHPUnit database, which is wiped per test
#   DB_USER, DB_PASS, DB_HOST
#   E2E_PORT      default 8889
#   WP_CLI_BIN    WP-CLI executable (default: wp)

set -euo pipefail

WP_CORE_DIR=${WP_CORE_DIR:-${TMPDIR:-/tmp}/wordpress}
WP_CORE_DIR=${WP_CORE_DIR%/}
DB_NAME=${DB_NAME:-blink_e2e}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-root}
DB_HOST=${DB_HOST:-127.0.0.1}
E2E_PORT=${E2E_PORT:-8889}
WP_CLI_BIN=${WP_CLI_BIN:-wp}
SITE_URL="http://localhost:${E2E_PORT}"

if [ ! -f "${WP_CORE_DIR}/wp-includes/version.php" ]; then
  echo "No WordPress at ${WP_CORE_DIR}. Run bin/install-wp-tests.sh first." >&2
  exit 1
fi
if [ ! -f "${WP_CORE_DIR}/wp-content/plugins/woocommerce/woocommerce.php" ]; then
  echo "No WooCommerce. Run bin/install-woocommerce.sh first." >&2
  exit 1
fi

command -v "$WP_CLI_BIN" >/dev/null 2>&1 || {
  echo "WP-CLI is required (https://wp-cli.org)." >&2
  exit 1
}

echo "==> wp-config"
rm -f "${WP_CORE_DIR}/wp-config.php"
"$WP_CLI_BIN" config create \
  --path="$WP_CORE_DIR" \
  --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
  --skip-check --force \
  --extra-php <<PHP
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);
define('DISABLE_WP_CRON', true);
PHP

echo "==> database ${DB_NAME}"
# Through WP-CLI rather than the mysql client: the runner images no longer
# ship one, and WP-CLI is a hard requirement of this script anyway.
"$WP_CLI_BIN" db create --path="$WP_CORE_DIR" 2>/dev/null || echo "(database already exists)"

echo "==> installing the site"
"$WP_CLI_BIN" core install \
  --path="$WP_CORE_DIR" \
  --url="$SITE_URL" \
  --title="Blink E2E" \
  --admin_user=admin --admin_password=password --admin_email=e2e@example.test \
  --skip-email

# Plain permalinks: the fake LNURL server routes /.well-known/lnurlp/... from
# an init hook, so rewrite rules must not get there first.
"$WP_CLI_BIN" option update permalink_structure '' --path="$WP_CORE_DIR"

echo "==> plugins"
PLUGIN_DIR="${WP_CORE_DIR}/wp-content/plugins/blink-for-woocommerce"
rm -rf "$PLUGIN_DIR"
ln -s "$(cd "$(dirname "$0")/.." && pwd)" "$PLUGIN_DIR"

"$WP_CLI_BIN" plugin activate woocommerce --path="$WP_CORE_DIR"
"$WP_CLI_BIN" plugin activate blink-for-woocommerce --path="$WP_CORE_DIR"

# The fake LNURL server, and the encoder it requires. Both must be present:
# the previous harness mapped only the first and the mu-plugin fatalled.
MU_DIR="${WP_CORE_DIR}/wp-content/mu-plugins"
mkdir -p "$MU_DIR"
# Symlinked rather than copied: a copy goes stale the moment one of these is
# edited, and the suite then runs against the previous version without saying
# so. That cost real debugging time -- a stubbed exchange rate looked like it
# had no effect, because the site was still serving yesterday's copy.
SRC_MU="$(cd "$(dirname "$0")/../tests/e2e/mu-plugins" && pwd)"
for mu in blink-e2e-lnurl-server.php blink-e2e-bolt11.php; do
  rm -f "${MU_DIR}/${mu}"
  ln -s "${SRC_MU}/${mu}" "${MU_DIR}/${mu}" 2>/dev/null ||
    cp "${SRC_MU}/${mu}" "${MU_DIR}/${mu}"
done

echo "==> WooCommerce setup"
"$WP_CLI_BIN" option update woocommerce_currency USD --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update woocommerce_store_address "1 Test Street" --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update woocommerce_default_country "US:CA" --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update woocommerce_onboarding_profile '{"skipped":true}' --format=json --path="$WP_CORE_DIR"

echo "==> Blink settings"
"$WP_CLI_BIN" option update blink_account_type non_custodial --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update blink_ln_address "ok@localhost:${E2E_PORT}" --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update blink_env blink --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option update blink_debug yes --path="$WP_CORE_DIR"
"$WP_CLI_BIN" option patch insert woocommerce_blink_default_settings enabled yes --path="$WP_CORE_DIR" 2>/dev/null \
  || "$WP_CLI_BIN" option update woocommerce_blink_default_settings '{"enabled":"yes"}' --format=json --path="$WP_CORE_DIR"

echo
echo "Site ready at ${SITE_URL}"
echo "Serve it with: bin/serve-e2e-site.sh"
