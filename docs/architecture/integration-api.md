# Backbone integration API

How systems outside the application use the backbone. Inside the application,
modules use the contracts in [master-backbone.md](master-backbone.md) instead.
Each section links the guide that holds the details.

Partner systems read and verify; they never create or change an organization,
unit, position, employee, assignment or card, and cannot suspend, revoke or
activate a card ([ADR 0002](adr/0002-core-write-authority-assignment-ending-and-sign-in.md)).
A partner that learns of a problem reports it to the responsible office, which
acts in Core.

## Consistency model

- REST under `/api/v1` is the authoritative current state.
- Events (outbox → change feed and webhooks) are asynchronous synchronization.
  Delivery is at least once; consumers must be idempotent on `event_id`.
- After a missed webhook, reconcile with the change feed or the REST read.

## REST

| Need | Endpoint |
| --- | --- |
| Organizations, structure, units, positions | `GET /api/v1/organizations[...]`, `/organization-units[...]`, `/positions[...]` |
| Employee basic identity | `GET /api/v1/employees`, `/employees/{employee_uuid}` |
| Current assignment | `GET /api/v1/employees/{employee_uuid}/assignment` |
| Card verification | `GET /api/v1/id-cards/verify/{card_uuid}`, `POST /api/v1/cards/verify` (provider) |
| Service eligibility | `GET /api/v1/employees/{employee_uuid}/service-eligibility?service_type=` |
| Service transactions | `POST /api/v1/services/{serviceType}/authorize`, `/transactions` |

Contract and errors: [api-v1-contract.md](../integration/api-v1-contract.md),
OpenAPI: [openapi-v1-partner.yaml](../integration/openapi-v1-partner.yaml).
Errors are structured (`code`, `message`, `request_id`, field errors) and never
carry stack traces. Responses are explicit resources, never serialized models,
and omit national ID, phone, date of birth, address and case data.

Planned additions (Stage 4): an `/assignments` collection, a card status read
by card UUID and an explicit verification resource. Existing routes keep their
shapes; nothing is renamed.

## Principals and scopes

| Principal | Credential | Notes |
| --- | --- | --- |
| Human user | session or personal token | own permissions and organization scope |
| Application (machine) | Sanctum token on a registered `ExternalApplication` | exact scopes, organization binding, revocable, rotated |
| Provider user / terminal | provider credentials | bound to its provider |
| Public verification | none | minimal card status only |

Scopes are least-privilege (`organizations.read`, `employees.basic_read`,
`id_cards.verify`, `changes.read`, ...): [partner-scopes.md](../integration/partner-scopes.md).
Scope says **what**; the organization binding says **where**, and both are
enforced on the server: [organization-scope.md](../integration/organization-scope.md).
Authentication: [partner-authentication.md](../integration/partner-authentication.md).
OAuth2 client credentials is not implemented; the registered application model
covers client identity, scopes, organization scope, revocation, rotation and
audit.

## Events, change feed, webhooks

- Transactional outbox: [transactional-outbox.md](../integration/transactional-outbox.md)
- Event types and envelope: [event-catalog.md](../integration/event-catalog.md)
- Change feed `GET /api/v1/changes`: keyset cursor, stable event ids, filtered
  to the caller's organization bound and authorized event types. Archives and
  deactivations arrive as `*.status_changed` events; there is no separate
  tombstone record: [change-feed.md](../integration/change-feed.md)
- Signed webhooks (HMAC, timestamp, replay window, rotation, retry, dead
  letter, SSRF guard): [webhook-security.md](../integration/webhook-security.md)
- Replay and reconciliation: [replay-and-reconciliation.md](../integration/replay-and-reconciliation.md)

## Idempotency

Retried commands send `Idempotency-Key`; the key is claimed atomically in the
database per principal and route, and a replay returns the stored result:
[idempotency.md](../integration/idempotency.md).

## Audit

Every machine call is logged with application, scope, organization bound,
endpoint, request id, status and latency; secrets are never logged. Backbone
writes go through `WriteAuditLogAction`, which redacts credentials and
identifiers.
