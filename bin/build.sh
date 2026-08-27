#!/usr/bin/env bash
#
# Build a distributable plugin zip: production vendor/ only, no dev files.
# Usage: bin/build.sh  →  dist/pdf-mapper-for-fluent-forms-<version>.zip

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="pdf-mapper-for-fluent-forms"
VERSION="$(grep -m1 "Version:" "$PLUGIN_DIR/$SLUG.php" | sed 's/.*Version: *//' | tr -d ' ')"
BUILD_ROOT="$(mktemp -d)"
DEST="$BUILD_ROOT/$SLUG"

mkdir -p "$DEST" "$PLUGIN_DIR/dist"

# Anchored excludes: /vendor is the Composer dir at the plugin root only —
# assets/vendor (bundled JS libraries) must ship.
rsync -a "$PLUGIN_DIR/" "$DEST/" \
  --exclude /vendor \
  --exclude /dist \
  --exclude /bin \
  --exclude node_modules \
  --exclude .gitignore \
  --exclude phpcs.xml \
  --exclude "*.zip" \
  --exclude .DS_Store

# composer.lock is copied in on purpose and deleted after: installing from the
# lock pins the exact vendor tree that was audited. Without it Composer resolves
# afresh on every build, so two builds of the same tag could ship different code.
( cd "$DEST" && composer install --no-dev --optimize-autoloader --quiet && rm -f composer.lock )

# Prune FPDF's demo/doc payload. tutorial/*.php are directly reachable over HTTP
# with no ABSPATH guard, and tutorial/makefont.php writes files into the plugin
# directory when requested. None of it is used at runtime; font/ must stay.
rm -rf "$DEST/vendor/setasign/fpdf/doc" \
       "$DEST/vendor/setasign/fpdf/tutorial" \
       "$DEST/vendor/setasign/fpdf/makefont"

ZIP="$PLUGIN_DIR/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$BUILD_ROOT" && zip -rq "$ZIP" "$SLUG" )

rm -rf "$BUILD_ROOT"
echo "Built: $ZIP"
