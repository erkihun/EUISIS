# NFC terminal integration

## Register an application and terminal

1. Apply migrations. In System Settings → API Management, sync the endpoint catalog.
2. Register an active External Application, assign the required NFC endpoints, then
   issue a token containing their scopes. Configure IP restrictions and rate limits.
3. As an authenticated Super/System Admin, POST JSON to `/nfc/terminals` with CSRF
   protection. The same endpoint updates an existing `terminal_code`:

```json
{
  "terminal_code": "CAFE-01",
  "name": "Cafeteria entrance",
  "terminal_type": "cafeteria",
  "status": "active",
  "external_application_id": "<registered application ID>",
  "provider_id": "<service provider ID>",
  "cafeteria_provider_id": "<cafeteria provider ID>",
  "service_type": "cafeteria",
  "organization_id": null,
  "certificate_reference": null
}
```

`provider_id` points to `service_providers`; `cafeteria_provider_id` selects the actual
cafeteria. The backend requires them to agree. `organization_id`, if set, restricts
the terminal to that exact employee organization; cafeteria subtree assignment rules
still run independently. Terminal code is public routing context, not a password.
Application tokens authenticate terminal bridges. Independent hardware terminal identity
must be verified by the adapter (for example a certificate-bound bridge).

`GET /nfc/terminals` and `GET /nfc/logs` return paginated JSON (50 rows/page).
Suspend a terminal by updating its status. Global registration/log endpoints are for
Super/System Admin accounts; provider bridges use only the integration API.

## Endpoints

All requests require HTTPS, `Authorization: Bearer <token>`, and JSON bodies.

| POST endpoint | Scope |
| --- | --- |
| `/api/v1/nfc/challenges` | `nfc.verify` |
| `/api/v1/nfc/verify` | `nfc.verify` |
| `/api/v1/nfc/service-eligibility` | `nfc.service_eligibility` |
| `/api/v1/nfc/service-transactions/verify-and-record` | `nfc.service_transactions.create` |

Missing assignment returns 403 `ENDPOINT_NOT_ALLOWED`; absent token or application
scope returns 403 `SCOPE_MISSING`. Legacy `provider:access` is insufficient.
Inactive applications, IP allowlists and per-application throttles are enforced.

### Reference-only status check

```json
{"credential":"nfc_<64 lowercase hex characters>","terminal_id":"CAFE-01"}
```

An active reference returns `valid: true`, `assurance: "reference"`, `eligible: null`.
This proves the online credential state, not possession of a genuine chip. Eligibility
enquiries add `service_type`; static references never authorize recorded service.

### Secure transaction

1. POST `/challenges` with credential, terminal, `purpose: "record"`, `service_type`,
   UUID `reference`, and the intended `usage_mode`/`meal_amount` when applicable.
2. The server returns `challenge`, `context_hash`, and `expires_at` (default 60 seconds).
3. The trusted reader adapter performs actual card cryptographic commands and obtains
   a proof binding the credential, terminal, nonce and context hash.
4. POST `/service-transactions/verify-and-record` with the same context fields,
   `challenge`, and `proof` object. Its schema is adapter-defined. It is not a UID.
5. Allow service only on a successful response with `eligible: true` and a
   `transaction_reference`. A boolean `valid` alone is never authorization.

The context hash is SHA-256 of the PHP-generated canonical JSON returned by the server's
parser: version, purpose, service type, reference, usage mode (default `single_day`),
meal amount (two-decimal string or null). Sign the returned hash; do not reconstruct it
with a different JSON encoder. Proof for another purpose/reference/amount is rejected.

Proofs are one-shot, including unsuccessful cryptographic attempts. On network failure,
reconcile the service transaction reference before dispensing; do not blindly retry
service delivery. Replaying a proof returns `REPLAY_DETECTED`. A fresh proof cannot
bypass cafeteria daily-use or provider transaction-reference duplicate checks.

Responses contain only `valid`, `eligible`, `reason_code`, `assurance`, and an optional
transaction reference. No employee summary scope is currently provided, so no employee
PII or internal primary keys are returned. Request bodies are capped at 64 KiB.

## Hardware adapter work

Implement `SecureCardAdapter` and configure its class in `config/nfc.php`. Implement
`NfcKeyManager` against your HSM/KMS/secret service. Bind reader identity and card
possession to server context, validate protocol framing and response lengths, use
constant-time proof comparison, and reject unsupported key versions. The interface
must not be implemented as “UID exists” or “terminal says verified”. Add known-answer
vectors and real card/reader acceptance tests before enabling secure credentials.

No production reader SDK or APDU protocol is bundled. Browser Web NFC / keyboard-wedge
readers alone do not provide this secure proof. Connect a trusted terminal bridge;
leave the existing QR camera flow available as a fallback.
