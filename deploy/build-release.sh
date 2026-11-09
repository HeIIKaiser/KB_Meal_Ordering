#!/usr/bin/env bash
# Build an upload-ready release ZIP on a developer machine (for hosting without
# SSH/Composer). Contains vendor/ (no dev deps) and compiled assets; never .env.
#
#   deploy/build-release.sh            → build/jedalen-<commit>.zip
set -euo pipefail

cd "$(dirname "$0")/.."
rev=$(git rev-parse --short HEAD)
out="build/jedalen-$rev.zip"
tmp=$(mktemp -d)

git archive HEAD | tar -x -C "$tmp"
(
  cd "$tmp"
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  rm -rf tests tools/*.md .github
)
mkdir -p build
(cd "$tmp" && zip -qr - .) > "$out"
rm -rf "$tmp"
echo "Built $out"
