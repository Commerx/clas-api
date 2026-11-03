#!/usr/bin/env bash
#
# Deploy the API to a server.
#
#   ssh ubuntu@<host> 'cd /var/www/api && ./deploy.sh'
#
# Deliberately a hard reset onto the remote branch rather than a pull. The
# script prunes files afterwards (see PRUNE below), which leaves the working
# tree differing from HEAD; a pull would then either conflict or quietly
# restore them. Resetting makes every deploy start from the same place.
#
# Nothing here touches .env, which is gitignored and belongs to the server.

set -euo pipefail

BRANCH="${DEPLOY_BRANCH:-main}"
PHP_FPM="${PHP_FPM_SERVICE:-php8.5-fpm}"

# Useful in the repository, not wanted on a server: planning notes, the test
# suite and its fixtures — which document the fixture account and the security
# findings — and local assistant guidance. None are reachable over HTTP, since
# the document root is public/, but there is no reason for them to be here.
PRUNE=(docs tests phpunit.xml CLAUDE.md AGENTS.md .github)

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

cd "$(dirname "$0")"

say "Fetching origin/$BRANCH"
git fetch --quiet origin "$BRANCH"
echo "    $(git rev-parse --short HEAD) -> $(git rev-parse --short "origin/$BRANCH")"

say "Resetting to origin/$BRANCH"
git reset --quiet --hard "origin/$BRANCH"
git log --oneline -1 | sed 's/^/    /'

say "Installing dependencies"
# --no-dev omits dev packages; the test suite is pruned below in any case.
COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
echo "    $(composer show --installed --no-dev 2>/dev/null | wc -l) packages"

say "Pruning files that should not be on a server"
for path in "${PRUNE[@]}"; do
    if [ -e "$path" ]; then
        rm -rf -- "$path"
        echo "    removed $path"
    fi
done

say "Clearing caches"
# Not caching config or routes yet: while endpoints are still being added,
# a stale cache is a confusing failure. Add `config:cache` and `route:cache`
# here once the surface settles, and remember a cached config ignores .env.
php artisan optimize:clear --quiet
echo "    caches cleared"

say "Running migrations"
php artisan migrate --force --no-interaction

say "Fixing writable paths"
# Laravel writes logs and compiled views as the web user, not as the deployer.
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

say "Reloading PHP-FPM"
# Opcache revalidates on a timer, so a reload makes the new code live
# immediately rather than up to a couple of seconds later.
sudo systemctl reload "$PHP_FPM"

say "Checking the application responds"
for path in up health; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "http://127.0.0.1/$path" || true)
    printf '    /%-8s %s\n' "$path" "$code"
    if [ "$code" != "200" ]; then
        echo
        echo "    FAILED: /$path returned $code. Recent log:" >&2
        tail -n 20 storage/logs/laravel.log 2>/dev/null >&2 || true
        exit 1
    fi
done

say "Deployed $(git rev-parse --short HEAD)"
