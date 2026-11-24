#!/usr/bin/env bash
# Update the installed app to the latest `main` (run on the server, in the app directory):
#
#   cd ~/apps/<app> && bash deploy/update.sh            # update
#   cd ~/apps/<app> && bash deploy/update.sh --check    # only check PHP + Composer, change nothing
#
# Takes a backup first, shows a maintenance page while updating, and brings the
# app back up even if a step fails (it then says which step failed and what state
# the app is in; details in the output above and in storage/logs/).
#
# PHP: the console's default `php` may differ from the version the site and cron run
# (Websupport moved the console to 8.6 while the site runs 8.5). Order of choice:
#   1. env PHP (e.g. PHP=/usr/bin/php8.5 bash deploy/update.sh)
#   2. /usr/bin/$PREFERRED_PHP if it exists
#   3. php
# Change PREFERRED_PHP below when the site's PHP version changes.
set -euo pipefail

PREFERRED_PHP="php8.5"

cd "$(dirname "$0")/.."
BRANCH="${BRANCH:-main}"
PHP_DIR="${UPDATE_PHP_DIR:-/usr/bin}" # overridable for tests only

CHECK_ONLY=0
case "${1:-}" in
    --check) CHECK_ONLY=1 ;;
    "") ;;
    *) echo "Usage: bash deploy/update.sh [--check]" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------- PHP + Composer
if [ -n "${PHP:-}" ]; then
    PHP_SOURCE="env PHP"
elif [ -x "$PHP_DIR/$PREFERRED_PHP" ]; then
    PHP="$PHP_DIR/$PREFERRED_PHP"
    PHP_SOURCE="preferred ($PREFERRED_PHP)"
else
    PHP="php"
    PHP_SOURCE="default php ($PHP_DIR/$PREFERRED_PHP not found)"
fi

if ! PHP_VERSION="$($PHP -r 'echo PHP_VERSION;' 2>/dev/null)"; then
    echo "!! PHP '$PHP' cannot be run. Set it explicitly, e.g.: PHP=/usr/bin/php8.5 bash deploy/update.sh" >&2
    exit 1
fi
COMPOSER="$(command -v composer || true)"
if [ -z "$COMPOSER" ]; then
    echo "!! composer is not on the PATH. Nothing was changed." >&2
    exit 1
fi
echo "== PHP: $PHP ($PHP_VERSION) – $PHP_SOURCE"
echo "== Composer: $COMPOSER (run with that PHP)"

composer_run() {
    "$PHP" "$COMPOSER" "$@"
}

# Does this PHP satisfy composer.lock? (extensions + PHP version of every locked package)
platform_check() {
    if ! composer_run check-platform-reqs --lock --no-dev; then
        echo "" >&2
        echo "!! PHP $PHP_VERSION ($PHP) does not satisfy composer.lock (see the lines marked 'failed' above)." >&2
        echo "!! Run the update with the PHP version the site uses, e.g.:" >&2
        echo "!!   PHP=/usr/bin/php8.5 bash deploy/update.sh" >&2
        echo "!! or change PREFERRED_PHP at the top of deploy/update.sh." >&2
        return 1
    fi
}

echo "== Platform check (composer.lock)"
if ! platform_check; then
    echo "!! Nothing was changed: no backup, no maintenance mode, no git pull." >&2
    exit 1
fi

if [ "$CHECK_ONLY" = 1 ]; then
    echo "== Check OK – nothing was changed (--check)."
    exit 0
fi

# ---------------------------------------------------------------- update
STEP="backup"
STATE="nothing changed yet"
IS_DOWN=0

finish() {
    local code=$?
    if [ "$IS_DOWN" = 1 ]; then
        $PHP artisan up || echo "!! 'artisan up' failed – run: $PHP artisan up" >&2
    fi
    if [ "$code" -ne 0 ]; then
        echo "" >&2
        echo "!! Update FAILED at step: $STEP" >&2
        echo "!! State: $STATE" >&2
        echo "!! The site is up (maintenance mode is off). Fix the error above and run the update again:" >&2
        echo "!!   PHP=$PHP bash deploy/update.sh" >&2
    fi
}
trap finish EXIT

echo "== Backup before update"
$PHP artisan app:backup

STEP="maintenance mode"
echo "== Maintenance mode"
$PHP artisan down --retry=60 || true
IS_DOWN=1

STEP="git pull"
echo "== Pull $BRANCH"
git fetch origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"
STATE="NEW code ($(git log -1 --format='%h')), but dependencies, database and caches NOT updated yet"

STEP="platform check of the new composer.lock"
echo "== Platform check (new composer.lock)"
platform_check

STEP="composer install"
echo "== Dependencies"
composer_run install --no-dev --optimize-autoloader --no-interaction
STATE="new code and dependencies, but the database is NOT migrated yet"

STEP="database migration"
echo "== Database"
$PHP artisan migrate --force
$PHP artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force
STATE="new code, dependencies and database; caches may be stale"

STEP="caches"
echo "== Caches"
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan filament:optimize || true
STATE="updated"

STEP="hosting check"
echo "== Check"
$PHP artisan app:hosting-check || echo "!! Some checks failed – see above"

echo "== Done: $(git log -1 --format='%h %s')"
