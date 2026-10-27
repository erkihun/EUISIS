#!/usr/bin/env bash
# Local application nodes for the Core staging rehearsal (docs/runbook/core-staging-rehearsal.md).
# TEST RIG ONLY: N single-threaded `php -S` processes stand in for PHP-FPM workers,
# serving CORE_ROOT/current/backend/public with OPcache on (restart after a deploy,
# like reloading PHP-FPM). The release's own cached configuration is used.
#
#   CORE_ROOT=... nodes.sh start|restart|check|stop [count] [base-port]
set -euo pipefail
export MSYS_NO_PATHCONV=1
: "${CORE_ROOT:?}"
ACTION="${1:-check}"; COUNT="${2:-8}"; BASE_PORT="${3:-8601}"
PIDS="$CORE_ROOT/nodes.pids"
mkdir -p "$CORE_ROOT/shared/storage/logs/nodes"
PHP_OPTS=(-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=0
  -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=40000 -d memory_limit=512M)
ROOT_WIN="$(cd "$CORE_ROOT" && pwd -W 2> /dev/null || pwd)"

stop() {
  [ -f "$PIDS" ] && while read -r pid; do kill "$pid" 2> /dev/null || true; done < "$PIDS"
  if command -v powershell.exe > /dev/null; then
    powershell.exe -NoProfile -Command "Get-NetTCPConnection -State Listen -LocalPort ($BASE_PORT..$((BASE_PORT + COUNT - 1))) -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id \$_.OwningProcess -Force -ErrorAction SilentlyContinue }" || true
  fi
  rm -f "$PIDS"
}
check() {
  local ok=0 port body
  for i in $(seq 0 $((COUNT - 1))); do
    port=$((BASE_PORT + i))
    # /health/ready from release eca376af on; an older release answers Laravel's /up only.
    body=$(php -r '$c = @file_get_contents("http://127.0.0.1:'"$port"'/health/ready");
      if ($c === false && str_contains($http_response_header[0] ?? "", " 404")) { $c = @file_get_contents("http://127.0.0.1:'"$port"'/up") === false ? false : "{\"status\":\"ok\",\"via\":\"up\"}"; }
      echo $c === false ? "down" : $c;')
    case "$body" in *'"status":"ok"'*) ok=$((ok + 1)) ;; *) echo "node $port: $body" ;; esac
  done
  echo "$ok/$COUNT nodes ready"
  [ "$ok" = "$COUNT" ]
}
start() {
  : > "$PIDS"
  for i in $(seq 0 $((COUNT - 1))); do
    port=$((BASE_PORT + i))
    (cd "$CORE_ROOT/current/backend/public" && nohup php "${PHP_OPTS[@]}" -S "127.0.0.1:$port" \
      "$ROOT_WIN/current/backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" \
      < /dev/null > "$CORE_ROOT/shared/storage/logs/nodes/node-$port.log" 2>&1 & echo $! >> "$PIDS") < /dev/null > /dev/null 2>&1
    # (the subshell's own descriptors are closed too, so a caller's pipe, e.g. deploy.sh | tee, is not held open)
  done
  for _ in $(seq 1 20); do sleep 1; check > /dev/null 2>&1 && break; done
  check
}
case "$ACTION" in
  start) start ;;
  restart) stop; sleep 1; start ;;
  check) check ;;
  stop) stop; echo stopped ;;
  *) echo "usage: nodes.sh start|restart|check|stop [count] [base-port]" >&2; exit 1 ;;
esac
