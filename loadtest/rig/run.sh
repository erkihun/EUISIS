#!/usr/bin/env bash
# One measured partner-API run: k6 plus the database sampler side by side.
#
#   DB_DATABASE=aaemployeedb_scale3m DATA=<api-data.json> OUT=<dir> \
#     loadtest/rig/run.sh <label> <profile> <mix> <seconds-to-sample> [base-port] [nodes] [extra k6 -e args...]
#
# Writes <OUT>/<label>.json (k6 summary), <label>.samples.csv and <label>.log,
# then prints the summary row and the sampler line.
set -euo pipefail
export MSYS_NO_PATHCONV=1
ROOT="$(cd "$(dirname "$0")/../.." && (pwd -W 2>/dev/null || pwd))"
LABEL="$1"; PROFILE="$2"; MIX="$3"; SECONDS_TO_SAMPLE="$4"; BASE_PORT="${5:-8101}"; NODES="${6:-16}"
shift $(( $# >= 6 ? 6 : $# ))
: "${DB_DATABASE:?}" "${DATA:?}" "${OUT:?}"
mkdir -p "$OUT"
URLS="${BASE_URLS:-$(for i in $(seq 0 $((NODES - 1))); do printf 'http://127.0.0.1:%d,' $((BASE_PORT + i)); done | sed 's/,$//')}"
K6="${K6:-/c/Program Files/k6/k6}"

(cd "$ROOT" && php loadtest/rig/sampler.php "$SECONDS_TO_SAMPLE" 2 > "$OUT/$LABEL.samples.csv" 2> "$OUT/$LABEL.sampler.txt") &
SAMPLER=$!
"$K6" run -q -e BASE_URLS="$URLS" -e DATA="$DATA" -e PROFILE="$PROFILE" -e MIX="$MIX" "$@" \
  --summary-export="$OUT/$LABEL.json" "$ROOT/loadtest/k6/partner-api.js" > "$OUT/$LABEL.log" 2>&1 || true
wait "$SAMPLER" || true
node "$ROOT/loadtest/k6/summarize.mjs" "$LABEL=$OUT/$LABEL.json" | tail -n +3
cat "$OUT/$LABEL.sampler.txt"
