# Verification API

## Purpose

The verification and service-authorization APIs provide secure, minimal-response checks for QR-based employee ID cards and downstream service access.

## Authentication

- Sanctum bearer token for provider/API clients
- Current middleware requires `provider:access` or `*` token ability for non-admin provider routes
- Optional device binding checks remain a foundation table and future hardening step

## Endpoints

### `POST /api/v1/cards/verify`

Validates the QR token or card token and returns a minimal verification response.

#### Request

```json
{
  "token": "opaque-or-signed-token",
  "service_type": "transport",
  "device_identifier": "scanner-01"
}
```

#### Success Response

```json
{
  "data": {
    "allowed": true,
    "result_code": "verified",
    "card_status": "active",
    "employee_status": "active",
    "service_type": "transport",
    "quota_remaining": 12
  }
}
```

#### Denied Response

```json
{
  "data": {
    "allowed": false,
    "result_code": "card_inactive",
    "denial_reason": "card inactive",
    "card_status": "revoked",
    "employee_status": "active",
    "service_type": "transport"
  }
}
```

### `POST /api/v1/services/{serviceType}/authorize`

Checks whether a provider may authorize the employee/card for the requested service and returns a minimal safe decision.

### `POST /api/v1/services/{serviceType}/transactions`

Records an authorized, denied, pending-sync, reversed, or settled service transaction.

### `GET /api/v1/employees/{employee}/entitlements`

Scoped internal endpoint for HR/admin users only.

### `GET /api/v1/providers/{provider}/settlements/{period}`

Scoped settlement foundation response for authorized provider or settlement users.

### `POST /api/v1/offline-sync/transactions`

Deferred sync foundation for approved offline-capable provider devices or clients.

## Verification Checks

- token signature or hash
- card existence
- card status
- card expiry
- employee status
- active assignment
- provider identity
- provider service-type alignment
- provider user scope when the actor is a provider-role user
- device/API client foundation if configured later
- entitlement status
- entitlement effective dates
- entitlement quota exhaustion
- duplicate provider transaction reference guard on transaction recording
- rate limit
- audit logging

## Denial Result Codes

- `invalid_token`
- `card_not_found`
- `card_inactive`
- `card_expired`
- `employee_inactive`
- `no_active_assignment`
- `provider_scope_denied`
- `provider_inactive`
- `service_not_allowed`
- `entitlement_missing`
- `quota_exhausted`
- `duplicate_transaction`
- `rate_limited`
- `request_invalid`

## Provider Scope Rules

- A provider user may verify only for authorized provider contexts.
- A provider may authorize only allowed service types.
- Devices/clients may be bound to provider scope and rejected if mismatched.
- Internal HR/admin entitlements endpoint is not exposed to generic provider users.

## Audit Behavior

- Every verification attempt is audited.
- Every service authorization attempt is audited.
- Transaction recording is audited.
- Provider token-ability or scope denials are audited as `provider_api_denied`.
- Export and settlement access should be audited.
