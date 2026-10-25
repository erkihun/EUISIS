# Core integration API (v1)

For the systems that consume the Core: Recruitment, EPMS, Cafeteria, Transport,
Consumer Association, Payroll, Attendance, Leave and later services. The Core is
the only writer of organizations, units, positions, employee identity,
placements and ID cards. A consuming system reads what its scopes allow, keeps
Core UUIDs as references, may cache projections, checks cards, and listens to
events. It never writes Core data and never reads the Core database directly
(ADR 0002 and ADR 0007).

## Authentication

A city-wide Core administrator registers each system in **Integration Center ›
API clients** (`POST /api/v1/api-clients`). The answer shows the client's
secret **once**; store it in the consuming system's secret store, never in a
browser or mobile app.

Send it as a bearer token on every call:

```
Authorization: Bearer <secret>
Accept: application/json
X-Request-ID: <your correlation id, optional, [A-Za-z0-9-]{1,64}>
```

The answer carries `X-Request-ID` (yours, or a new one); quote it when reporting
a problem. A client is refused when it is revoked, switched off, expired, or
calls from an address outside its allow list (IP addresses or CIDR ranges).
Rotation (`POST /api/v1/api-clients/{uuid}/rotate`) issues a new secret and ends
the old one at once.

## Scopes (least privilege)

| Scope | Opens |
|---|---|
| `organizations:read` | organizations and their parent in the hierarchy in force |
| `positions:read` | positions and whether they are held |
| `employees:basic-read` | employee number, full name, status, current placement |
| `employees:read` | also first/middle/last name and gender (needs `employees:basic-read` for the routes) |
| `employee-status:read` | whether a person may be served now |
| `assignments:read` | current and past placements |
| `cards:verify` | card validity check |

A client also has an **owner organization**: it reads only that organization
and everything under it in the hierarchy in force (none = the whole city).
A record outside it answers 404, never 403, so its existence does not leak.

No route returns national IDs, dates of birth, phone numbers, e-mail addresses,
addresses, photos, signatures, documents or credential secrets.

## Rate limits

Each client has its own limit per minute (default 120, set per client). Over
the limit the answer is `429` with `code: RATE_LIMITED` and `Retry-After`.

## Endpoints

Base: `/api/v1/integration`. Lists are paginated (`page`, `per_page` ≤ 100,
default 50) and return Laravel pages: `data`, `links`, `meta`.

| Method and path | Scope | Filters |
|---|---|---|
| `GET /organizations` | `organizations:read` | `status`, `updated_since` |
| `GET /organizations/{uuid}` | `organizations:read` | |
| `GET /positions` | `positions:read` | `organization_id`, `status` (`active`/other), `updated_since` |
| `GET /positions/{uuid}` | `positions:read` | |
| `GET /employees` | `employees:basic-read` | `status`, `organization_id`, `updated_since` |
| `GET /employees/{uuid}` | `employees:basic-read` | |
| `GET /employees/{uuid}/assignment` | `assignments:read` | |
| `GET /employees/{uuid}/assignment-history` | `assignments:read` | |
| `GET /employees/{uuid}/status` | `employee-status:read` | |
| `POST /cards/verify` | `cards:verify` | body `{"token": "<the token from the QR>"}` or `{"card_uuid": "<public card UUID>"}` |

The machine-readable description of these routes is
[`openapi/core-integration-v1.yaml`](openapi/core-integration-v1.yaml) (OpenAPI 3.1); a test keeps it in
step with the routes, events and card codes.

Dates are `YYYY-MM-DD`; timestamps are ISO 8601 with offset. Display calendars
(Ethiopian or Gregorian) are the consumer's concern; the API is always Gregorian.

### Card verification

```json
{ "valid": false, "code": "CARD_SUSPENDED", "status": "suspended",
  "expires_at": "2028-10-25T09:00:00+03:00", "holder": null }
```

`holder` (`employee_id`, `employee_number`, `full_name`, `organization_id`) is
given only to a client that also has `employees:basic-read`. Denial codes, in
the order they are checked:

A QR printed since P3 (ADR 0010) carries the check address and a credential
token — send it as `token`. A credential is checked first:

| Code | Meaning |
|---|---|
| `CREDENTIAL_UNKNOWN` (404) | no credential has this token |
| `CREDENTIAL_REVOKED` | revoked, with its card or by hand |
| `CREDENTIAL_SUPERSEDED` | a newer credential replaced it (the card must be reprinted) |
| `CREDENTIAL_EXPIRED` | past the credential's own expiry |
| `KEY_RETIRED` | issued under a key whose grace period ended |

