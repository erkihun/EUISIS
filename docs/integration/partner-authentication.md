# Partner authentication

Audience: engineers integrating a partner system (cafeteria, transport, HR
satellite) with the Addis One API v1.

## Principals

| Principal | How it authenticates | What governs it |
| --- | --- | --- |
| Registered application (`ExternalApplication`) | `Authorization: Bearer <token>` issued in System Settings → API Management | Status, IP allowlist, assigned endpoints, per-application rate limit, scopes, organization bound, provider binding |
| Person (`User`) | Personal token or browser session | Token abilities, the person's own permissions and organization scope — never more than the screens show |

Machine clients should always be registered applications. Personal tokens with
`provider:access` exist for the provider portal and are accepted only on the
provider service routes.

## Request pipeline (registered application)

1. `auth:sanctum` — token valid → 401 otherwise.
2. `throttle:api` — global limiter.
3. `api.external` — application `active`, source IP allowed, endpoint assigned, per-application limit (429 + `Retry-After`). Every call is logged to `api_request_logs` (route, status, failure reason, request id; never bodies, headers or tokens).
4. `provider.scope` (provider routes only) — provider binding, see [partner-scopes.md](partner-scopes.md).
5. `api.scope:<scope>` — the token has the scope **and** the application still holds it.
6. Controller — organization bound ([organization-scope.md](organization-scope.md)).

## Token handling

- A token is shown once at creation; only its hash is stored.
- Removing a scope from an application takes effect on the next request even for tokens issued earlier.
- Rotate tokens flagged `LEGACY_CREDENTIAL` by `php artisan api:access-review`.
- Send `X-Request-ID` (letters, digits and hyphens, ≤ 64 chars) to correlate; the API echoes it, or generates one, in the response header and error bodies.

OAuth2 client credentials are **not implemented**.
