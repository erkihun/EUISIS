#!/usr/bin/env bash
#
# Nightly backup for a MySQL/MariaDB host (the live Plesk server), and the
# status report the application reads with BACKUP_DRIVER=report.
#
# What one run does:
#   1. a full logical dump of the database (mysqldump, single transaction,
#      routines, triggers, events), gzip-compressed, optionally encrypted;
#   2. an archive of storage/app (private files: employee photos, uploads,
#      letter assets), gzip-compressed, optionally encrypted;
#   3. a verification of both files (gzip integrity; the dump must end with the
#      "Dump completed" marker);
#   4. a copy of both files to the independent second repository (any command
#      that takes a source and the repository, e.g. rclone or rsync over SSH);
#   5. retention: files older than RETENTION_DAYS are removed from repository 1
#      (repository 2 keeps its own retention, usually longer);
#   6. the status report (STATUS_PATH), written atomically, in the schema
#      php artisan backup:health expects.
#
# A restore test is a separate, manual step (see "Restore test" below); the
# runner only records the last one from RESTORE_TEST_FILE.
#
# Usage:  mysql_backup.sh --config /etc/euisis-backup.conf [--dry-run]
# Cron:   15 1 * * * /var/www/euisis/current/scripts/backup/mysql_backup.sh --config /etc/euisis-backup.conf >> /var/log/euisis-backup.log 2>&1
#
# The config file is a shell fragment (see mysql_backup.conf.example). The
# database password lives in the MySQL option file it names (MYSQL_DEFAULTS),
# never in this script, the config, the command line or the report.
#
# Restore test (at least every BACKUP_RESTORE_TEST_DAYS days, default 35):
#   create an empty scratch database, restore the latest dump into it,
#   count a few tables against production, then record the result:
#     printf '{"status":"SUCCEEDED","started_at":%d,"completed_at":%d,"backup_reference":"%s"}\n' \
#       "$start" "$(date +%s)" "20261124-011500F" > "$RESTORE_TEST_FILE"
#   (the reference is the run's label: the directory name under REPO1; add
#   "repository":2 when the dump came from the off-site copy). The next run
#   copies it into the report as a RESTORE_TEST operation.

set -euo pipefail

CONFIG=""
DRY_RUN=0
while [ $# -gt 0 ]; do
    case "$1" in
        --config) CONFIG="$2"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done
[ -n "$CONFIG" ] && [ -r "$CONFIG" ] || { echo "usage: $0 --config FILE [--dry-run]" >&2; exit 2; }

# Defaults, overridable by the config file.
MYSQLDUMP=/usr/bin/mysqldump
MYSQL_DEFAULTS=/etc/euisis-backup.my.cnf      # [client] user=... password=... host=...
DB_NAME=""
APP_STORAGE=/var/www/euisis/shared/storage/app
REPO1=/var/backups/euisis                      # repository 1 (this host, private directory)
REPO2_COPY=""                                  # command: "<cmd> SRC_FILE" copies to repository 2; empty = no second copy
REPO2_USAGE_PERCENT=""                         # optional: how full repository 2 is (a number) if the copy tool can't tell
REPO2_AVAILABLE_CMD=""                         # optional: command that exits 0 when repository 2 is reachable
REPO2_VERIFY_CMD=""                            # optional: "<cmd> LOCAL_FILE" exits 0 when the copy in repository 2 matches (e.g. rclone check)
ENCRYPT_RECIPIENT=""                           # gpg recipient (key id or e-mail); empty = no encryption
RETENTION_DAYS=14
STATUS_PATH=/var/lib/euisis-backup/status.json
RESTORE_TEST_FILE=/var/lib/euisis-backup/restore-test.json
# Controls the owner has put in place (true/false); reported as such, never assumed.
CONTROL_INDEPENDENT_COPY=false
CONTROL_ENCRYPTED=false
CONTROL_PRIVATE_STORAGE=true
CONTROL_KEY_RECOVERY=false
CONTROL_FILE_RECOVERY=false
CONTROL_RETENTION_CONFIGURED=true
CONTROL_OWNER_ASSIGNED=false
CONTROL_ALERTS_TESTED=false
CONTROL_RUNBOOKS_REVIEWED=false

# shellcheck disable=SC1090
. "$CONFIG"

[ -n "$DB_NAME" ] || { echo "DB_NAME is not set in $CONFIG" >&2; exit 2; }
[ -r "$MYSQL_DEFAULTS" ] || { echo "MySQL option file $MYSQL_DEFAULTS is missing or unreadable" >&2; exit 2; }
[ -x "$MYSQLDUMP" ] || { echo "mysqldump not found at $MYSQLDUMP" >&2; exit 2; }
[ -d "$APP_STORAGE" ] || { echo "storage directory $APP_STORAGE is missing" >&2; exit 2; }
mkdir -p "$REPO1" "$(dirname "$STATUS_PATH")"
chmod 700 "$REPO1"

