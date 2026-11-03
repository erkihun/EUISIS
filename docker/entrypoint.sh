#!/usr/bin/env bash
# Container entrypoint for the Agelgay (EUISIS) app image.
#
#   entrypoint php-fpm     web/PHP role (default): waits for the database, links
#                          storage, migrates when AUTO_MIGRATE=true, caches, serves
#   entrypoint queue       queue worker  (php artisan queue:work)
#   entrypoint scheduler   scheduler     (php artisan schedule:work)
#   entrypoint <anything>  run that command as www-data (e.g. `php artisan tinker`)
set -euo pipefail

cd /var/www/html

role="${1:-php-fpm}"

log() { printf '[entrypoint] %s\n' "$*"; }

if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY is empty. Generate one and put it in .env.docker:"
    log "  docker compose run --rm --no-deps app php artisan key:generate --show"
    exit 1
fi

wait_for_db() {
    local host="${DB_HOST:-}" port="${DB_PORT:-5432}" tries=0
    [ -n "$host" ] || return 0
    until pg_isready -h "$host" -p "$port" -q 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            log "database at $host:$port did not answer after 60 attempts"
            exit 1
        fi
        log "waiting for database at $host:$port ($tries)"
        sleep 2
    done
}

prepare_storage() {
    # The storage volume starts from the image's copy; make sure every directory
    # Laravel writes to exists and belongs to the PHP user.
    mkdir -p storage/app/public storage/app/private \
             storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    if [ ! -L public/storage ]; then
        gosu www-data php artisan storage:link --force --no-interaction >/dev/null
    fi
}

warm_caches() {
    gosu www-data php artisan optimize --no-interaction
}

case "$role" in
    php-fpm)
        wait_for_db
        prepare_storage
        if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
            log "running migrations"
            gosu www-data php artisan migrate --force --no-interaction
        fi
        warm_caches
        touch /tmp/app-ready
        log "starting php-fpm"
        exec php-fpm
        ;;
    queue)
        wait_for_db
        prepare_storage
        warm_caches
        log "starting queue worker"
        exec gosu www-data php artisan queue:work \
            --queue="${QUEUE_NAMES:-default,notifications,long}" \
            --tries="${QUEUE_TRIES:-3}" --timeout="${QUEUE_TIMEOUT:-300}" \
            --sleep=3 --max-time=3600 --no-interaction
        ;;
    scheduler)
        wait_for_db
        prepare_storage
        warm_caches
        log "starting scheduler"
        exec gosu www-data php artisan schedule:work --no-interaction
        ;;
    *)
        exec gosu www-data "$@"
        ;;
esac
