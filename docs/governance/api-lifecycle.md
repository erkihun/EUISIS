# API lifecycle

Rules for `/api/v1` and every later version. Contract: [../integration/api-v1-contract.md](../integration/api-v1-contract.md) and [../integration/openapi-v1-partner.yaml](../integration/openapi-v1-partner.yaml) (version 1.4.0). Owner: Integration/API Owner ([roles-register.md](roles-register.md)).

## Versions

Every partner route carries its major version in the path (`/api/v1/...`).
A breaking change ships as `/api/v2/...`, running beside v1 for the
deprecation period below. Non-breaking additions stay in the current version.
The OpenAPI document's own version (1.4.0) counts documentation releases
within v1; it is not the API major version.

## Changes allowed within v1

- New endpoints, new optional query parameters, new response fields.
- New error codes for new situations.
- Tighter validation only where the old input was unsafe, announced in release notes.

Partners must ignore fields they do not know.

## Breaking changes

Each of these needs a new major version (or, for a security fix, an
announced exception approved by the Information Security Lead):

- Removing or renaming a field, endpoint, parameter or error code.
- Changing a field's type, format or meaning (semantic change).
- Making an optional input required.
- Authorization behaviour: a scope returning more or less data, a different organization bound, a changed scope name.
- Pagination contract: page size limits, cursor or page semantics, ordering guarantees.
- Error contract: status codes, the error body shape, or the meaning of a code.
- Idempotency or rate-limit semantics a client relies on.

Removing an API because internal code no longer calls it is not a reason:
external consumers may still depend on it (`api:consumers`, `api_request_logs`).

## Deprecation

Each deprecated endpoint gets a row in [deprecation-register.md](deprecation-register.md) with:
deprecation date, replacement endpoint, consumer list (from `api:consumers` and
90 days of `api_request_logs`), migration deadline, removal date, owner.

1. Mark it deprecated in the OpenAPI document (`deprecated: true`) and the register.
2. Tell every consumer on the list in writing.
3. Keep it working for at least six months after a replacement exists, unless it is a security risk.
4. Remove it only after the logs show no use for 30 days; update the OpenAPI document in the same change (`OpenApiContractTest` fails otherwise).

## Consumer registry

`php artisan api:consumers` is the registry used for impact analysis before
any API change: code, owner institution, contact, status, API version,
scopes, organization access and grants, provider binding, rate limit, last
use, token count and next token expiry, with findings (`UNREVIEWED`,
`UNSCOPED`, `OVERPRIVILEGED`, `LEGACY_CREDENTIAL`, `PROVIDER_UNBOUND` from
`api:access-review`, plus `NEVER_USED`, `STALE`, `NO_TOKEN_EXPIRY`). It reads
the registered applications in Integration Center (`external_applications`);
there is no second list to keep in step. The environment it reports is the
one it runs in, so run it in each environment.

## OpenAPI is the contract

The OpenAPI document is in source control and changes in the same pull
request as the code. It states authentication (bearer token, scopes),
request and response schemas, errors, pagination, idempotency keys for
writes, rate limits and examples. `OpenApiContractTest` fails when a partner
route is undocumented or a documented route does not exist. Response-field
compatibility is reviewed by the Integration/API Owner (CODEOWNERS); a
schema-level diff check is planned ([technical-debt.md](technical-debt.md) TD-12).

## Rate limits

Each application has its own per-minute limit (`rate_limit_per_minute`).
Raise a limit only with evidence (traffic in `api_request_logs`, purpose,
capacity) and record why; review limits in the quarterly architecture review.
No application gets an unlimited or very high limit by default.

## Every API change

- [ ] OpenAPI document updated in the same pull request.
- [ ] Breaking or not, decided against the list above; breaking → new version.
- [ ] Consumers affected listed from `api:consumers`.
- [ ] Scope and organization bounds decided; data minimised (no national ID, phone, address or photo path unless the scope is for it).
- [ ] Feature test for allowed, refused (scope, organization) and validation cases.
- [ ] Rate limit considered.
- [ ] Release note for partners ([../releases/](../releases/README.md)).

## State (25 October 2026)

Every partner route under `/api/v1` is in the OpenAPI document (1.4.0);
`OpenApiContractTest` keeps it that way with an empty backlog. No endpoint is
deprecated and no v2 exists.
