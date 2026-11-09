# Service eligibility

`GET /api/v1/employees/{employee_uuid}/service-eligibility?service_type=cafeteria`
— scope `service_eligibility.check`. Addis One is the only authority; a partner
never infers eligibility from a cached answer.

## Response

`200` when eligible, `403` when not — both with the same body:

```json
{
  "eligible": false,
  "reason_code": "id_card_revoked",
  "message": "…",
  "card_status": "revoked",
  "service": { "service_type": "cafeteria" },
  "effective_context": {
    "employee_uuid": "…", "employee_number": "…", "employee_status": "active",
    "card_uuid": "…", "card_status": "revoked", "organization_uuid": "…"
  },
  "employee": { "employee_uuid": "…", "employee_number": "…", "status": "active" },
  "checked_at": "2026-10-19T09:00:01+03:00",
  "request_id": "…"
}
```

Other outcomes: `404` outside the caller's organization bound or unknown
employee; `422` when `service_type` is missing.

## Partner rule (fail closed)

The scan-to-serve flow must deny when:

1. verification is not `valid: true`;
2. `employee_uuid` is missing from the verification response;
3. the eligibility call fails, times out, or `eligible` is not `true`.

The cafeteria reference consumer (`external-apps/cafeteria-system`,
`ServeEmployeeService`) implements exactly this; `employee_reference_missing` is
its reason code for (2).