Then the card:

| Code | Meaning |
|---|---|
| `CARD_NOT_FOUND` (404) | no card with this reference in the client's organizations |
| `EMPLOYEE_INACTIVE` | the holder is suspended or no longer employed |
| `CARD_REVOKED` | revoked for good |
| `CARD_REPLACED` | a newer card replaced it |
| `CARD_EXPIRED` | past its expiry date |
| `CARD_SUSPENDED` | blocked, may be reinstated |
| `CARD_INACTIVE` | not yet active (waiting to print, printed, issued) or reported lost/damaged |
| `CREDENTIAL_INVALID` | its QR credential is not active |
| `ASSIGNMENT_INACTIVE` | the holder has no placement in force |

A service that requires an active card serves the person only when `valid` is
`true`.

## Errors

Every integration error has the same shape and never contains internals:

```json
{ "message": "This client may not make this call.", "code": "SCOPE_DENIED", "errors": {} }
```

| HTTP | `code` |
|---|---|
| 401 | `UNAUTHENTICATED` (missing, wrong, rotated, revoked or expired secret) |
| 403 | `SCOPE_DENIED`, `IP_NOT_ALLOWED`, `CLIENT_INACTIVE`, `CLIENT_REVOKED`, `CLIENT_EXPIRED`, `NOT_AN_API_CLIENT` |
| 404 | `NOT_FOUND`, `CARD_NOT_FOUND` |
| 422 | `VALIDATION_FAILED` (`errors` holds the field messages) |
| 429 | `RATE_LIMITED` |

Every call of an identified client is logged (route, status, duration, denial
code, request id; never the payload) and visible in **Integration Center › API
calls**.

## Events and webhooks

The Core publishes these events (`GET /api/v1/webhooks` lists them as
`event_types`):

`organization.created`, `organization.updated`, `organization.archived`,
`organization.deleted`, `hierarchy.published`, `unit.created`, `unit.updated`,
`position.created`, `position.updated`, `position.status.changed`,
`employee.created`, `employee.updated`, `employee.status.changed`,
`assignment.created`, `assignment.changed`, `assignment.ended`,
`card.approved`, `card.printed`, `card.issued`, `card.activated`,
`card.suspended`, `card.reinstated`, `card.revoked`, `card.lost`,
`card.damaged`, `card.replaced`, `card.expired`.

An event is written in the same database transaction as the change (outbox):
there is an event exactly when the change committed. Delivery happens after,
every minute (`core:deliver-webhooks`), so a failing receiver never undoes a
Core change.

Body (payload version 1):

```json
{
  "event_id": "01a1…",
  "event_type": "card.revoked",
  "occurred_at": "2026-10-25T14:02:11+03:00",
  "entity_type": "IdCard",
  "entity_uuid": "01a0…",
  "payload_version": 1,
  "data": { "entity_uuid": "01a0…", "organization_id": "01a0…", "status": "revoked", "previous": { "status": "active" } }
}
```

`data` carries identifiers, codes, statuses and dates only. For
`employee.updated` it lists `changed_fields`, never their values: read the
employee through the API with your scopes.

Headers:

```
X-Core-Event: card.revoked
X-Core-Event-Id: <event_id>
X-Core-Delivery: <delivery id>
X-Core-Timestamp: <unix seconds>
X-Core-Signature: v1=<hex HMAC-SHA256 of "<X-Core-Timestamp>.<raw body>" with the endpoint secret>
```

For a grace period after the endpoint's secret is rotated (Core settings ›
Integration, default 24 hours) the header carries two values, new then old:
`v1=<new>,v1=<old>`; accept either, then switch to the new secret. After
repeated failed attempts (default 20 in a row) the endpoint is suspended; its
events wait and are sent once an administrator resumes it.

A receiver must check the signature with a constant-time comparison, refuse
timestamps older than five minutes, and treat `event_id` as the idempotency key
(the same event can arrive more than once). Answer any 2xx quickly; anything
else, or no answer within 10 seconds, is retried after 1, 5, 15, 60, 180, 360
and 720 minutes, then marked failed. An administrator can retry a failed
delivery, switch an endpoint off, or rotate its secret (shown once).

## Versioning

Everything external is under `/api/v1`. Fields may be added to answers and
events without notice; consumers must ignore unknown fields. Removing or
changing a field, a code or an event is a new version (`/api/v2`,
`payload_version: 2`), published side by side with the old one.
