# Shared helpers of deploy.sh and rollback.sh (docs/runbook/core-deployment.md). Sourced, not run.
#
# Layout under CORE_ROOT:
#   releases/<id>/       unpacked artifacts (RELEASE + backend/)
#   shared/.env          the environment (secrets come from the secret store; never in an artifact)
#   shared/storage/      Laravel storage: private disks, logs, framework files
#   current              -> releases/<id>, what PHP-FPM serves
#   CURRENT_RELEASE, PREVIOUS_RELEASE   the ids, for rollback and the record
#
# Linux uses symbolic links and an atomic rename for the switch. Git Bash on
# Windows (local rehearsals only) uses directory junctions and copies the .env;
# that switch is not atomic.
set -euo pipefail
: "${CORE_ROOT:?Set CORE_ROOT to the deployment root}"
PHP="${PHP:-php}"
RELOAD_CMD="${RELOAD_CMD:-sudo systemctl reload php8.4-fpm}"
WORKERS_CMD="${WORKERS_CMD:-}"
SMOKE_URL="${SMOKE_URL:?Set SMOKE_URL to the site address, ending with /}"
SMOKE_CURL_OPTS="${SMOKE_CURL_OPTS:-}"
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) WINDOWS=1 ;; *) WINDOWS= ;; esac

log() { printf '[%s %s] %s\n' "${STEP:-deploy}" "$(date -u +%T)" "$*"; }
fail() { log "FAILED: $*"; exit 1; }

link_dir() {   # link_dir <target> <link>; an existing link is removed as a link, never by deleting through it
  if [ -n "$WINDOWS" ]; then
    if [ -e "$2" ]; then cmd //c rmdir "$(cygpath -w "$2")" 2> /dev/null || rm -rf "$2"; fi
    cmd //c mklink //J "$(cygpath -w "$2")" "$(cygpath -w "$1")" > /dev/null
  else
    if [ -L "$2" ]; then rm -f "$2"; else rm -rf "$2"; fi
    ln -sfn "$1" "$2"
  fi
}

link_file() {  # link_file <target> <link>
  rm -f "$2"
  if [ -n "$WINDOWS" ]; then cp "$1" "$2"; else ln -sfn "$1" "$2"; fi
}

link_shared() {  # link_shared <release dir>
  [ -f "$CORE_ROOT/shared/.env" ] || fail "missing $CORE_ROOT/shared/.env"
  mkdir -p "$CORE_ROOT/shared/storage"/{app/private,framework/{cache,sessions,views},logs}
  link_dir "$CORE_ROOT/shared/storage" "$1/backend/storage"
  link_file "$CORE_ROOT/shared/.env" "$1/backend/.env"
}

switch_current() {  # switch_current <release id>
  local id="$1"
  [ -d "$CORE_ROOT/releases/$id" ] || fail "no release $id"
  if [ -f "$CORE_ROOT/CURRENT_RELEASE" ] && [ "$(cat "$CORE_ROOT/CURRENT_RELEASE")" != "$id" ]; then
    cp "$CORE_ROOT/CURRENT_RELEASE" "$CORE_ROOT/PREVIOUS_RELEASE"
  fi
  if [ -n "$WINDOWS" ]; then
    [ -e "$CORE_ROOT/current" ] && cmd //c rmdir "$(cygpath -w "$CORE_ROOT/current")"
    cmd //c mklink //J "$(cygpath -w "$CORE_ROOT/current")" "$(cygpath -w "$CORE_ROOT/releases/$id")" > /dev/null
  else
    ln -sfn "releases/$id" "$CORE_ROOT/current.next" && mv -Tf "$CORE_ROOT/current.next" "$CORE_ROOT/current"
  fi
  echo "$id" > "$CORE_ROOT/CURRENT_RELEASE"
  log "current -> releases/$id"
}

reload_runtime() {
  log "reload: $RELOAD_CMD"
  eval "$RELOAD_CMD"
  (cd "$CORE_ROOT/current/backend" && "$PHP" artisan queue:restart > /dev/null)
  log "queue workers told to restart"
  if [ -n "$WORKERS_CMD" ]; then eval "$WORKERS_CMD"; fi
}

smoke() {  # smoke <expected release id>
  local id="$1" code body asset
  # /health/ready exists from release eca376af on; an older release is smoke-tested with SMOKE_PATHS="up login".
  for path in ${SMOKE_PATHS:-up health/live health/ready login}; do
    # The load balancer needs a probe interval or two to see reloaded nodes: retry for up to SMOKE_WAIT seconds.
    for _ in $(seq 1 "${SMOKE_WAIT:-30}"); do
      code="$(curl -s $SMOKE_CURL_OPTS -o /dev/null -w '%{http_code}' --max-time 20 "${SMOKE_URL}${path}" || true)"
      [ "$code" = 200 ] && break
      sleep 1
    done
    [ "$code" = 200 ] || fail "smoke: GET /$path answered $code"
  done
  # The sign-in page must load this release's own screens: its script name carries the build's hash.
  asset="$("$PHP" -r '$m = json_decode((string) file_get_contents($argv[1]), true); echo $m["resources/js/app.tsx"]["file"] ?? "";' "$CORE_ROOT/releases/$id/backend/public/build/manifest.json")"
  body="$(curl -s $SMOKE_CURL_OPTS --max-time 20 "${SMOKE_URL}login" || true)"
  case "$body" in *"$asset"*) ;; *) fail "smoke: /login does not serve $asset, the screens of release $id" ;; esac
  if [ -n "${CORE_MONITOR_TOKEN:-}" ]; then
    body="$(curl -s $SMOKE_CURL_OPTS --max-time 30 -H "Authorization: Bearer $CORE_MONITOR_TOKEN" "${SMOKE_URL}health/monitor" || true)"
    case "$body" in *"\"release\":\"$(head -n1 "$CORE_ROOT/releases/$id/RELEASE")\""*) ;; *) fail "smoke: /health/monitor does not report release $id" ;; esac
  fi
  log "smoke passed: ${SMOKE_PATHS:-up health/live health/ready login}; /login serves $asset${CORE_MONITOR_TOKEN:+; /health/monitor reports $id}"
}
