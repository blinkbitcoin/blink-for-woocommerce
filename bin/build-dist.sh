#!/usr/bin/env bash
#
# Builds the directory that actually ships to WordPress.org, into build/dist.
#
# The repository root is not the plugin. It also holds the test suites, the
# build scripts, the Nix flake and the documentation, none of which are
# distributed -- .distignore is the list, and the WordPress.org deploy action
# honours it. Running quality checks against the repository root therefore
# checks files no user will ever receive, and reports problems that cannot be
# fixed without deleting the development tooling.
#
# This script applies .distignore the same way, so the checks see what the
# shops see.

set -euo pipefail

cd "$(dirname "$0")/.."

# Named after the plugin slug on purpose: Plugin Check derives the expected
# text domain from the directory name, so building into "dist" would report a
# text-domain mismatch on every translated string.
DEST=${1:-build/blink-for-woocommerce}

command -v rsync >/dev/null 2>&1 || {
  echo "rsync is required." >&2
  exit 1
}

rm -rf "$DEST"
mkdir -p "$DEST"

# rsync reads .distignore directly: it skips blank lines and # comments, and
# treats a bare name as matching at any depth, which is how .distignore is
# written and how the deploy action reads it.
rsync -a --exclude-from=.distignore ./ "$DEST/"

echo "Distribution built at $DEST"
find "$DEST" -maxdepth 1 -mindepth 1 -printf '  %f\n' 2>/dev/null || ls -1 "$DEST" | sed 's/^/  /'
