# Partner scopes

Scopes say **what** a caller may do. Where it may do it is the organization
bound ([organization-scope.md](organization-scope.md)).

| Scope | Endpoints | Person also needs |
| --- | --- | --- |
| `id_cards.verify` | `GET /id-cards/verify/{card_uuid}`, `POST /cards/verify` | — |
| `service_eligibility.check` | `GET /employees/{employee_uuid}/service-eligibility`, `POST /services/{type}/authorize`, `GET /employees/{id}/entitlements` | — (entitlements: employee policy) |
| `service_transactions.create` | `POST /services/{type}/transactions`, `POST /offline-sync/transactions` | — |
| `reports.read_limited` | `GET /organizations` (directory), `GET /providers/{provider}/settlements/{period}` | `organizations.view` for the directory |
| `organizations.read` | `GET /organizations/directory`, `GET /organizations/{id}` | `organizations.view` |
| `organization_units.read` | units endpoints | `organization-units.view` |
| `positions.read` | positions endpoints | `positions.view` |
| `employees.basic_read` | employee lists and detail | `employees.view` |
| `employee_assignments.read` | `GET /employees/{id}/assignment` | `employees.view` |
| `organization_structure.read` | `GET /organizations/{id}/structure` | `organizations.view` |
| `employees.photo_read` | photo link in verification ([photo-access.md](photo-access.md)) | `employees.view` |
| `nfc.*` | `/nfc/*` (separate NFC gate) | — |

## Provider routes and binding

`/cards/verify`, `/services/*`, `/employees/{id}/entitlements`,
`/providers/{provider}/settlements/{period}`, `/offline-sync/transactions`:

- **Registered application** — must be bound to one service provider
  (`service_provider_id`). Every provider the request names (route, `provider_code`,
  each offline item) must be that provider → otherwise 403
  `provider_binding_missing` / `provider_binding_denied`. Without `provider_code`
  the bound provider is used.
- **Person** — token with `provider:access` (or the exact scope, or `*`) and a
  provider organization scope covering every named provider and service.

`provider:access` never satisfies a scope outside these routes (F1).
