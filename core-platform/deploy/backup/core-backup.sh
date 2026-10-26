#!/usr/bin/env bash
# One Core backup run (docs/runbook/core-backup-and-restore.md). Scheduled by
# cron or a systemd timer on the backup host (crontab.example), never by the
# application. Configuration: BACKUP_CONFIG (default /etc/core-backup/backup.env,
# see backup.env.example); database passwords come from ~/.pgpass or a service
# file, never from this configuration.
#
# What a run produces, in BACKUP_REPO/<id>/:
#   database      pgBackRest (BACKUP_DB_TOOL=pgbackrest, production: physical,
#                 WAL archiving, repository encryption and a second off-host
#                 repository are pgBackRest's own configuration) and/or a
#                 logical dump (pg_dump custom format) encrypted to GPG_RECIPIENT
#   files         the private disks (employee documents, photos, signatures,
#                 imports, exports) as a tar encrypted to GPG_RECIPIENT
#   snapshot.json core:snapshot counts and checksums, taken just before the dump,
#                 for the restore comparison (no personal data)
#   manifest.json what ran, sizes, file counts, release; SHA256SUMS of every artifact
# then copies the directory off the host (OFFHOST_DIR or OFFHOST_CMD) and checks
# the copy, applies retention (RETAIN_COUNT) and writes the status file the Core
# reads (CORE_BACKUP_STATUS_FILE). Encryption is to a public key: the backup host
# cannot read its own backups; the private key is held for restores (escrow).
#
# Any failed step writes last_failure_at with the step name (System Health turns
# critical), runs ALERT_CMD if set, and exits non-zero.
set -Eeuo pipefail
CONFIG="${BACKUP_CONFIG:-/etc/core-backup/backup.env}"
# shellcheck source=/dev/null
[ -f "$CONFIG" ] && . "$CONFIG"
: "${BACKUP_REPO:?}" "${STATUS_FILE:?}" "${FILES_ROOT:?}" "${CORE_APP:?}" "${GPG_RECIPIENT:?}"
DB_TOOL="${BACKUP_DB_TOOL:-pgbackrest}"
RETAIN_COUNT="${RETAIN_COUNT:-14}"
RETENTION_DAYS="${RETENTION_DAYS:-$RETAIN_COUNT}"
PHP="${PHP:-php}"
GPG=(gpg --batch --yes --quiet --trust-model always)
ID="core-$(date -u +%Y%m%dT%H%M%SZ)"
DIR="$BACKUP_REPO/$ID"
STEP=start
log() { printf '[backup %s] %s\n' "$(date -u +%T)" "$*"; }

# status key=value ... : merges into the status file atomically (strings; true/false/null and digits as JSON).
status() {
  "$PHP" -r '
    $file = $argv[1]; $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    foreach (array_slice($argv, 2) as $pair) { [$k, $v] = explode("=", $pair, 2);
      $data[$k] = match (true) { $v === "true" => true, $v === "false" => false, $v === "null" => null, ctype_digit($v) => (int) $v, default => $v }; }
    $tmp = $file.".tmp"; file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); rename($tmp, $file);' "$STATUS_FILE" "$@"
}
on_error() {
  log "FAILED at step: $STEP"
  status "last_failure_at=$(date -u +%FT%TZ)" "last_failure_reason=backup step failed: $STEP" "last_failure_id=$ID" || true
  if [ -n "${ALERT_CMD:-}" ]; then eval "$ALERT_CMD" || true; fi
}
trap on_error ERR

started="$(date -u +%FT%TZ)"
mkdir -p "$DIR"
log "backup $ID"

STEP=snapshot
(cd "$CORE_APP" && "$PHP" artisan core:snapshot --output="$DIR/snapshot.json" > /dev/null)

STEP=database
db_note=""
case "$DB_TOOL" in
  pgbackrest|both)
    pgbackrest --stanza="${PGBACKREST_STANZA:-core}" --type="${PGBACKREST_TYPE:-diff}" backup
    db_note="pgbackrest ${PGBACKREST_TYPE:-diff} $(pgbackrest --stanza="${PGBACKREST_STANZA:-core}" --output=json info | "$PHP" -r '$i = json_decode(stream_get_contents(STDIN), true); echo end($i[0]["backup"])["label"] ?? "?";')"
    ;;
esac
case "$DB_TOOL" in
  pg_dump|both)
    "${PG_DUMP:-pg_dump}" --format=custom --no-owner --no-privileges "${PGDATABASE:?PGDATABASE}" | "${GPG[@]}" --encrypt --recipient "$GPG_RECIPIENT" --output "$DIR/db.dump.gpg"
    db_note="${db_note:+$db_note + }pg_dump custom"
    ;;
  pgbackrest) ;;
  *) log "unknown BACKUP_DB_TOOL=$DB_TOOL"; false ;;
esac

STEP=files
file_count="$(find "$FILES_ROOT" -type f | wc -l | tr -d ' ')"
file_bytes="$(du -sb "$FILES_ROOT" | cut -f1)"
tar -C "$FILES_ROOT" -cf - . | "${GPG[@]}" --encrypt --recipient "$GPG_RECIPIENT" --output "$DIR/files.tar.gpg"

STEP=manifest
release="$(head -n1 "$CORE_APP/../RELEASE" 2>/dev/null || echo unknown)"
(cd "$DIR" && sha256sum -- * > SHA256SUMS)
"$PHP" -r 'echo json_encode(["id" => $argv[1], "started_at" => $argv[2], "finished_at" => gmdate("Y-m-d\TH:i:s\Z"), "database" => $argv[3],
  "files" => ["count" => (int) $argv[4], "bytes" => (int) $argv[5]], "release" => $argv[6], "encrypted_to" => $argv[7],
  "artifacts" => array_map(fn ($l) => ["sha256" => strtok($l, " "), "name" => trim(substr($l, 66), " *")], array_filter(file($argv[8], FILE_IGNORE_NEW_LINES)))],
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);' \
  "$ID" "$started" "$db_note" "$file_count" "$file_bytes" "$release" "$GPG_RECIPIENT" "$DIR/SHA256SUMS" > "$DIR/manifest.json"

STEP=offhost
offhost=false
if [ -n "${OFFHOST_DIR:-}" ]; then
  mkdir -p "$OFFHOST_DIR/$ID" && cp -p "$DIR"/* "$OFFHOST_DIR/$ID/"
  (cd "$OFFHOST_DIR/$ID" && sha256sum -c --quiet SHA256SUMS)
  offhost=true
elif [ -n "${OFFHOST_CMD:-}" ]; then
  eval "${OFFHOST_CMD//\{dir\}/$DIR}"
  offhost=true
elif [ "${ALLOW_NO_OFFHOST:-0}" != 1 ]; then
  log "no off-host destination (OFFHOST_DIR or OFFHOST_CMD)"; false
fi

STEP=retention
for repo in "$BACKUP_REPO" ${OFFHOST_DIR:+"$OFFHOST_DIR"}; do
  find "$repo" -mindepth 1 -maxdepth 1 -type d -name 'core-*' | sort | head -n "-$RETAIN_COUNT" | while read -r old; do
    log "retention: removing $(basename "$old") from $repo"; rm -rf "$old"
  done
done

STEP=status
status "last_success_at=$(date -u +%FT%TZ)" "last_backup_id=$ID" "type=$db_note + files (gpg)" "encrypted=true" \
  "retention_days=$RETENTION_DAYS" "retain_count=$RETAIN_COUNT" "offhost_copy=$offhost" "files_count=$file_count"
log "done: $ID ($db_note; $file_count files; off-host copy: $offhost)"