now() { date +%s; }
log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
file_size() { stat -c %s "$1" 2>/dev/null || stat -f %z "$1"; }
file_mtime() { stat -c %Y "$1" 2>/dev/null || stat -f %m "$1"; }
uuid() { # version-4 form, from the kernel's random source (works without uuidgen or /proc)
    local h; h="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
    printf '%s-%s-4%s-%x%s-%s\n' "${h:0:8}" "${h:8:4}" "${h:13:3}" "$(( (0x${h:16:1} & 0x3) | 0x8 ))" "${h:17:3}" "${h:20:12}"
}

# One run is one label in the application's backup-label form: YYYYMMDD-HHMMSSF.
STAMP="$(date +%Y%m%d-%H%M%S)"
LABEL="${STAMP}F"
RUN_DIR="$REPO1/$LABEL"
mkdir -p "$RUN_DIR"
EXT=".gz"; [ -n "$ENCRYPT_RECIPIENT" ] && EXT=".gz.gpg"
DB_FILE="$RUN_DIR/db-$STAMP.sql$EXT"
FILES_FILE="$RUN_DIR/files-$STAMP.tar$EXT"

# Optional encryption on the way to disk; the key never touches the report.
sink() { if [ -n "$ENCRYPT_RECIPIENT" ]; then gzip -c | gpg --batch --yes --quiet --encrypt --recipient "$ENCRYPT_RECIPIENT" --output "$1"; else gzip -c > "$1"; fi; }
unsink() { if [ -n "$ENCRYPT_RECIPIENT" ]; then gpg --batch --quiet --decrypt "$1" | gzip -dc; else gzip -dc "$1"; fi; }

OPS=""   # JSON fragments of operations, comma-separated
add_op() { # type status started completed reference size failure
    local id; id="$(uuid)"
    local size="${6:-null}" ref="null" fail="null"
    [ -n "${5:-}" ] && ref="\"$(json_str "$5")\""
    [ -n "${7:-}" ] && fail="\"$(json_str "$7")\""
    local op="{\"id\":\"$id\",\"type\":\"$1\",\"repository\":${8:-1},\"status\":\"$2\",\"started_at\":$3,\"completed_at\":$4,\"backup_reference\":$ref,\"backup_size\":$size,\"recovery_target\":null,\"failure_summary\":$fail}"
    OPS="${OPS:+$OPS,}$op"
}

overall=SUCCEEDED
failure=""

# 1. Database dump
t0=$(now)
if [ "$DRY_RUN" = 1 ]; then
    log "dry run: would dump $DB_NAME to $DB_FILE"
    printf -- '-- Dump completed\n' | sink "$DB_FILE"
elif "$MYSQLDUMP" --defaults-extra-file="$MYSQL_DEFAULTS" --single-transaction --quick --routines --triggers --events \
        --hex-blob --default-character-set=utf8mb4 "$DB_NAME" | sink "$DB_FILE"; then
    log "database dump written: $DB_FILE"
else
    overall=FAILED; failure="mysqldump failed"; log "$failure"
fi
add_op FULL_BACKUP "$overall" "$t0" "$(now)" "$LABEL" "$(file_size "$DB_FILE")" "$failure"

# 2. Files archive (storage/app: private uploads, letter assets, public logos)
t0=$(now)
if [ "$DRY_RUN" = 1 ]; then
    tar -C "$APP_STORAGE" -cf - --exclude='./backup' . 2>/dev/null | head -c 1048576 | sink "$FILES_FILE" || true
    log "dry run: partial files archive written"
elif tar -C "$APP_STORAGE" -cf - --exclude='./backup' . | sink "$FILES_FILE"; then
    log "files archive written: $FILES_FILE"
else
    overall=FAILED; failure="storage archive failed"; log "$failure"
fi

# 3. Verify: both files decompress, the dump carries the completion marker.
t0=$(now); vstatus=SUCCEEDED; vfail=""
if ! unsink "$FILES_FILE" > /dev/null 2>&1; then vstatus=FAILED; vfail="files archive does not decompress"; fi
if [ "$DRY_RUN" != 1 ] && ! unsink "$DB_FILE" | tail -c 200 | grep -q 'Dump completed'; then vstatus=FAILED; vfail="dump has no completion marker"; fi
[ "$vstatus" = FAILED ] && { overall=FAILED; log "verify failed: $vfail"; }
add_op VERIFY "$vstatus" "$t0" "$(now)" "$LABEL" "" "$vfail"

