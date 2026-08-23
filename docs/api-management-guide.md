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

Super Admin and System Admin receive all permissions from the role seeder.
Organizational Admin holds none of them and cannot reach the module.

## 3. Registering an application

1. System Settings -> API Management -> **New Application**.
2. Provide name, unique code, owner institution and contact details.
3. Tick only the scopes the integration genuinely needs.
4. Set a rate limit appropriate to expected traffic (default 60/min).
5. Optionally restrict to specific source IPs.

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

A request without the required scope returns `403` with
`error_code: missing_scope`.

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
| `404` | `card_not_found` | Unknown card token |
| `429` | `rate_limit_exceeded` | Rate limit exceeded; honour `Retry-After` |

## 8. Logs

API Logs records, per request: application, endpoint, method, source IP,
status code, success flag and failure reason.

**Request and response bodies are never logged**, so the log table cannot
become a secondary source of employee data.

## 9. Security rules

- External systems never receive database access — the API is the only interface.
- Tokens are stored hashed; the plaintext appears once at creation.
- Deleting an application revokes its tokens in the same action.
- Token create/revoke and application create/update/delete are audit-logged.
- Serve over HTTPS only; never embed a token in a URL or client-side code.
