#!/usr/bin/env bash
# Update the installed app to the latest `main` (run on the server, in the app directory):
#
#   cd ~/apps/<app> && bash deploy/update.sh
#
# Takes a backup first, shows a maintenance page while updating, and brings the
# app back up even if a step fails (then check the output and storage/logs/).
set -euo pipefail

cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
BRANCH="${BRANCH:-main}"

echo "== Backup before update"
$PHP artisan app:backup

echo "== Maintenance mode"
$PHP artisan down --retry=60 || true
trap '$PHP artisan up' EXIT

echo "== Pull $BRANCH"
git fetch origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"

echo "== Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "== Database"
$PHP artisan migrate --force
$PHP artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force

echo "== Caches"
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan filament:optimize || true

echo "== Check"
$PHP artisan app:hosting-check || echo "!! Some checks failed – see above"

echo "== Done: $(git log -1 --format='%h %s')"
