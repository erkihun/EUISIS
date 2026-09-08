# Organization → Employee Data API (v1)

Read-only access to EUISIS organizational structure for approved external
systems: organizations, their units, the positions established in those units,
and the employees assigned to them.

External systems reach this data **only through this API**. Direct database
access is not offered under any circumstance.

---

## 1. Authentication

Every request carries a bearer token issued to a registered external
application under **System Settings → API Management**.

```http
GET /api/v1/organizations/directory HTTP/1.1
Host: euisis.example.gov.et
Authorization: Bearer <YOUR_API_TOKEN>
Accept: application/json
```

A token is displayed once, at generation, and only its hash is stored. If a
token is lost it must be revoked and reissued — it cannot be read back.

Five independent checks run on every call, in this order:

| # | Check | Failure |
|---|-------|---------|
| 1 | Token authenticates to a registered application | `401` |
| 2 | Application status is `active` | `403 application_suspended` / `application_revoked` |
| 3 | Source IP satisfies the allowlist, when one is configured | `403 ip_not_allowed` |
| 4 | Endpoint is assigned to the application | `403 endpoint_not_allowed` |
| 5 | Token carries the endpoint's required scope | `403 missing_scope` |

Endpoint assignment is checked **before** the rate limiter, so a call an
application may never make cannot consume its quota. Every request — allowed or
denied — is written to the API request log.

---

## 2. Scopes

| Scope | Grants |
|-------|--------|
| `organizations.read` | Read organizations |
| `organization_units.read` | Read organization units |
| `positions.read` | Read positions |
| `employees.basic_read` | Read employees (safe fields only) |
| `employee_assignments.read` | Read employee assignments |
| `organization_structure.read` | Read the nested organization structure |

Scopes are granted per application. Selecting an endpoint in API Management
automatically grants the scope that endpoint requires — an application cannot be
assigned an endpoint its token could never call.

**Holding a scope is not sufficient.** The endpoint must also be assigned to the
application. The two checks are independent by design: a token scoped for
`employees.basic_read` still receives `403 endpoint_not_allowed` on
`/api/v1/employees` if an administrator has not assigned that endpoint.

---

## 3. Endpoints

All endpoints are `GET`, versioned under `/api/v1`, and paginated where they
return a list.

### Organizations

| Endpoint | Scope |
|----------|-------|
| `/api/v1/organizations/directory` | `organizations.read` |
| `/api/v1/organizations/{organization}` | `organizations.read` |
| `/api/v1/organizations/{organization}/units` | `organization_units.read` |
| `/api/v1/organizations/{organization}/positions` | `positions.read` |
| `/api/v1/organizations/{organization}/employees` | `employees.basic_read` |
| `/api/v1/organizations/{organization}/structure` | `organization_structure.read` |

> **Note on `/api/v1/organizations`** — that path is a pre-existing, narrower
> directory endpoint (scope `reports.read_limited`) kept unchanged for the
> integrations already using it. The filterable, paginated listing described
> here lives at `/api/v1/organizations/directory`.

### Organization Units

| Endpoint | Scope |
|----------|-------|
| `/api/v1/organization-units` | `organization_units.read` |
| `/api/v1/organization-units/{unit}` | `organization_units.read` |
| `/api/v1/organization-units/{unit}/positions` | `positions.read` |
| `/api/v1/organization-units/{unit}/employees` | `employees.basic_read` |

### Positions

| Endpoint | Scope |
|----------|-------|
| `/api/v1/positions` | `positions.read` |
| `/api/v1/positions/{position}` | `positions.read` |

### Employees

| Endpoint | Scope |
|----------|-------|
| `/api/v1/employees` | `employees.basic_read` |
| `/api/v1/employees/{employee}` | `employees.basic_read` |
| `/api/v1/employees/{employee}/assignment` | `employee_assignments.read` |

---

## 4. Data contract

### Organization

