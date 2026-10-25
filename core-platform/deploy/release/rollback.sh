#!/usr/bin/env bash
# Rolls the Core application back to the previous release, or to the one named
# (docs/runbook/core-rollback.md).
#
#   CORE_ROOT=/srv/core SMOKE_URL=https://core.example/ rollback.sh [<release id>]
#
# Code only: the database is left as it is. Core migrations are additive within
# a release line, so the earlier release runs on the later schema; this script
# lists the migrations the earlier release does not know so the operator can
# confirm that. It never runs migrate:rollback (down() can drop data): a schema
# problem is fixed forward with a new migration.
STEP=rollback
. "$(dirname "$0")/lib.sh"
TARGET="${1:-$(cat "$CORE_ROOT/PREVIOUS_RELEASE" 2>/dev/null || true)}"
[ -n "$TARGET" ] || fail "no previous release recorded; name the release to roll back to"
FROM="$(cat "$CORE_ROOT/CURRENT_RELEASE" 2>/dev/null || echo unknown)"
[ "$TARGET" != "$FROM" ] || fail "release $TARGET is already current"
DEST="$CORE_ROOT/releases/$TARGET"
[ -d "$DEST/backend" ] || fail "releases/$TARGET is not on this server"
log "rolling back $FROM -> $TARGET"

link_shared "$DEST"
cd "$DEST/backend"
"$PHP" artisan optimize:clear > /dev/null && "$PHP" artisan optimize > /dev/null || fail "optimize"
ahead="$("$PHP" -r '
  require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $known = array_map(fn ($f) => basename($f, ".php"), glob("database/migrations/*.php"));
  echo implode(" ", array_diff(Illuminate\Support\Facades\DB::table("migrations")->pluck("migration")->all(), $known));')"
if [ -n "$ahead" ]; then log "the database is ahead of $TARGET by: $ahead (additive; left in place)"; else log "the database schema matches $TARGET"; fi

switch_current "$TARGET"
reload_runtime
smoke "$TARGET"
log "rolled back to $TARGET; $FROM stays in releases/ for the fix-forward"