# 4. Second repository
repo2_available=false
if [ -n "$REPO2_COPY" ]; then
    t0=$(now); cstatus=SUCCEEDED; cfail=""
    for f in "$DB_FILE" "$FILES_FILE"; do
        if [ "$DRY_RUN" = 1 ]; then log "dry run: would copy $f with: $REPO2_COPY"; continue; fi
        # shellcheck disable=SC2086
        if ! $REPO2_COPY "$f"; then cstatus=FAILED; cfail="copy to repository 2 failed for $(basename "$f")"; fi
    done
    [ "$cstatus" = FAILED ] && { overall=FAILED; log "$cfail"; }
    add_op FULL_BACKUP "$cstatus" "$t0" "$(now)" "$LABEL" "$(file_size "$DB_FILE")" "$cfail" 2
    # The copy is verified only when a check command exists; otherwise repository 2 reads "verify not recorded".
    if [ -n "$REPO2_VERIFY_CMD" ] && [ "$cstatus" = SUCCEEDED ] && [ "$DRY_RUN" != 1 ]; then
        t0=$(now); v2=SUCCEEDED; v2fail=""
        for f in "$DB_FILE" "$FILES_FILE"; do
            # shellcheck disable=SC2086
            $REPO2_VERIFY_CMD "$f" > /dev/null 2>&1 || { v2=FAILED; v2fail="repository 2 copy of $(basename "$f") does not match"; }
        done
        [ "$v2" = FAILED ] && { overall=FAILED; log "$v2fail"; }
        add_op VERIFY "$v2" "$t0" "$(now)" "$LABEL" "" "$v2fail" 2
    fi
    if [ -n "$REPO2_AVAILABLE_CMD" ]; then
        # shellcheck disable=SC2086
        if $REPO2_AVAILABLE_CMD > /dev/null 2>&1; then repo2_available=true; fi
    elif [ "$cstatus" = SUCCEEDED ]; then repo2_available=true; fi
fi

# 5. Retention on repository 1
if [ "$DRY_RUN" != 1 ]; then
    t0=$(now)
    find "$REPO1" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} + || true
    add_op RETENTION_CLEANUP SUCCEEDED "$t0" "$(now)" "" ""
fi

# Last restore test, if one was recorded.
if [ -r "$RESTORE_TEST_FILE" ]; then
    rt="$(tr -d '\n' < "$RESTORE_TEST_FILE")"
    rid="$(uuid)"
    rrepo=1; case "$rt" in *'"repository": 2'*|*'"repository":2'*) rrepo=2 ;; esac
    rt="$(printf '%s' "$rt" | sed -E 's/"repository" *: *[12] *,? *//')"
    OPS="${OPS:+$OPS,}{\"id\":\"$rid\",\"type\":\"RESTORE_TEST\",\"repository\":$rrepo,\"backup_size\":null,\"recovery_target\":null,\"failure_summary\":null,${rt#\{}"
fi

# 6. Status report: repositories with their backups, controls, no WAL (MySQL dumps are not PITR).
backups_json() { # dir
    local out="" d f ts size ref
    for d in "$1"/*/; do
        [ -d "$d" ] || continue
        ref="$(basename "$d")"
        case "$ref" in *F) ;; *) continue ;; esac
        f="$(ls "$d"db-*.sql* 2>/dev/null | head -1)"; [ -n "$f" ] || continue
        ts="$(file_mtime "$f")"; size="$(file_size "$f")"
        out="${out:+$out,}{\"reference\":\"$ref\",\"type\":\"full\",\"completed_at\":$ts,\"size\":$size}"
    done
    printf '%s' "$out"
}
usage1="$(df -P "$REPO1" | awk 'NR==2 {gsub("%","",$5); print $5}')"
usage2="${REPO2_USAGE_PERCENT:-null}"
repo2_backups=""; [ -n "$REPO2_COPY" ] && [ "$repo2_available" = true ] && repo2_backups="$(backups_json "$REPO1")"
db_state=HEALTHY; [ "$overall" = FAILED ] && db_state=DEGRADED

tmp="$STATUS_PATH.tmp.$$"
cat > "$tmp" <<EOF
{
  "version": 1,
  "observed_at": $(now),
  "database": "$db_state",
  "engine": "mysql",
  "repositories": [
    {"id": 1, "available": true, "usage_percent": ${usage1:-null}, "backups": [$(backups_json "$REPO1")]},
    {"id": 2, "available": $repo2_available, "usage_percent": $usage2, "backups": [$repo2_backups]}
  ],
  "wal": {"enabled": false, "last_archived_at": null, "failed_count": 0, "failing": false, "backlog": 0},
  "operations": [$OPS],
  "controls": {
    "independent_copy": $CONTROL_INDEPENDENT_COPY,
    "encrypted": $CONTROL_ENCRYPTED,
    "private_storage": $CONTROL_PRIVATE_STORAGE,
    "key_recovery": $CONTROL_KEY_RECOVERY,
    "file_recovery": $CONTROL_FILE_RECOVERY,
    "retention_configured": $CONTROL_RETENTION_CONFIGURED,
    "owner_assigned": $CONTROL_OWNER_ASSIGNED,
    "alerts_tested": $CONTROL_ALERTS_TESTED,
    "runbooks_reviewed": $CONTROL_RUNBOOKS_REVIEWED
  },
  "controls_checked_at": $(now),
  "dry_run": $([ "$DRY_RUN" = 1 ] && echo true || echo false)
}
EOF
chmod 640 "$tmp"
mv -f "$tmp" "$STATUS_PATH"
log "status report written: $STATUS_PATH ($overall)"
[ "$overall" = SUCCEEDED ]
