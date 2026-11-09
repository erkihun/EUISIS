#!/usr/bin/env bash
# Isolated logical backup + restore drill (docs/production-readiness/backup-pitr-restore.md).
#
#   PGHOST=127.0.0.1 PGUSER=… PGPASSWORD=… loadtest/db/restore-drill.sh <source-db> <new-target-db> <dump-dir> [jobs]
#
# 1. pg_dump --format=directory --jobs=N of the source (a consistent snapshot).
# 2. createdb <target> (must not exist) and pg_restore --jobs=N into it.
# 3. Compares exact row counts of the master-data and transaction tables between
#    source and target and runs loadtest/db/integrity-checks.sql on the target.
# Times every step. It never writes to the source and refuses an existing target,
# so it cannot overwrite a database. This proves a LOGICAL restore only: it is
# not PITR and not a test of the production pgBackRest repositories.
set -euo pipefail
SRC="$1"; DST="$2"; DIR="$3"; JOBS="${4:-8}"
BIN="${PGBIN:-/c/Program Files/PostgreSQL/18/bin}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
case "$DST" in aaemployeedb|*prod*|"$SRC") echo "Refusing target $DST" >&2; exit 1 ;; esac
if "$BIN/psql" -d postgres -Atc "select 1 from pg_database where datname='$DST'" | grep -q 1; then
  echo "Target $DST exists; refusing to overwrite." >&2; exit 1
fi
[ -e "$DIR" ] && { echo "Dump dir $DIR exists; refusing." >&2; exit 1; }

t() { date +%s; }
s=$(t); "$BIN/pg_dump" --format=directory --jobs="$JOBS" --no-owner --file="$DIR" "$SRC"; dump_s=$(( $(t) - s ))
dump_size=$(du -sh "$DIR" | cut -f1)
s=$(t); "$BIN/createdb" "$DST"; "$BIN/pg_restore" --jobs="$JOBS" --no-owner --dbname="$DST" "$DIR"; restore_s=$(( $(t) - s ))
s=$(t); "$BIN/psql" -d "$DST" -qc 'ANALYZE' ; analyze_s=$(( $(t) - s ))

echo "dump: ${dump_s}s (${dump_size} on disk, ${JOBS} jobs) | restore: ${restore_s}s | analyze: ${analyze_s}s"
echo "source size: $("$BIN/psql" -d "$SRC" -Atc "select pg_size_pretty(pg_database_size(current_database()))") | restored size: $("$BIN/psql" -d "$DST" -Atc "select pg_size_pretty(pg_database_size(current_database()))")"

COUNTS="select 'employees', count(*) from employees union all select 'employee_assignments', count(*) from employee_assignments
 union all select 'id_cards', count(*) from id_cards union all select 'positions', count(*) from positions
 union all select 'organizations', count(*) from organizations union all select 'organization_units', count(*) from organization_units
 union all select 'entitlements', count(*) from entitlements union all select 'service_transactions', count(*) from service_transactions
 union all select 'cafeteria_transactions', count(*) from cafeteria_transactions union all select 'audit_logs', count(*) from audit_logs
 union all select 'integration_events', count(*) from integration_events union all select 'webhook_deliveries', count(*) from webhook_deliveries
 union all select 'users', count(*) from users union all select 'migrations', count(*) from migrations
 union all select 'checksum employees', sum(hashtext(id::text || employee_number || coalesce(full_name, '')))::bigint from employees
 union all select 'checksum id_cards', sum(hashtext(id::text || status || coalesce(public_card_uuid::text, '')))::bigint from id_cards"
diff <("$BIN/psql" -d "$SRC" -Atc "$COUNTS" | sort) <("$BIN/psql" -d "$DST" -Atc "$COUNTS" | sort) && echo "row counts and checksums: identical" || { echo "MISMATCH between source and restore" >&2; exit 2; }
"$BIN/psql" -d "$DST" -f "$ROOT/loadtest/db/integrity-checks.sql"
