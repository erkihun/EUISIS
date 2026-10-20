#!/usr/bin/env bash
# Local multi-node rig for the load tests (docs/production-readiness/load-test-results.md).
# Starts N single-threaded `php -S` app nodes on consecutive ports against a
# BENCHMARK database, with OPcache on and config/route/event caches written to
# storage/framework/bench/ so the main checkout's bootstrap/cache is untouched.
#
#   DB_DATABASE=aaemployeedb_scale3m loadtest/rig/nodes.sh start 16 8101
#   loadtest/rig/nodes.sh check 16 8101
#   loadtest/rig/nodes.sh stop
#
# Windows/Git Bash notes: `php -S` resolves index.php from the working
# directory, so it runs from public/; MSYS_NO_PATHCONV stops Git Bash from
# rewriting arguments that start with "/"; the cache paths are relative to the
# project because absolute C:/ paths are joined onto the base path.
set -euo pipefail
export MSYS_NO_PATHCONV=1

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ROOT_WIN="$(cd "$ROOT" && pwd -W 2>/dev/null || pwd)"
ACTION="${1:-check}"
COUNT="${2:-8}"
BASE_PORT="${3:-8101}"
PIDS="$ROOT/storage/framework/bench/nodes.pids"
mkdir -p "$ROOT/storage/framework/bench" "$ROOT/storage/logs/bench"

: "${DB_DATABASE:?Set DB_DATABASE to the benchmark database}"
if [ "$DB_DATABASE" = "aaemployeedb" ]; then echo "Refusing the development database; use a benchmark copy." >&2; exit 1; fi

export APP_ENV="${APP_ENV:-local}" APP_DEBUG=false LOG_LEVEL=warning LOG_CHANNEL=single
export CACHE_STORE="${CACHE_STORE:-database}" SESSION_DRIVER=database QUEUE_CONNECTION=database
export INTEGRATION_CHANGE_FEED_ENABLED=true INTEGRATION_WEBHOOKS_ENABLED="${INTEGRATION_WEBHOOKS_ENABLED:-true}"
export APP_TRUSTED_PROXIES="${APP_TRUSTED_PROXIES:-127.0.0.1}"
export APP_CONFIG_CACHE=storage/framework/bench/config.php APP_ROUTES_CACHE=storage/framework/bench/routes.php
export APP_EVENTS_CACHE=storage/framework/bench/events.php APP_SERVICES_CACHE=storage/framework/bench/services.php
export APP_PACKAGES_CACHE=storage/framework/bench/packages.php
PHP_OPTS=(-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=0
  -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=40000 -d memory_limit=512M)

case "$ACTION" in
  start)
    (cd "$ROOT" && php artisan config:cache >/dev/null && php artisan route:cache >/dev/null && php artisan event:cache >/dev/null)
    : > "$PIDS"
    for i in $(seq 0 $((COUNT - 1))); do
      port=$((BASE_PORT + i))
      (cd "$ROOT/public" && nohup php "${PHP_OPTS[@]}" -S "127.0.0.1:$port" \
        "$ROOT_WIN/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" \
        < /dev/null > "$ROOT/storage/logs/bench/node-$port.log" 2>&1 & echo $! >> "$PIDS")
    done
    sleep 2
    "$0" check "$COUNT" "$BASE_PORT"
    ;;
  check)
    ok=0
    for i in $(seq 0 $((COUNT - 1))); do
      port=$((BASE_PORT + i))
      body=$(php -r '$c=@file_get_contents("http://127.0.0.1:'"$port"'/health/ready"); echo $c===false?"down":$c;')
      case "$body" in *'"status":"ok"'*) ok=$((ok + 1)) ;; *) echo "node $port: $body" ;; esac
    done
    echo "$ok/$COUNT nodes ready"
    ;;
  stop)
    [ -f "$PIDS" ] && while read -r pid; do kill "$pid" 2>/dev/null || true; done < "$PIDS"
    # php -S children on Windows survive their bash parent: stop them by port owner too.
    if command -v powershell.exe >/dev/null; then
      powershell.exe -NoProfile -Command "Get-NetTCPConnection -State Listen -LocalPort ($BASE_PORT..$((BASE_PORT + COUNT - 1))) -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id \$_.OwningProcess -Force -ErrorAction SilentlyContinue }" || true
    fi
    rm -f "$PIDS" "$ROOT"/storage/framework/bench/*.php
    echo stopped
    ;;
  *) echo "usage: $0 start|check|stop [count] [base-port]" >&2; exit 1 ;;
esac
