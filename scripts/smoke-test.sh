#!/usr/bin/env bash
# Post-deployment smoke test for EUISIS. Read-only: GET/HEAD requests only,
# no credentials, no data written. Run from any machine that can reach the site.
#
#   scripts/smoke-test.sh https://euisis.example.gov.et
#
# Exit code 0 when every check passes, 1 otherwise. Signed-in journeys (scan,
# settlement) are verified by the UAT script in docs/go-live-checklist.md.
set -u

BASE="${1:-}"
if [ -z "$BASE" ]; then
    echo "usage: $0 <base-url>" >&2
    exit 2
fi
BASE="${BASE%/}"
FAILED=0
RANDOM_UUID="$(cat /proc/sys/kernel/random/uuid 2>/dev/null || echo 3f2b8c1e-9a4d-4c6b-8e2f-1a2b3c4d5e6f)"

pass() { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s — %s\n' "$1" "$2"; FAILED=1; }

status_of() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@"; }
headers_of() { curl -s -D - -o /dev/null --max-time 20 "$1"; }

check_status() { # label expected url [curl args...]
    local label="$1" expected="$2" url="$3"; shift 3
    local got
    got="$(status_of "$@" "$url")"
    [ "$got" = "$expected" ] && pass "$label ($got)" || fail "$label" "expected $expected, got $got"
}

echo "EUISIS smoke test against $BASE"

check_status "Health check /up (database and cache answer)" 200 "$BASE/up"
check_status "Staff sign-in page" 200 "$BASE/login"
check_status "Provider portal sign-in page" 200 "$BASE/provider/portal/login"
check_status "Public ID checker, unknown card" 200 "$BASE/id-checker/$RANDOM_UUID"
check_status "Legacy card URL redirects permanently" 301 "$BASE/verify/card/$RANDOM_UUID"
check_status "Signed-in page redirects anonymous visitors" 302 "$BASE/dashboard"
check_status "Provider portal page redirects anonymous visitors" 302 "$BASE/provider/portal/scan"

# Debug mode must be off: an unknown URL returns a plain 404, not a stack trace.
BODY="$(curl -s --max-time 20 "$BASE/this-page-does-not-exist-$RANDOM_UUID")"
if printf '%s' "$BODY" | grep -qiE 'Whoops|Stack trace|vendor/laravel|APP_KEY|SQLSTATE'; then
    fail "Error pages hide internals" "debug output found on a 404 page"
else
    pass "Error pages hide internals"
fi

HEADERS="$(headers_of "$BASE/login")"
for header in 'X-Frame-Options' 'X-Content-Type-Options' 'Content-Security-Policy' 'Referrer-Policy'; do
    printf '%s' "$HEADERS" | grep -qi "^$header:" && pass "Header $header" || fail "Header $header" "missing on /login"
done
case "$BASE" in
    https://*)
        printf '%s' "$HEADERS" | grep -qi '^Strict-Transport-Security:' && pass "Header Strict-Transport-Security" \
            || fail "Header Strict-Transport-Security" "missing — check APP_TRUSTED_PROXIES if TLS ends at a proxy"
        printf '%s' "$HEADERS" | grep -i '^Set-Cookie:' | grep -qvi 'secure' \
            && fail "Cookies are Secure" "a cookie without the Secure flag — set SESSION_SECURE_COOKIE=true" \
            || pass "Cookies are Secure"
        HTTP_STATUS="$(status_of "http://${BASE#https://}/login")"
        case "$HTTP_STATUS" in
            301|308) pass "Plain HTTP redirects to HTTPS ($HTTP_STATUS)" ;;
            *) fail "Plain HTTP redirects to HTTPS" "got $HTTP_STATUS" ;;
        esac
        ;;
    *)
        fail "HTTPS" "base URL is not https"
        ;;
esac

if [ "$FAILED" -ne 0 ]; then
    echo "SMOKE TEST FAILED"
    exit 1
fi
echo "SMOKE TEST PASSED"
