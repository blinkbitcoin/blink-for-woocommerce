#!/usr/bin/env bash
#
# Runs both PHPUnit suites with branch coverage and merges the result.
#
# Branch coverage requires Xdebug: pcov is a line-hit sampler and cannot
# produce it, so there is no faster substitute here.

set -euo pipefail

cd "$(dirname "$0")/.."

if ! php -m | grep -qi '^xdebug$'; then
  echo "Xdebug is required for branch coverage (pcov cannot produce it)." >&2
  exit 1
fi

rm -rf build/cov build/coverage
mkdir -p build/cov build/coverage

export XDEBUG_MODE=coverage

echo "==> unit suite"
vendor/bin/phpunit -c phpunit-unit.xml.dist --path-coverage

if [ "${SKIP_INTEGRATION:-0}" != "1" ]; then
  echo "==> integration suite"
  vendor/bin/phpunit -c phpunit.xml.dist --path-coverage
fi

echo "==> merging"
# The .php export carries php-code-coverage's own branch data, which the gate
# reads. Cobertura's condition-coverage is weaker and would quietly downgrade
# the branch gate to a line gate; it is emitted for diff-cover and CI tooling.
vendor/bin/phpcov merge \
  --php build/coverage/merged.cov \
  --cobertura build/coverage/cobertura.xml \
  --clover build/coverage/clover.xml \
  --html build/coverage/html \
  build/cov/ 2>/dev/null

echo "Merged report at build/coverage/merged.cov"
