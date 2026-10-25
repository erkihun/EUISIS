#!/usr/bin/env bash
# Builds the Core release artifact from one commit (docs/runbook/core-deployment.md).
#
#   core-platform/deploy/release/build-artifact.sh <git-ref> <output-dir>
#
# Output: core-<sha8>.tar.gz and its .sha256. The artifact holds backend/ with
# production Composer dependencies and the built screens (public/build), and
# RELEASE (commit, tag, build time, tool versions). Built from `git archive`,
# so nothing uncommitted or ignored gets in. Run it in CI or on a build host,
# never on a production server; the servers only unpack artifacts.
set -euo pipefail
REF="${1:?usage: build-artifact.sh <git-ref> <output-dir>}"
OUT="${2:?usage: build-artifact.sh <git-ref> <output-dir>}"
# An absolute path without a drive letter: GNU tar reads "C:/..." as a remote host.
OUT="$(mkdir -p "$OUT" && cd "$OUT" && pwd)"
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
SHA="$(git -C "$REPO" rev-parse --verify "${REF}^{commit}")"
ID="${SHA:0:8}"
TAG="$(git -C "$REPO" describe --tags --exact-match "$SHA" 2>/dev/null || echo untagged)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
log() { printf '[build %s] %s\n' "$(date -u +%T)" "$*"; }

log "commit $SHA ($TAG)"
git -C "$REPO" archive "$SHA" core-platform | tar -x -C "$WORK"
cd "$WORK/core-platform/backend"
log "composer install --no-dev"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --quiet
cd ../frontend
log "npm ci && npm run build"
npm ci --no-audit --no-fund --loglevel=error
npm run build --silent
cd ..
printf '%s\n' "$SHA" "tag=$TAG" "built_at=$(date -u +%FT%TZ)" "php=$(php -r 'echo PHP_VERSION;')" "node=$(node -v)" \
  "composer_lock_sha256=$(sha256sum backend/composer.lock | cut -d' ' -f1)" \
  "frontend_manifest_sha256=$(sha256sum backend/public/build/manifest.json | cut -d' ' -f1)" > RELEASE
# The frontend sources are not needed at runtime: the built screens are in backend/public/build.
tar -czf "$OUT/core-$ID.tar.gz" RELEASE backend
(cd "$OUT" && sha256sum "core-$ID.tar.gz" > "core-$ID.tar.gz.sha256")
log "artifact $OUT/core-$ID.tar.gz ($(du -h "$OUT/core-$ID.tar.gz" | cut -f1))"
