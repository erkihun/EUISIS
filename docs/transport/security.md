# Transport — Security

## Authorization
* Staff: `transport-*` permissions (`database/seeders/data/transport-permissions.php`), categories in `PermissionCatalog`; default roles Transport Admin, Transport Operator, Transport Dispatcher, Transport Finance Reviewer, Organization Transport Officer (`DefaultRoleMatrix`). Routes carry `can:` middleware and controllers/requests re-check.
* Provider users: `provider.transport.*` keys (`ProviderUserPermissionCatalog`) enforced by `provider.permission` middleware and request `authorize()`; owners/managers hold every key, operators only what is granted.
* Devices: bearer credential, hashed; `AuthenticateTransportDevice`; throttle `transport-device`.
* Situation Room: `situation_room.transport.view`.

## Isolation
* Provider isolation: every portal and device query is filtered by the authenticated provider; `TransportProviderRequest` verifies every referenced vehicle/driver/route/trip/stop belongs to it; cross-provider ids are validation errors or 403, never silently remapped.
* Organization scope: staff lists, reports, exports, manifests, voids and statements go through `OrganizationScopeService` (employees in scope; statements by `organization_id`).
* Admin and portal share one session store: requests decide the audience by the route, never by "whichever guard is signed in".

## Input and data
* Form Requests everywhere; mass assignment limited to validated keys (`safe()->except(...)`); a client cannot post `provider_id`, `status`, `transport_settlement_id` or reviewer fields.
* QR values are never stored (hash only); credentials are never logged; attachments go to the private `local` disk under `transport/…`.
* Idempotency and duplicates are database-backed: `transport_transactions_scan_nonce_unique`, partial unique `ttx_one_accepted_boarding_per_trip`, `tt_one_trip_per_schedule_date`; PostgreSQL advisory lock per employee inside the boarding transaction.
* Rate limits: scan 60/min per user, device limiter, lookup 30/min, exports 10–20/min.
* Audit: 24 `AuditEventType::Transport*` events with old/new values, reason and request context.

## Tests
`TransportSecurityTest` (IDOR across providers, mass assignment, lookup grants, scoped statements, void scope and settled-boarding guard), `TransportAdminTest` (admin writes beside a portal session), `TransportPortalTest`, `TransportDeviceApiTest` (credential states, vehicle binding, idempotency), `TransportIntegrityTest` (database-level duplicate refusal; PostgreSQL index and advisory lock on the release gate).
