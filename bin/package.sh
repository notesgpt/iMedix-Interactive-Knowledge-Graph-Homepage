#!/usr/bin/env bash
# Build the production bundle (typecheck + vite build) and produce an installable plugin zip:
#   build/imedix-knowledge-universe-<version>.zip
# The zip contains compiled assets, so the server needs no Node.js.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$ROOT/wordpress/imedix-knowledge-universe"
VERSION="$(sed -n 's/^ \* Version: *//p' "$PLUGIN/imedix-knowledge-universe.php" | tr -d '[:space:]')"

(cd "$ROOT/frontend" && npm ci --no-audit --no-fund && npm run build)
test -f "$PLUGIN/assets/dist/.vite/manifest.json" || { echo "manifest missing" >&2; exit 1; }

mkdir -p "$ROOT/build"
OUT="$ROOT/build/imedix-knowledge-universe-$VERSION.zip"
rm -f "$OUT"
(cd "$ROOT/wordpress" && zip -qr "$OUT" imedix-knowledge-universe -x '*.DS_Store')
echo "Wrote $OUT"
