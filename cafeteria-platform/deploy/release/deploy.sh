#!/usr/bin/env bash
# Deploys a Core release artifact (docs/runbook/core-deployment.md).
#
#   CORE_ROOT=/srv/core SMOKE_URL=https://core.example/ deploy.sh <core-xxxxxxxx.tar.gz>
#
# 1. checks the artifact against its .sha256      5. caches config, events, routes and views
# 2. unpacks it into releases/<id>                6. runs the production configuration review
# 3. links shared/.env and shared/storage           (fails the deployment in production)
# 4. migrates (backward-compatible only, while    7. switches current, reloads PHP-FPM, restarts workers
#    the previous release still serves)           8. smoke test; keeps the last KEEP releases
#
# Any failure stops it. Before step 7 the previous release keeps serving; after
# it, use rollback.sh. Optional: PRE_DEPLOY_CMD (e.g. the backup) runs first.
STEP=deploy
. "$(dirname "$0")/lib.sh"
ART="${1:?usage: deploy.sh <artifact.tar.gz>}"
ART="$(cd "$(dirname "$ART")" && pwd)/$(basename "$ART")"   # no drive letter for tar
KEEP="${KEEP:-5}"
ID="$(basename "$ART" .tar.gz)"; ID="${ID#core-}"
DEST="$CORE_ROOT/releases/$ID"

(cd "$(dirname "$ART")" && sha256sum -c --quiet "$(basename "$ART").sha256") || fail "checksum of $ART does not match"
log "artifact $ID verified"
if [ -n "${PRE_DEPLOY_CMD:-}" ]; then log "pre-deploy: $PRE_DEPLOY_CMD"; eval "$PRE_DEPLOY_CMD" || fail "pre-deploy command"; fi

if [ -d "$DEST" ]; then log "releases/$ID exists: reusing it"; else mkdir -p "$DEST" && tar -xzf "$ART" -C "$DEST"; fi
link_shared "$DEST"
cd "$DEST/backend"
"$PHP" artisan --version > /dev/null || fail "the release does not boot"
s=$(date +%s)
"$PHP" artisan migrate --force --no-interaction || fail "migrate"
log "migrated in $(( $(date +%s) - s ))s"
"$PHP" artisan optimize > /dev/null || fail "optimize (config, events, routes, views)"
"$PHP" artisan core:config-audit > "$CORE_ROOT/shared/storage/logs/config-audit-$ID.txt" || { cat "$CORE_ROOT/shared/storage/logs/config-audit-$ID.txt"; fail "core:config-audit"; }
log "configuration review passed"

switch_current "$ID"
reload_runtime
smoke "$ID"

# Keep the newest KEEP releases plus whatever current and previous point at.
keep="$(cat "$CORE_ROOT/CURRENT_RELEASE") $(cat "$CORE_ROOT/PREVIOUS_RELEASE" 2>/dev/null || true)"
ls -1t "$CORE_ROOT/releases" | tail -n +$((KEEP + 1)) | while read -r old; do
  case " $keep " in *" $old "*) continue ;; esac
  log "removing old release $old"
  if [ -n "$WINDOWS" ]; then cmd //c rmdir "$(cygpath -w "$CORE_ROOT/releases/$old/backend/storage")" 2>/dev/null || true; fi
  rm -rf "${CORE_ROOT:?}/releases/$old"
done
log "release $ID is live"
