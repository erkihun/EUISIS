#!/usr/bin/env bash
# HTTP-level security and API hygiene probes. Read-mostly; the only writes are throttled POSTs
# that are expected to be rejected. Output: PASS/FAIL/INFO lines.
B="${BASE_URL:-http://127.0.0.1:8123}"
S=/tmp/claude-0/-home-user-EUISIS/04a59b1e-adf3-537d-b073-538f279f04b1/scratchpad/e2e/results
mkdir -p "$S"; CJ="$S/probe-cookies.txt"; rm -f "$CJ"
pass(){ printf '  PASS  %s\n' "$1"; }
fail(){ printf '  FAIL  %s — %s\n' "$1" "$2"; }
info(){ printf '  INFO  %s\n' "$1"; }
code(){ curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$@"; }

echo "== Security headers (/login)"
H=$(curl -s -D - -o /dev/null "$B/login")
echo "$H" | grep -iE '^(content-security-policy|x-frame-options|x-content-type-options|referrer-policy|permissions-policy|strict-transport-security|x-powered-by|server|cross-origin-opener-policy|cross-origin-resource-policy|cache-control|set-cookie):' | sed 's/^/        /' | cut -c1-220
echo "$H" | grep -qi '^x-powered-by' && fail "X-Powered-By hidden" "header present" || pass "X-Powered-By hidden"
echo "$H" | grep -qi '^permissions-policy' && pass "Permissions-Policy present" || fail "Permissions-Policy present" "missing"
CSP=$(echo "$H" | grep -i '^content-security-policy' | head -1)
echo "$CSP" | grep -q "unsafe-inline" && info "CSP allows 'unsafe-inline' (check script-src vs style-src): $(echo "$CSP" | grep -oE "(script|style)-src[^;]*" | tr '\n' ' ' | cut -c1-200)"
echo "$CSP" | grep -q "unsafe-eval" && fail "CSP has no unsafe-eval" "unsafe-eval present" || pass "CSP has no unsafe-eval"
echo "$H" | grep -i '^set-cookie' | grep -qvi 'httponly' && fail "All cookies HttpOnly" "$(echo "$H" | grep -i '^set-cookie' | grep -vi httponly | cut -c1-80)" || pass "All cookies HttpOnly (XSRF-TOKEN excluded by design?)"
echo "$H" | grep -i '^set-cookie' | grep -qi 'samesite' && pass "Cookies carry SameSite" || fail "Cookies carry SameSite" "missing"

echo "== Error hygiene"
for u in "/this-does-not-exist-$RANDOM" "/employees/00000000-0000-0000-0000-000000000000" "/api/does-not-exist" "/employees/not-a-uuid"; do
  c=$(code "$B$u"); body=$(curl -s "$B$u");
  if printf '%s' "$body" | grep -qiE 'Whoops|Stack trace|SQLSTATE|vendor/laravel'; then fail "No internals on $u ($c)" "debug output"; else pass "No internals on $u ($c)"; fi
done
c=$(code -X TRACE "$B/login"); [ "$c" = "405" ] || [ "$c" = "404" ] && pass "TRACE refused ($c)" || info "TRACE on /login returned $c"
c=$(code -X POST "$B/login" -H 'Content-Type: application/json' --data '{bad json'); info "POST /login with malformed JSON and no CSRF -> $c"
c=$(code -X POST "$B/login" --data 'email=a@b.c&password=x'); [ "$c" = "419" ] && pass "CSRF enforced on POST /login (419)" || fail "CSRF enforced on POST /login" "got $c"
c=$(code -X POST "$B/api/transport/boarding" -H 'Accept: application/json' --data '{}'); info "unauthenticated POST /api/transport/boarding -> $c"

echo "== Sensitive files not served"
for u in /.env /.env.example /composer.json /composer.lock /package.json /phpunit.xml.dist /.git/config /.git/HEAD /storage/logs/laravel.log /employeeId_db /sec-routes.json /artisan /README.md /vite.config.js /tsconfig.json /build/manifest.json /storage/app/private /.htaccess /web.config; do
  c=$(code "$B$u"); case "$c" in 200)
     case "$u" in /build/manifest.json|/robots.txt) info "$u -> 200 (expected public)";; *) fail "$u not served" "HTTP 200";; esac;; *) pass "$u -> $c";; esac
done
c=$(code "$B/robots.txt"); info "/robots.txt -> $c"; curl -s "$B/robots.txt" | head -5 | sed 's/^/        /'