```json
{
  "id": "9c1f...",
  "code": "AACG-001",
  "name_en": "Addis Ababa City Government",
  "name_am": "የአዲስ አበባ ከተማ አስተዳደር",
  "organization_type": { "code": "BUREAU", "name_en": "Bureau", "name_am": "ቢሮ" },
  "status": "active",
  "updated_at": "2026-09-03T10:15:00+03:00"
}
```

### Organization Unit

```json
{
  "id": "3a2e...",
  "code": "HR-DEPT",
  "name_en": "Human Resources Department",
  "name_am": "የሰው ሀብት ክፍል",
  "unit_type": "department",
  "parent_unit_id": null,
  "organization_id": "9c1f...",
  "status": "active",
  "updated_at": "2026-09-03T10:15:00+03:00"
}
```

### Position

```json
{
  "id": "7b4c...",
  "code": "HR-OFF-01",
  "standard_name": "Human Resource Officer",
  "standard_name_am": "የሰው ሀብት ኦፊሰር",
  "bpr_name": "HR Officer III",
  "job_grade": "IX",
  "position_status": "active",
  "organization_id": "9c1f...",
  "organization_unit_id": "3a2e...",
  "occupied": true,
  "vacant": false,
  "updated_at": "2026-09-03T10:15:00+03:00"
}
```

`standard_name`, `job_grade` and `position_status` are the published contract
names for what the schema stores as `title_en`, `grade_level` and `is_active`.

### Employee

```json
{
  "id": "5d8a...",
  "employee_number": "EMP-00123",
  "full_name": "Abebe Bekele Tadesse",
  "full_name_en": "Abebe Bekele Tadesse",
  "gender": "male",
  "employment_status": "active",
  "organization_id": "9c1f...",
  "organization_unit_id": "3a2e...",
  "position_id": "7b4c...",
  "active_assignment": {
    "id": "1f0b...",
    "employee_id": "5d8a...",
    "organization_id": "9c1f...",
    "organization_unit_id": "3a2e...",
    "position_id": "7b4c...",
    "assignment_status": "active",
    "is_current": true,
    "effective_from": "2025-01-01",
    "effective_to": null
  },
  "updated_at": "2026-09-03T10:15:00+03:00"
}
```

### Fields never returned

The following are **never** included in any response from this API, under any
scope:

- national ID
- phone number
- email address
- salary or any compensation data
- home or postal address
- documents and document references
- photographs and signature images
- date of birth
- private notes
- passwords, tokens, or any security data

This is enforced in one place — `App\Services\Api\OrganizationDataPresenter` —
which names every published field explicitly and never iterates a model's
attributes. Employee queries additionally select only the safe column list, so a
sensitive attribute is not even loaded into memory.

---

## 5. Structure endpoint

```http
GET /api/v1/organizations/{organization}/structure?depth=position
```

Returns the hierarchy in one nested document:

```
Organization
└── Units
    └── Positions
        └── Employee summary (if occupied)
```

### `depth`

| Value | Returns |
|-------|---------|
| `unit` | Organization and its units |
| `position` | …and the positions in each unit **(default)** |
| `employee` | …and a safe employee summary for each occupied position |

An unrecognised value falls back to `position`.

### Sample response (`depth=employee`)

```json
{
  "data": {
    "organization": { "id": "9c1f...", "code": "AACG-001", "name_en": "...", "status": "active" },
    "depth": "employee",
    "units": [
      {
        "id": "3a2e...",
        "code": "HR-DEPT",
        "name_en": "Human Resources Department",
        "unit_type": "department",
        "parent_unit_id": null,
        "organization_id": "9c1f...",
        "status": "active",
        "positions": [
          {
            "id": "7b4c...",
            "code": "HR-OFF-01",
            "standard_name": "Human Resource Officer",
            "job_grade": "IX",
            "position_status": "active",
            "occupied": true,
            "vacant": false,
            "employees": [
              {
                "id": "5d8a...",
                "employee_number": "EMP-00123",
                "full_name": "Abebe Bekele Tadesse",
                "employment_status": "active"
              }
            ]
          }
        ]
      }
    ]
  }
}
```

