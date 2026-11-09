# API v1 partner contract

Machine-readable: [openapi-v1-partner.yaml](openapi-v1-partner.yaml).

## Conventions

- Base path `/api/v1`. JSON only. `Accept: application/json`.
- Identifiers are stable UUIDs (`employee_uuid`, `card_uuid`, `organization_uuid`,
  and `id` in directory payloads). Never database integers. Employee number and
  card number are display references, not keys.
- Dates `YYYY-MM-DD`; timestamps ISO 8601 with offset.
- Lists are paginated (`per_page` default 25, max 100; `page`) with `data` and `meta` (`current_page`, `per_page`, `total`, `last_page`).
- `X-Request-ID` is accepted and echoed.

## Errors

```json
{ "message": "Forbidden.", "error_code": "missing_scope", "request_id": "…" }
```

| Status | Meaning |
| --- | --- |
| 400 | malformed header (e.g. idempotency key) |
| 401 | not authenticated |
| 403 | authenticated but not allowed (scope, endpoint, IP, status, provider binding, missing permission) |
| 404 | not found **or** outside the caller's organization bound |
| 409 | idempotency conflict |
| 422 | validation (fields in `errors`) |
| 429 | rate limit (`Retry-After`) |
| 5xx | unexpected; quote `request_id` / `error_id` when reporting |

Normal business outcomes (ineligible, invalid card, duplicate transaction) are
never 500s.

## Compatibility notes for existing partners (October 2026)

- Applications registered before this change are `unreviewed`: they authenticate
  but receive no organization-bound data until an administrator sets their access.
- `provider:access` personal tokens no longer read directory or verification
  endpoints.
- `photo_url` is a short-lived signed link.
- Verification `valid` is stricter (QR and current-card checks).
- Provider routes require machine clients to be bound to a provider.
- Response fields were only **added**; none were removed or renamed.
