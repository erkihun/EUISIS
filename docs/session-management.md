# Session Management

How EUISIS decides that a signed-in session has ended, and why. This applies to
every portal: administrators and employees (`web` guard, `/login`) and service
providers (`provider` guard, `/provider/portal/login`).

Code: `App\Services\Security\SessionActivityService` (the policy),
`App\Http\Middleware\EnforceSessionIdleTimeout` (enforcement),
`resources/js/Components/SessionTimeoutManager.tsx` (browser warning and
heartbeat). Tests: `tests/Feature/Security/SessionTimeoutTest.php`.

## 1. Policy

| Concept | Value | Source |
|---|---|---|
| **Idle timeout** | *Session Timeout Minutes* (5–1440, default 120) | System Settings → Security |
| Storage lifetime | idle timeout + `SESSION_STORAGE_GRACE_MINUTES` (default 30) | derived, never set separately |
| Absolute lifetime | none | — |
| MFA re-verification | `MFA_SESSION_LIFETIME_MINUTES` | MFA roles only; re-challenges, does not sign out |

A session ends when **no meaningful request** has reached the server for the
idle timeout. Activity is measured with the server clock and stored in the
session (`session_policy.last_activity_at`). Client timestamps are never read.

Example with 30 minutes: signed in at 12:00, saves at 12:20 and 12:40, still
signed in. Signed in at 12:00 with nothing after, the first request after 12:30
is sent to the login page.

### Why storage outlives the policy

Laravel deletes a session after `session.lifetime` minutes. If that were shorter
than the idle timeout, sessions would vanish before the policy said so. If it
were equal, the application could never tell the user *why*. Storage lives for
idle + grace minutes, so during the grace window an expired session still exists.
The middleware then ends it cleanly: it signs out, destroys the session and
token, writes an audit entry and shows the message.

`SESSION_LIFETIME` in `.env` is only the **fallback idle timeout** for when
System Settings cannot be read. Do not raise it to "fix" timeouts; change the
setting.

A changed setting applies on the next request. Saving clears the settings
cache, and every request copies the setting into config at boot. No restart or
`config:cache` is needed.

## 2. What counts as activity

The expiry check runs **before** a request is recorded as activity, so a
request on an expired session never revives it.

| Counts (resets the clock) | Does not count |
|---|---|
| Page navigation, form submit, save, approve, search | `GET /session/status` (passive by route) |
| `POST /session/activity`, the interaction heartbeat | `GET /notifications/feed`, the bell poll (passive by route) |
| Any request not listed on the right | Any request sent with `X-Activity: passive` (dashboard auto-refresh) |
| | `HEAD`/`OPTIONS`, link prefetch (`Purpose`, `X-Inertia-Prefetch`) |

The route list lives in `security.session.passive_routes` and is enforced on the
server whatever the client sends. The `X-Activity: passive` header can only make
a request count *less*, so it cannot be used to extend a session.

## 3. Browser: heartbeat and warning

`SessionTimeoutManager` is mounted beside the Inertia app and is active whenever
the page carries `session_policy` (signed-in users only).

- **Heartbeat.** When the user types, clicks, scrolls with the wheel or touches
  the screen, and no request has reached the server for `heartbeat_seconds`
  (¼ of the timeout, 30 s–5 min), it sends one `POST /session/activity`. An open
  tab nobody touches sends nothing.
- **Warning.** Near expiry (the last `warning_seconds`: 20% of the timeout,
  1–5 min) it first asks `GET /session/status`, which is passive, how much time
  is really left, so work in another tab counts. Only then does it show
  *"Your session will expire soon due to inactivity."* with **Stay Signed In**
  (sends a heartbeat) and **Sign Out**. Showing the warning extends nothing.
- **At zero** it asks the server again. An expired session answers
  `401 {reason: idle_timeout, redirect}`, and the page goes to that login URL.
- **Signing out** in one tab tells the others (`BroadcastChannel`). They check
  with the server and go to sign-in.

The browser is a convenience. The server decides every time.

## 4. When a session ends

| Reason | Where | Login page shows | Audit event |
|---|---|---|---|
| Idle timeout | `EnforceSessionIdleTimeout` | "Your session expired due to inactivity. Please sign in again." / "በተወሰነው ጊዜ ውስጥ እንቅስቃሴ ስላልነበረ የክፍለ ጊዜዎ ጊዜ አልፏል። እባክዎ እንደገና ይግቡ።" | `session_idle_timeout` |
| Explicit logout | logout controllers | nothing | `user_logged_out` |
| Password changed elsewhere | `AuthenticateSession` | "Your password was changed…" | `session_revoked` |
| Account disabled | `EnsureAdminAccess` | account-inactive error | — |

Every path goes through `SessionActivityService::end()`. It signs out every
guard in the session, invalidates the session (the old id and CSRF token are
destroyed), and records the reason. The message is a code (`sessionNotice`)
flashed once and localized by the login page, so it cannot reappear later.
Audit rows hold the reason only, never a session id, cookie, token, password or
MFA secret.

