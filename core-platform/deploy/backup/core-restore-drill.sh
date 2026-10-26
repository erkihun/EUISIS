#!/usr/bin/env bash
# Restore drill: restores one Core backup into an ISOLATED environment and proves
# it works (docs/runbook/core-backup-and-restore.md). Never touches the source
# database, the live storage or the live application.
#
#   RESTORE_ROOT=/srv/restore-drill PGBIN=/usr/lib/postgresql/18/bin \
#   ARTIFACT=/srv/artifacts/core-xxxxxxxx.tar.gz ENV_FILE=/secure/core.env \
#   core-restore-drill.sh <backup directory> [verify credentials file]
#
#  1. checks every artifact against SHA256SUMS (use the OFF-HOST copy)
#  2. starts a new PostgreSQL cluster in RESTORE_ROOT (RESTORE_PORT, local only)
#  3. decrypts and restores the database (GPG private key: the restore operator's)
#  4. decrypts and unpacks the private files
#  5. unpacks the release artifact against the restored copy, with ENV_FILE (the
#     secrets come from the secret store, not from the backup) pointed at it
#  6. core:snapshot --compare: counts and checksums equal the backup's
#  7. file count equals the manifest; core:audit-verify on the restored trail
#  8. serves the restored copy and runs restore-verify.php (sign-in with MFA,
#     employees, assignments, hierarchy, cards, credentials, documents)
#  9. records restore_tested_at / restore_test_result in STATUS_FILE (optional)
# Timings and results go to RESTORE_ROOT/drill-report.txt.
set -Eeuo pipefail
BK="${1:?usage: core-restore-drill.sh <backup dir> [credentials file]}"
CREDS="${2:-}"
: "${RESTORE_ROOT:?}" "${PGBIN:?}" "${ARTIFACT:?}" "${ENV_FILE:?}"
ARTIFACT="$(cd "$(dirname "$ARTIFACT")" && pwd)/$(basename "$ARTIFACT")"   # no drive letter for tar
RESTORE_PORT="${RESTORE_PORT:-55432}"
APP_PORT="${APP_PORT:-8590}"
PHP="${PHP:-php}"
GPG=(gpg --batch --yes --quiet)
REPORT="$RESTORE_ROOT/drill-report.txt"
HERE="$(cd "$(dirname "$0")" && pwd)"
STEP=start
[ -e "$RESTORE_ROOT/pgdata" ] && { echo "RESTORE_ROOT already holds a drill; use a new one" >&2; exit 1; }
mkdir -p "$RESTORE_ROOT"
log() { printf '[restore %s] %s\n' "$(date -u +%T)" "$*" | tee -a "$REPORT"; }
now() { date +%s; }
cleanup() {
  [ -n "${APP_PID:-}" ] && kill "$APP_PID" 2> /dev/null || true
  "$PGBIN/pg_ctl" -D "$RESTORE_ROOT/pgdata" stop -m fast > /dev/null 2>&1 || true
}
record() {  # record <result> : restore_tested_at / restore_test_result into STATUS_FILE, when given
  [ -n "${STATUS_FILE:-}" ] || return 0
  "$PHP" -r '$f = $argv[1]; $d = json_decode((string) @file_get_contents($f), true) ?: []; $d["restore_tested_at"] = gmdate("c"); $d["restore_test_result"] = $argv[2];
    $d["restore_test_backup_id"] = $argv[3]; $d["restore_test_seconds"] = (int) $argv[4]; file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' \
    "$STATUS_FILE" "$1" "$(basename "$BK")" "$(( $(date +%s) - T0 ))"
}
trap 'log "FAILED at step: $STEP"; record "failed: $STEP"' ERR
trap cleanup EXIT

T0=$(now)
log "drill of $(basename "$BK") from $BK"
STEP=checksums; (cd "$BK" && sha256sum -c --quiet SHA256SUMS); log "checksums: all artifacts match SHA256SUMS"

STEP=cluster
"$PGBIN/initdb" -D "$RESTORE_ROOT/pgdata" -U core_restore --auth=trust --encoding=UTF8 --no-locale > "$RESTORE_ROOT/initdb.log"
"$PGBIN/pg_ctl" -D "$RESTORE_ROOT/pgdata" -o "-p $RESTORE_PORT -c listen_addresses=127.0.0.1" -l "$RESTORE_ROOT/postgres.log" -w start > /dev/null
"$PGBIN/createdb" -h 127.0.0.1 -p "$RESTORE_PORT" -U core_restore core_restored
log "isolated PostgreSQL $("$PGBIN/postgres" --version | awk '{print $3}') on 127.0.0.1:$RESTORE_PORT"

STEP=database; t=$(now)
"${GPG[@]}" --decrypt "$BK/db.dump.gpg" | "$PGBIN/pg_restore" --no-owner --no-privileges --exit-on-error -h 127.0.0.1 -p "$RESTORE_PORT" -U core_restore -d core_restored
"$PGBIN/psql" -q -h 127.0.0.1 -p "$RESTORE_PORT" -U core_restore -d core_restored -c 'ANALYZE' > /dev/null
DB_S=$(( $(now) - t )); log "database restored in ${DB_S}s"

