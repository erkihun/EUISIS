# ID card verification

`GET /api/v1/id-cards/verify/{card_uuid}` — scope `id_cards.verify`.
`card_uuid` is the stable public UUID printed in the QR code; it survives token
rotation and reprints of the same card record.

## Validity

`valid: true` only when **all** hold:

| Condition | Otherwise `status` |
| --- | --- |
| card `status = active` | the card status (`lost`, `suspended`, `printed`, …) |
| `revoked_at` is null and `qr_status = active` | `revoked` |
| `expires_at` not passed | `expired` |
| it is the employee's current card (`is_current`) | `replaced` |

A partner must deny service whenever `valid` is not `true`.

## Response (200)

```json
{
  "valid": true,
  "status": "active",
  "card_uuid": "…",
  "employee_uuid": "…",
  "organization_uuid": "…",
  "assignment_status": "active",
  "verified_at": "2026-10-19T09:00:00+03:00",
  "card": { "card_uuid": "…", "card_number": "…", "issued_at": "…", "expires_at": "…", "is_current": true },
  "employee": { "employee_uuid": "…", "employee_number": "…", "full_name": "…", "status": "active", "photo_url": "https://…/photo?…&signature=…", "photo_expires_in": 300 },
  "organization": { "organization_uuid": "…", "code": "…", "name_en": "…", "name_am": "…" },
  "position": { "code": "…", "title_en": "…", "title_am": "…" },
  "request_id": "…"
}
```

Never returned: national ID, phone, email, address, salary, documents.

Use `employee_uuid` for the eligibility call ([service-eligibility.md](service-eligibility.md)).
Do not store or invent any other identifier.

## Errors

| Status | `error_code` | When |
| --- | --- | --- |
| 401 | `unauthenticated` | no/invalid token |
| 403 | `missing_scope`, `endpoint_not_allowed`, `ip_not_allowed`, `application_suspended` | gate refusals |
| 404 | `card_not_found` | unknown UUID **or** outside the caller's organization bound |
| 429 | `rate_limit_exceeded` | per-application limit |
