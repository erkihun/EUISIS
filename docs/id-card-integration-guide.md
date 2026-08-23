# ID Card Integration Guide (v1)

How approved external systems verify an employee ID card and check service
eligibility. Integrators need no database access — the API is the only
supported interface.

## 1. Authentication

Every request carries a Sanctum bearer token issued to a registered
**external application**:

```http
GET /api/v1/id-cards/verify/{token} HTTP/1.1
Host: <your-domain>
Authorization: Bearer <api-token>
Accept: application/json
```

Tokens are issued per application, not per person, and can be revoked without
affecting any user account. A revoked token fails immediately with `401`.

## 2. Scopes

A token only carries the scopes its application was approved for. Endpoints
assert the specific scope they need and return `403` with
`error_code: missing_scope` otherwise.

| Scope | Grants |
|---|---|
| `id_cards.verify` | Verify a card by its QR token |
| `employees.basic_verify` | Minimal employee confirmation |
| `service_eligibility.check` | Check whether an employee may receive a service |
| `service_transactions.create` | Record a service transaction |
| `reports.read_limited` | Read limited settlement/report data |

Tokens holding the legacy `provider:access` ability or Sanctum's `*` wildcard
are still accepted, so existing provider-portal integrations continue to work.

## 3. QR verification flow

1. The printed card carries a QR encoding **only** a verification URL:
   `https://<domain>/verify/card/{public_card_uuid}`
2. Scan it and extract `public_card_uuid`.
3. Call `GET /api/v1/id-cards/verify/{public_card_uuid}`.
4. Trust `valid` — do not infer validity from the presence of a card record.

The UUID is opaque and stable: it does not change when services, settings or
card layout change. It changes only when the card itself is replaced.

## 4. Endpoints

### `GET /api/v1/id-cards/verify/{token}`

Scope: `id_cards.verify`

```json
{
  "valid": true,
  "status": "active",
  "card": { "card_number": "IDC-2026-000001", "issued_at": "2026-01-15", "expires_at": "2029-01-14" },
  "employee": { "employee_number": "EMP-2026-000001", "full_name": "...", "status": "active" },
  "organization": { "code": "...", "name_en": "...", "name_am": "..." },
  "position": { "code": "...", "title_en": "...", "title_am": "..." }
}
```

Unknown token → `404` with `error_code: card_not_found`.

### `GET /api/v1/employees/{employee}/service-eligibility?service_type=<type>`

Scope: `service_eligibility.check`

```json
{
  "eligible": false,
  "reason_code": "id_card_expired",
  "message": "...",
  "card_status": "expired",
  "employee": { "employee_number": "EMP-2026-000001", "status": "active" }
}
```

Returns `200` when eligible, `403` when not. **Always branch on `eligible`,**
not on the HTTP status alone.

### Existing provider endpoints

`POST /api/v1/cards/verify` · `POST /api/v1/services/{serviceType}/authorize` ·
`POST /api/v1/services/{serviceType}/transactions` ·
`GET /api/v1/employees/{employee}/entitlements` ·
`GET /api/v1/providers/{provider}/settlements/{period}` ·
`POST /api/v1/offline-sync/transactions`

## 5. Reason codes

| `reason_code` | Meaning |
|---|---|
| `employee_inactive` | Employee is not active |
| `no_active_id_card` | No current card |
| `id_card_pending` | Card issued but not yet active |
| `id_card_expired` | Past `expires_at` |
| `id_card_revoked` | Revoked or QR reference revoked |
| `id_card_lost` | Reported lost |
| `id_card_replaced` | Superseded by a newer card |
| `id_card_suspended` | Temporarily suspended |
| `id_card_not_active` | Any other non-serviceable state |

**If a card is not active, no service may be granted.** The API enforces this;
integrators must not override it locally.

## 6. Idempotency

Write endpoints accept an `Idempotency-Key` header (≤128 chars):

```http
POST /api/v1/services/cafeteria/transactions
Idempotency-Key: 8f14e45f-ea8b-4f2b-9a1d-3c9d2b7a1f00
```

The first successful response is stored for 24 hours and replayed for repeats
of the same key, with `Idempotent-Replay: true`. Only 2xx responses are
replayed — failures stay retryable. Keys are namespaced per token.

Send a fresh key per logical transaction, and reuse it when retrying after a
timeout.

## 7. Rate limits

Requests are throttled by the `api` limiter. Registered applications carry a
`rate_limit_per_minute` value. Exceeding it returns `429`; honour
`Retry-After`.

## 8. Security rules

- The QR contains **no personal data** — only an opaque UUID in a URL.
- Responses never include national ID, phone, email, address, salary or
  document files.
- `card_number` and `employee_number` are distinct identifiers; do not treat
  one as the other.
- Verification requests are audit-logged.
- Applications may be restricted to an IP allowlist.
- Serve integrations over HTTPS only; never embed a token in a URL or client.

## 9. Versioning

The prefix (`/api/v1/`) is the version. Additive changes — new optional fields
— may ship within a version, so parse defensively and ignore unknown fields.
Removals or semantic changes ship as `/api/v2/`.

## 10. Errors

| HTTP | Meaning |
|---|---|
| `400` | Malformed request (e.g. invalid `Idempotency-Key`) |
| `401` | Missing, invalid or revoked token |
| `403` | Missing scope, or not eligible |
| `404` | Unknown card token |
| `429` | Rate limit exceeded |
