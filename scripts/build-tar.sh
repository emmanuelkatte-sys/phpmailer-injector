#!/usr/bin/env bash
# Build phpmailer-injector.tar.gz for VPS deploy.
# Always emits top-level directory "phpmailer-injector/" (lowercase).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-$(dirname "$ROOT")}"
STAGE="$(mktemp -d /tmp/phpmailer-injector-build-XXXXXXXX)"
NAME="phpmailer-injector"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

cd "$ROOT"
if [ ! -f composer.json ]; then
    echo "composer.json not found in $ROOT" >&2
    exit 1
fi

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader

mkdir -p "$STAGE/$NAME"
tar -cf - \
    --exclude='./.git' \
    --exclude='./tests' \
    --exclude='./deploy' \
    -C "$ROOT" . | tar -xf - -C "$STAGE/$NAME"

cd "$STAGE"
tar -czf "$OUT_DIR/${NAME}.tar.gz" "$NAME/"
sha256sum "$OUT_DIR/${NAME}.tar.gz"

echo "Built: $OUT_DIR/${NAME}.tar.gz"
echo "Verify: tar -tzf $OUT_DIR/${NAME}.tar.gz | head -3"
