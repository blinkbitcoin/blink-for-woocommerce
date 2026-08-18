#!/usr/bin/env bash
#
# Installs WooCommerce into the WordPress tree created by bin/install-wp-tests.sh
# so the integration suite can boot a real gateway.
#
# Usage: bin/install-woocommerce.sh [version|latest]

set -euo pipefail

WC_VERSION=${1:-latest}
WP_CORE_DIR=${WP_CORE_DIR:-${TMPDIR:-/tmp}/wordpress}
WC_PLUGIN_DIR="${WP_CORE_DIR%/}/wp-content/plugins/woocommerce"

if [ -f "${WC_PLUGIN_DIR}/woocommerce.php" ]; then
  echo "WooCommerce already present at ${WC_PLUGIN_DIR}"
  exit 0
fi

if [ "$WC_VERSION" = "latest" ]; then
  DOWNLOAD_URL="https://downloads.wordpress.org/plugin/woocommerce.zip"
else
  DOWNLOAD_URL="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
fi

TMP_ZIP=$(mktemp -t woocommerce.XXXXXX).zip
echo "Downloading ${DOWNLOAD_URL}"
curl -fsSL -o "$TMP_ZIP" "$DOWNLOAD_URL"

mkdir -p "${WP_CORE_DIR%/}/wp-content/plugins"
unzip -q -o "$TMP_ZIP" -d "${WP_CORE_DIR%/}/wp-content/plugins"
rm -f "$TMP_ZIP"

echo "WooCommerce installed at ${WC_PLUGIN_DIR}"
