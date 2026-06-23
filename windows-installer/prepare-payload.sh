#!/usr/bin/env bash
# ============================================================================
# Build the clean Laravel payload that gets bundled into the Windows installer.
# Run from the repo root (or anywhere) on macOS/Linux with php, composer, npm.
#
#   ./windows-installer/prepare-payload.sh
#
# Output: windows-installer/build/app   (copy of the app, prod deps, built
# assets, no .git / node_modules / tests / secrets).
# ============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT="$SCRIPT_DIR/build/app"

echo ">> Building front-end assets (vite)"
( cd "$REPO_ROOT" && npm ci && npm run build )

echo ">> Staging application into $OUT"
rm -rf "$OUT"
mkdir -p "$OUT"

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'node_modules' \
  --exclude 'tests' \
  --exclude 'windows-installer' \
  --exclude '.env' \
  --exclude '.env.example' \
  --exclude 'compose.yaml' \
  --exclude 'docker-compose*.yml' \
  --exclude '*.png' \
  --exclude '*.postman_collection.json' \
  --exclude '.phpunit.result.cache' \
  --exclude 'phpunit.xml' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  "$REPO_ROOT/" "$OUT/"

echo ">> Installing production composer dependencies"
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )

echo ">> Ensuring writable storage skeleton"
mkdir -p \
  "$OUT/storage/app/public" \
  "$OUT/storage/framework/cache/data" \
  "$OUT/storage/framework/sessions" \
  "$OUT/storage/framework/views" \
  "$OUT/storage/logs" \
  "$OUT/bootstrap/cache"
# keep empty dirs in the installer
find "$OUT/storage" "$OUT/bootstrap/cache" -type d -empty -exec touch {}/.gitkeep \;

echo ">> Clearing any cached config/routes/views from the build host"
( cd "$OUT" && php artisan optimize:clear || true )
rm -f "$OUT/bootstrap/cache/config.php" "$OUT/bootstrap/cache/routes-v7.php" 2>/dev/null || true

echo ">> Payload ready: $OUT"
echo "   Next: place portable Apache+PHP under windows-installer/build/runtime"
echo "   then compile windows-installer/adms-installer.iss with Inno Setup on Windows."