**Redirects.** Provider sessions and `/provider/portal*` and `/cafeteria/portal*`
URLs go to `/provider/portal/login`. Everyone else goes to `/login`;
`/employee/login` is an alias for it. After signing in again, page views return
to where the user was. Form submissions are never replayed.

## 5. 401, 403 and 419

| Status | Meaning | Handling |
|---|---|---|
| 401 | Not signed in | Page requests go to the right login page. JSON gets 401 (with `reason`/`redirect` when the session idled out). |
| 403 | Signed in, not allowed | Error page. The session is untouched; never shown as "expired". |
| 419 | CSRF token did not match | See below. It is **not** automatically "session expired". |

Laravel converts a CSRF failure into `HttpException(419)` before render
callbacks run. The decision therefore lives in the 419 branch of the
`HttpException` handler in `bootstrap/app.php`:

1. **The session is signed in, but the token is stale.** The user goes back to a
   fresh page with "The page had expired, so nothing was submitted. Please try
   again." Nothing is replayed.
2. **The session is gone, on a route that needs sign-in.** The user goes to the
   right login page with the recorded end reason (inactivity when none was
   recorded).
3. **A guest page** (such as a login form left open) goes back to that page
   with a fresh token.

JSON callers get a typed `419 {reason: page_expired}`. The raw
"419 Page Expired" page is never shown.

## 6. Tabs and devices

- **Tabs** of one browser share one session and one idle clock. Work in any tab
  keeps all of them signed in. Signing out in one signs out all.
- **Devices** each have their own session and their own clock. Activity on a
  laptop does not keep a phone signed in. There is no per-user "last activity".
- Simultaneous sessions are allowed (unchanged).

## 7. Remember me, MFA and password changes

- **Remember me is removed.** Without it, the session cookie already survives a
  browser restart within the idle window. A remember cookie could only ever
  revive a session the idle policy had ended. A request authenticated by a
  leftover remember cookie is treated as an idle-expired session: signed out,
  cookie cleared.
- **MFA.** Verification is stored in the session, so it ends with it: a new
  sign-in means a new MFA challenge. Active MFA users are also re-challenged
  every `MFA_SESSION_LIFETIME_MINUTES`.
- **Password change.** `AuthenticateSession` keeps a hash of the password in
  each session. After a change, every *other* session is signed out on its next
  request with "Your password was changed…". The session that made the change
  stays signed in. Forced first-login change and admin resets behave the same.
- **Forced password change.** `/session/*` sits behind `force.password`. A
  heartbeat returns 403 on that screen and never opens anything else.
- **Session fixation.** Login (web and provider) and the forced password change
  regenerate the session id. Logout and every end reason invalidate it.

## 8. Cookies and production

| Setting | Production | Notes |
|---|---|---|
| `SESSION_SECURE_COOKIE` | true | Defaults to true when `APP_URL` is `https://`, so local HTTP still works |
| `SESSION_HTTP_ONLY` | true | |
| `SESSION_SAME_SITE` | `lax` | See below |
| `SESSION_ENCRYPT` | true | |
| `SESSION_DRIVER` | `redis` or `database` | Shared storage; see below |

**Why `lax`, not `strict`.** With `strict`, opening EUISIS from an email link
(such as the daily-activity reminder) or another site sends no session cookie.
The response then *replaces* the signed-in cookie, which signs the user out of
every open tab. `lax` still withholds the cookie from cross-site POST, PUT and
DELETE, and every state-changing request also needs the CSRF token.

One Laravel session (one cookie, `SESSION_COOKIE`) is shared by all guards by
design. A browser can be signed in to the admin area and the provider portal at
once, and signing out of either ends that browser's session.

**Several app servers.** Use `SESSION_DRIVER=redis` (or `database`) so every
node sees the same session; sticky sessions are then unnecessary. File sessions
live on one node, so a request that reaches another node looks signed out.
Keep server clocks NTP-synchronized, because idle time is measured on whichever
node serves the request. `php artisan security:production-check --strict`
fails on file or array sessions, insecure cookies, or storage that does not
outlive the idle timeout.

**Reverse proxy.** Terminate TLS at the proxy and set `APP_URL` to `https://`.
Enable *Force HTTPS* in System Settings, or configure trusted proxies, so
generated URLs are https. The Secure flag comes from configuration and does not
depend on proxy headers.

## 9. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Signed out in every tab after clicking an email link | `SESSION_SAME_SITE=strict` in `.env`; use `lax` |
| Signed out at random behind a load balancer | File sessions on several nodes; use `redis` or `database` |
| Never signed out while a page is open | A custom poll not marked passive; add its route to `security.session.passive_routes` or send `X-Activity: passive` |
| Signed out while typing a long form | Heartbeat blocked (check the browser console for `/session/activity`), or a timeout below the time between keystrokes |
| "The page had expired…" after submitting | A 419 on a live session (stale token); the page reloads fresh and the action can be repeated |
| MFA prompt while active | `MFA_SESSION_LIFETIME_MINUTES` elapsed; by design |
| Timeout change has no effect | Settings cache not cleared (saving clears it), or `config:cache` holding an old fallback |
