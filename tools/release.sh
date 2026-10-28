#!/usr/bin/env bash
# Usage: tools/release.sh 1.5.1 "What changed (shown on the Plugins page)"
# Sets the version in product-glide.php, commits and pushes to main.
# The Release action sees the new version, tags vX.Y.Z, builds the zip and publishes it.
set -euo pipefail
cd "$(dirname "$0")/.."
VER="${1:?version, e.g. 1.5.1}"
NOTES="${2:-Product Glide $VER}"
sed -i -E "s/^( \* Version:\s+)[0-9.]+/\1$VER/; s/define\( 'PG_VERSION', '[0-9.]+' \)/define( 'PG_VERSION', '$VER' )/" product-glide.php
grep -q "Version:           $VER" product-glide.php && grep -q "'PG_VERSION', '$VER'" product-glide.php || { echo "version not set"; exit 1; }
git add -A
git commit -m "Release $VER" -m "$NOTES"
git push origin HEAD:main
echo "Pushed $VER — the Release action will publish v$VER with product-glide.zip in about a minute."