STEP=files; t=$(now)
mkdir -p "$RESTORE_ROOT/storage/app/private" "$RESTORE_ROOT/storage/framework/"{cache,sessions,views} "$RESTORE_ROOT/storage/logs"
"${GPG[@]}" --decrypt "$BK/files.tar.gpg" | tar -x -C "$RESTORE_ROOT/storage/app"
FILES=$(find "$RESTORE_ROOT/storage/app" -type f | wc -l | tr -d ' ')
EXPECTED=$("$PHP" -r 'echo json_decode((string) file_get_contents($argv[1]), true)["files"]["count"];' "$BK/manifest.json")
[ "$FILES" = "$EXPECTED" ] || { log "file count $FILES differs from the manifest's $EXPECTED"; false; }
log "files restored in $(( $(now) - t ))s: $FILES files (manifest: $EXPECTED)"

STEP=application
mkdir -p "$RESTORE_ROOT/app" && tar -xzf "$ARTIFACT" -C "$RESTORE_ROOT/app"
rm -rf "$RESTORE_ROOT/app/backend/storage"
case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*) cmd //c mklink //J "$(cygpath -w "$RESTORE_ROOT/app/backend/storage")" "$(cygpath -w "$RESTORE_ROOT/storage")" > /dev/null ;;
  *) ln -s "$RESTORE_ROOT/storage" "$RESTORE_ROOT/app/backend/storage" ;;
esac
# The recovered environment: the real secrets, pointed at the isolated copy (database, files, seal heads, no backup status).
grep -v -E '^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|APP_URL|CORE_BACKUP_STATUS_FILE|SESSION_SECURE_COOKIE|QUEUE_CONNECTION|MAIL_MAILER)=' "$ENV_FILE" > "$RESTORE_ROOT/app/backend/.env"
printf '%s\n' "DB_HOST=127.0.0.1" "DB_PORT=$RESTORE_PORT" "DB_DATABASE=core_restored" "DB_USERNAME=core_restore" "DB_PASSWORD=" \
  "APP_URL=http://127.0.0.1:$APP_PORT" "SESSION_SECURE_COOKIE=false" "QUEUE_CONNECTION=sync" "MAIL_MAILER=array" >> "$RESTORE_ROOT/app/backend/.env"
cd "$RESTORE_ROOT/app/backend"
"$PHP" artisan storage:link --force > /dev/null 2>&1 || true   # the public disk (settings images)
"$PHP" artisan optimize > /dev/null
log "release $(head -n1 ../RELEASE | cut -c1-8) unpacked against the restored copy"

STEP=snapshot
"$PHP" artisan core:snapshot --compare="$BK/snapshot.json" > "$RESTORE_ROOT/snapshot-compare.txt" 2>&1 || { cat "$RESTORE_ROOT/snapshot-compare.txt" | tee -a "$REPORT"; false; }
log "core:snapshot --compare: identical to the backup ($(grep -c . "$RESTORE_ROOT/snapshot-compare.txt") lines of report)"
"$PHP" -r '
  require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $db = Illuminate\Support\Facades\DB::connection();
  foreach (["organizations", "organization_units", "positions", "employees", "employee_assignments", "id_cards", "digital_credentials", "employee_documents", "users", "core_roles", "user_scopes", "core_audit_logs", "audit_seals", "migrations"] as $t) {
    try { printf("  %-22s %s\n", $t, $db->table($t)->count()); } catch (Throwable $e) { printf("  %-22s n/a\n", $t); } }' | tee -a "$REPORT"

STEP=audit
"$PHP" artisan core:audit-verify --restored 2>&1 | tail -n 1 | tee -a "$REPORT"

STEP=serve
# Nothing may already answer on the port: a server left from an earlier drill would be verified instead of this copy.
"$PHP" -r 'exit(@file_get_contents("http://127.0.0.1:".$argv[1]."/up") === false ? 0 : 1);' "$APP_PORT" || { log "port $APP_PORT is already in use"; false; }
# exec: APP_PID is the server itself, not a subshell, so the cleanup stops it.
(cd public && exec "$PHP" -S "127.0.0.1:$APP_PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > "$RESTORE_ROOT/serve.log" 2>&1) &
APP_PID=$!
for _ in $(seq 1 30); do "$PHP" -r 'exit(@file_get_contents("http://127.0.0.1:".$argv[1]."/health/live") === false ? 1 : 0);' "$APP_PORT" && break; sleep 1; done
if [ -n "$CREDS" ]; then
  STEP=verify
  "$PHP" "$HERE/restore-verify.php" "http://127.0.0.1:$APP_PORT" "$CREDS" | tee -a "$REPORT"
fi

TOTAL=$(( $(now) - T0 ))
log "RESTORE DRILL PASSED in ${TOTAL}s (database ${DB_S}s)"
record passed
