#!/usr/bin/env bash
# Queue workers and the scheduler for the Core staging rehearsal
# (docs/runbook/core-staging-rehearsal.md). TEST RIG ONLY: stands in for the
# systemd/supervisor units. Production runs `queue:work` under a supervisor and
# `schedule:run` from cron (docs/runbook/core-deployment.md).
#
#   CORE_ROOT=... workers.sh start|restart|stop [worker count]
set -euo pipefail
: "${CORE_ROOT:?}"
ACTION="${1:-start}"; COUNT="${2:-2}"
PIDS="$CORE_ROOT/workers.pids"
LOGS="$CORE_ROOT/shared/storage/logs/workers"
mkdir -p "$LOGS"
stop() {
  [ -f "$PIDS" ] || return 0
  while read -r pid; do kill "$pid" 2> /dev/null || true; done < "$PIDS"
  # php children on Windows outlive their bash parents: stop them by command line.
  if command -v powershell.exe > /dev/null; then
    powershell.exe -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { \$_.CommandLine -match 'queue:work|schedule:work' -and \$_.CommandLine -match 'core-staging' } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }" || true
  fi
  rm -f "$PIDS"
}
start() {
  : > "$PIDS"
  cd "$CORE_ROOT/current/backend"
  for i in $(seq 1 "$COUNT"); do
    nohup php artisan queue:work database --name=core-staging-$i --sleep=1 --tries=3 --max-time=3600 --memory=256 < /dev/null >> "$LOGS/worker-$i.log" 2>&1 &
    echo $! >> "$PIDS"
  done
  nohup php artisan schedule:work --whisper < /dev/null >> "$LOGS/scheduler.log" 2>&1 &
  echo $! >> "$PIDS"
  echo "$COUNT workers and the scheduler started"
}
case "$ACTION" in
  start) start ;;
  restart) stop; start ;;
  stop) stop; echo stopped ;;
  *) echo "usage: workers.sh start|restart|stop [count]" >&2; exit 1 ;;
esac