echo "== Unauthenticated API surface (expect 401/403/404, JSON)"
grep -oE '"uri":"api/[^"]+"' /tmp/claude-0/-home-user-EUISIS/04a59b1e-adf3-537d-b073-538f279f04b1/scratchpad/routes.json | sed 's/"uri":"//; s/"$//' | sort -u | grep -v '{' | head -60 > "$S/api-uris.txt"
open=0; n=0
while read -r u; do n=$((n+1)); c=$(curl -s -o "$S/api-body.tmp" -w '%{http_code}' -H 'Accept: application/json' "$B/$u");
  if [ "$c" = "200" ]; then open=$((open+1)); info "OPEN without auth: GET /$u -> 200 $(head -c 120 "$S/api-body.tmp")"; fi
  head -c 1 "$S/api-body.tmp" | grep -q '[{[]' || { [ "$c" = "405" ] || info "non-JSON body on GET /$u ($c)"; }
done < "$S/api-uris.txt"
info "$n parameterless API GET uris probed; $open answered 200 without auth"

echo "== Throttling"
# forgot-password: throttle:5,1
rm -f "$CJ"; curl -s -c "$CJ" -b "$CJ" "$B/forgot-password" -o "$S/fp.html"; X=$(grep XSRF-TOKEN "$CJ" | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
last=""; for i in 1 2 3 4 5 6 7; do last=$(curl -s -c "$CJ" -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$B/forgot-password" -H "X-XSRF-TOKEN: $X" -H 'X-Inertia: true' -H 'Content-Type: application/json' --data '{"email":"qa.throttle@demo.local"}'); done
[ "$last" = "429" ] && pass "forgot-password throttled after 5/min ($last)" || info "7th forgot-password POST -> $last (expected 429 after 5)"
# health endpoints unthrottled?
for i in $(seq 1 65); do c=$(code "$B/health/live"); done; info "65 rapid /health/live -> last $c"
# session status heartbeat throttle:60,1 (unauthenticated -> 401?)
c=$(code "$B/session/status" -H 'Accept: application/json'); info "GET /session/status anonymous -> $c"

echo "== Session fixation / cookie rotation on login"
rm -f "$CJ"; curl -s -c "$CJ" -b "$CJ" "$B/login" -o "$S/login.html"
SID1=$(grep -E "addis_one[_a-z]*session|laravel_session|[a-z_]*session" "$CJ" | awk '{print $6"="$7}' | head -1 | cut -c1-60)
X=$(grep XSRF-TOKEN "$CJ" | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
c=$(curl -s -c "$CJ" -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$B/login" -H "X-XSRF-TOKEN: $X" -H 'X-Inertia: true' -H 'Content-Type: application/json' --data '{"email":"qa.sysadmin8@demo.local","password":"password"}')
SID2=$(grep -E "session" "$CJ" | awk '{print $6"="$7}' | head -1 | cut -c1-60)
[ "$c" = "302" ] && pass "login as qa.sysadmin8 -> 302" || fail "login as qa.sysadmin8" "got $c"
[ "$SID1" != "$SID2" ] && pass "session id rotated on login" || fail "session id rotated on login" "same cookie value"
c=$(code -b "$CJ" "$B/dashboard"); [ "$c" = "200" ] && pass "authenticated /dashboard 200" || fail "authenticated /dashboard" "$c"

echo "== Authenticated API without token ability / cross-guard"
c=$(code -b "$CJ" -H 'Accept: application/json' "$B/api/user"); info "GET /api/user with web session cookie -> $c (Sanctum SPA stateful?)"
c=$(code -b "$CJ" -H 'Accept: application/json' "$B/provider/portal/scan"); info "GET /provider/portal/scan with staff session -> $c (expect redirect to portal login, not 200)"

echo "== Open redirect / host header"
c=$(curl -s -o /dev/null -w '%{redirect_url}' "$B/login?redirect=https://evil.example"); info "login?redirect= -> redirect_url='$c'"
c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: evil.example' "$B/dashboard"); info "Host: evil.example on /dashboard -> $c"
c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'X-Forwarded-Host: evil.example' "$B/dashboard"); info "X-Forwarded-Host evil on /dashboard -> $c"

echo "== Logout + reuse of old session"
X=$(grep XSRF-TOKEN "$CJ" | awk '{print $7}' | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
cp "$CJ" "$S/old-cookies.txt"
c=$(curl -s -c "$CJ" -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$B/logout" -H "X-XSRF-TOKEN: $X" -H 'X-Inertia: true'); info "POST /logout -> $c"
c=$(code -b "$S/old-cookies.txt" "$B/dashboard"); [ "$c" = "302" ] && pass "old session cookie rejected after logout ($c)" || fail "old session cookie rejected after logout" "got $c"
echo "done"
