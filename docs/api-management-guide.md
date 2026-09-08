# API Management Guide

Administrator guide for **System Settings → API Management**. For the
integrator-facing contract see `id-card-integration-guide.md`.

## 1. Concepts

| Term | Meaning |
|---|---|
| External application | A registered system permitted to call the API |
| API token | A Sanctum bearer token issued to an application |
| Scope | A named ability limiting what a token may do |
| Rate limit | Requests per minute allowed for an application |
| IP allowlist | Optional list of source IPs; empty means unrestricted |
| Endpoint catalog | The integration surface, discovered from the route table |
| Endpoint assignment | The specific endpoints an application may call |

## 2. Permissions

| Permission | Grants |
|---|---|
| `api_management.view` | View applications, tokens and scopes |
| `api_management.create` | Register a new application |
| `api_management.update` | Edit an application |
| `api_management.delete` | Delete an application and revoke its tokens |
| `api_management.tokens.create` | Generate a token |
| `api_management.tokens.revoke` | Revoke or rotate a token |
| `api_management.logs.view` | View API request logs |
| `api_management.docs.view` | View this documentation page |
| `api_management.endpoints.view` | View the endpoint catalog |
| `api_management.endpoints.sync` | Re-read the routes into the catalog |
| `api_management.endpoints.update` | Edit an endpoint's description and status |

Super Admin and System Admin receive all permissions from the role seeder.
Organizational Admin holds none of them and cannot reach the module.

## 3. Registering an application

1. System Settings -> API Management -> **New Application**.
2. Provide name, unique code, owner institution and contact details.
3. Tick only the scopes the integration genuinely needs.
4. Under **Select API Endpoints**, tick the endpoints the integration may
   call. Ticking an endpoint automatically grants the scope it requires, so
   an application is never assigned an endpoint its token could not call.
5. Set a rate limit appropriate to expected traffic (default 60/min).
6. Optionally restrict to specific source IPs.

An application with **no** endpoint assignments is treated as unrestricted, so
integrations registered before endpoint assignment existed keep working. Once
any endpoint is assigned, the assignment list becomes authoritative and every
other endpoint is denied.

## 4. Tokens

Open the application and choose **Generate Token**. The plaintext value is
displayed **once** and is never recoverable — only a hash is stored. If it is
lost, revoke it and issue a new one.

A token inherits exactly the application's approved scopes. Changing scopes
does not retroactively alter existing tokens; revoke and reissue to apply.

**Rotation:** generate the new token, deploy it, then revoke the old one so
there is no gap in service.

## 5. Scopes

| Scope | Grants |
|---|---|
| `id_cards.verify` | Verify a card by QR token |
| `employees.basic_verify` | Minimal employee confirmation |
| `service_eligibility.check` | Check service eligibility |
| `service_transactions.create` | Record a service transaction |
| `reports.read_limited` | Read limited settlement data |
| `organizations.read` | Read organizations |
| `organization_units.read` | Read organization units |
| `positions.read` | Read positions |
| `employees.basic_read` | Read employees (safe fields only) |
| `employee_assignments.read` | Read employee assignments |
| `organization_structure.read` | Read the nested organization structure |

A request without the required scope returns `403` with
`error_code: missing_scope`.

Scope and endpoint assignment are checked **independently**. A token holding
`employees.basic_read` still receives `403 endpoint_not_allowed` if an
administrator has not assigned `/api/v1/employees` to its application.

### Organization → Employee data API

The last six scopes above cover organization structure through to employee
records. The full endpoint list, data contract, filters and error codes are
documented separately in
[`api-organization-employee-v1.md`](api-organization-employee-v1.md).

In the endpoint picker these appear under three groups:

- **Organization Data API** — organizations, units, positions
- **Employee Data API** — employees and their assignments
- **Organization Structure API** — the nested structure endpoint

## 6. Sample request

```http
GET /api/v1/id-cards/verify/{card_uuid} HTTP/1.1
Authorization: Bearer <token>
Accept: application/json
```

```json
{
  "valid": true,
  "status": "active",
  "card": { "card_number": "IDC-2026-000001", "expires_at": "2029-01-14" },
  "employee": { "employee_number": "EMP-2026-000001", "full_name": "...", "status": "active" }
}
```

## 7. Error codes

| HTTP | `error_code` | Meaning |
|---|---|---|
| `401` | — | Missing, invalid or revoked token |
| `403` | `missing_scope` | Token lacks the required scope |
| `403` | `ip_not_allowed` | Source IP not in the allowlist |
| `403` | `application_suspended` / `application_revoked` | Application disabled |
| `403` | `endpoint_not_allowed` | Endpoint not assigned to this application |
| `404` | `card_not_found` | Unknown card token |
| `429` | `rate_limit_exceeded` | Rate limit exceeded; honour `Retry-After` |

## 8. Endpoint catalog

**API Management → Endpoints** lists the integration surface: method, URI,
required scope, version, description and status.

The catalog is discovered from the Laravel route table, not maintained by hand,
so an endpoint added to `routes/api.php` appears here as soon as an
administrator presses **Sync**. Sync reconciles rather than replaces:

- a newly discovered route is created as `active` and documented,
- discovered fields (method, URI, action, middleware, scope) are overwritten —
  the route table owns them,
- curated fields (description, documented flag) are preserved,
- a route that no longer exists is marked `deprecated`, never deleted, so
  historical request logs keep a definition to point at.

Only `active` **and** documented endpoints are offered for assignment. A
deprecated or hidden endpoint cannot be attached to an application even by a
crafted request — the server re-validates every submitted id.

## 9. Logs

API Logs records, per request: application, endpoint, method, source IP,
status code, success flag and failure reason.

**Request and response bodies are never logged**, so the log table cannot
become a secondary source of employee data.

## 10. Security rules

- External systems never receive database access — the API is the only interface.
- Tokens are stored hashed; the plaintext appears once at creation.
- Deleting an application revokes its tokens in the same action.
- Token create/revoke and application create/update/delete are audit-logged.
- An application may call only the endpoints assigned to it; holding the scope
  is never sufficient on its own.
- Endpoint denials are logged and do not consume the application's rate limit,
  so a call an application may never make cannot exhaust its quota.
- The organization/employee API returns safe fields only — never national ID,
  phone, email, salary, address, documents or photographs.
- Serve over HTTPS only; never embed a token in a URL or client-side code.