The employee summary at structure depth carries four fields only — id, employee
number, name and employment status. A position may hold more than one current
assignment, so `employees` is always a list.

---

## 6. Pagination

Every list endpoint is paginated.

```json
{
  "data": [ ... ],
  "meta": { "current_page": 1, "per_page": 25, "total": 142, "last_page": 6 }
}
```

| Parameter | Default | Maximum |
|-----------|---------|---------|
| `per_page` | 25 | 100 |
| `page` | 1 | — |

`per_page` above 100 is clamped to 100 rather than rejected: a bulk export is
not an available operation on this API.

---

## 7. Filters

| Filter | Applies to | Matches |
|--------|-----------|---------|
| `organization_code` | organizations, units, positions, employees | Organization code (case-insensitive) |
| `organization_unit_code` | units, positions, employees | Unit code (case-insensitive) |
| `position_code` | positions, employees | Position code (case-insensitive) |
| `employee_number` | employees | Employee number (case-insensitive) |
| `status` | all | `active` / `inactive` for positions; the record's status elsewhere |
| `updated_after` | all | ISO 8601 timestamp; returns records changed at or after it |
| `per_page` | all lists | Page size |

On employee endpoints, `organization_code`, `organization_unit_code` and
`position_code` resolve through the employee's **current assignment**, which is
what places them in the hierarchy.

### Polling for changes

```http
GET /api/v1/employees?updated_after=2026-09-01T00%3A00%3A00%2B03%3A00&per_page=100
```

Percent-encode the `+` in a timezone offset (`%2B`). An unencoded `+` arrives as
a space; the API repairs that specific case, but encoding is correct and
reliable. A genuinely unparseable value returns `422` rather than being ignored
— silently dropping the filter would return the full dataset to a caller that
asked for a delta.

---

## 8. Error codes

| Status | `error_code` | Meaning |
|--------|--------------|---------|
| `401` | — | Missing, malformed, or revoked token |
| `403` | `application_suspended` | Application is suspended |
| `403` | `application_revoked` | Application registration revoked |
| `403` | `ip_not_allowed` | Source IP outside the configured allowlist |
| `403` | `endpoint_not_allowed` | **ENDPOINT_NOT_ALLOWED** — endpoint not assigned to this application |
| `403` | `missing_scope` | **SCOPE_MISSING** — token lacks the required scope (the response names it in `required_scope`) |
| `404` | — | Organization, unit, position or employee not found |
| `404` | `assignment_not_found` | Employee has no current assignment |
| `422` | — | Invalid query parameter (e.g. malformed `updated_after`) |
| `429` | `rate_limit_exceeded` | Per-application rate limit exceeded; see `Retry-After` |

`error_code` values are lowercase snake_case on the wire, unchanged from the
existing integration API so current callers keep parsing them. The uppercase
names above are the specification's labels for the same conditions.

```json
{
  "message": "Forbidden.",
  "error_code": "missing_scope",
  "required_scope": "employees.basic_read"
}
```

---

## 9. Security rules

- An application may call **only** the endpoints assigned to it in API
  Management. Scope alone never grants access.
- Sensitive employee fields are never returned — see §4.
- Every request, allowed or denied, is written to the API request log with
  application, endpoint, method, IP, status and outcome. **Bodies are never
  logged**, so the log cannot become a secondary source of employee data.
- Rate limits are per application, per minute, and configurable per
  registration.
- IP allowlists are optional; an empty allowlist means unrestricted.
- Denied requests are logged with their reason, and scope denials additionally
  raise an audit-log entry.
- Deleting an application detaches its endpoint assignments and deletes its
  tokens, so access stops immediately.

## 10. Related documentation

- [`api-management-guide.md`](api-management-guide.md) — registering
  applications, issuing tokens, assigning endpoints
- [`id-card-integration-guide.md`](id-card-integration-guide.md) — ID card
  verification API
