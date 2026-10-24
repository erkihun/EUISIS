# API lifecycle

Rules for `/api/v1` and every later version. Contract: [../integration/api-v1-contract.md](../integration/api-v1-contract.md) and [../integration/openapi-v1-partner.yaml](../integration/openapi-v1-partner.yaml).

## Changes allowed within v1

- New endpoints, new optional query parameters, new response fields.
- New error codes for new situations.
- Tighter validation only where the old input was unsafe, announced in release notes.

Partners must ignore fields they do not know.

## Breaking changes

Removing or renaming a field or endpoint, changing a type or meaning, making an
optional input required, or widening what a scope returns. These go into a new
version (`/api/v2`) that runs beside v1.

## Deprecation

1. Mark the endpoint deprecated in the OpenAPI document and in [deprecation-register.md](deprecation-register.md), with a date.
2. Tell every registered application that used it in the last 90 days (`api_request_logs`).
3. Keep it working for at least six months after a replacement exists, unless it is a security risk.
4. Remove it only after the logs show no use for 30 days; then update the OpenAPI document in the same change (`OpenApiContractTest` fails otherwise).

## Every API change

- [ ] OpenAPI document updated in the same pull request.
- [ ] Scope and organization bounds decided; data minimised (no national ID, phone, address or photo path unless the scope is for it).
- [ ] Feature test for allowed, refused (scope, organization) and validation cases.
- [ ] Rate limit considered.
- [ ] Release note for partners.

## Current gap

Eight partner routes exist without OpenAPI entries (organization units, positions, entitlements, service authorize); listed in `OpenApiContractTest` and [technical-debt.md](technical-debt.md).
