# NFC employee ID architecture

## Readiness and boundaries

The initial repository had stable public QR references, application endpoint discovery,
Sanctum tokens, shared card eligibility, and cafeteria accounting. It had no NFC
credential registry, secure reader SDK, chip authentication adapter, or key manager.

This implementation adds a second credential channel. A printed QR continues to carry
the existing `/id-checker/{public_card_uuid}` URL. NFC contains only an independently
generated `nfc_` reference (256 random bits), with cryptographic authentication delegated
to a secure card adapter. NFC never modifies QR identity, card numbers, templates,
rendering, printing, exports, feedback codes, or employee records.

**Hardware deployment is not ready until a real adapter is integrated and validated.**
The shipped adapter fails closed. Reference credentials support online verification and
eligibility enquiries only; they cannot record service transactions.

## Database and lifecycle

Migrations add `nfc_credentials`, `service_terminals`, `nfc_challenges`, and
`nfc_verification_logs`, plus NFC permissions for existing Super/System Admin roles.
No existing records are backfilled or rewritten. Apply with `php artisan migrate`.
Credential references have a unique index; card/status lookups and log timestamps are
indexed. Credentials retain soft-deleted history. Foreign keys protect audit history.

Provisioning requires a current active card, which has already completed the existing
approval/issuance flow. Lifecycle operations lock the card and credential, and audit
each change in the same database transaction:

- Provision: create a pending credential. At most one pending/active/suspended credential
  per card can be provisioned through the service.
- Activate: pending or suspended to active; secure types require key references and a
  configured adapter. Actual chip personalization is an adapter/operator responsibility.
- Suspend: active to suspended; activation can resume it.
- Lost: pending/active/suspended to lost; cannot reactivate.
- Revoke: permanently disable pending/active/suspended/lost credentials.
- Replace: mark the old credential replaced, create a pending credential with a new
  random reference, and link the history. The QR stays unchanged.
- Expiry is enforced at every verification, even if the stored status is still active.

To change a key version, revoke and provision with the new version/reference, personalize
the card, then activate. Never reuse the old key reference for a compromised key.
Replacing the physical ID card also invalidates NFC through the underlying card's
status/current-card checks, even if its credential row still says active.

## Shared service rules

`CredentialVerificationService` dispatches QR and NFC identity checks.
`NfcVerificationService` checks live credential/card/employee/terminal state and proof.
Service evaluation continues through existing `EmployeeServiceEligibilityService`:

- Cafeteria: `CafeteriaQrScanService` accepts a server-resolved card, evaluates the same
  organization, working-day, holiday, leave, subsidy, weekly-use and duplicate rules,
  and records the same employee/service/ledger records as QR. Dry-run eligibility
  stops before writes. QR and NFC scans lock the same card/employee rows.
- Other service types: `VerifyCardForServiceAction::verifyResolvedCard` checks the
  existing provider and entitlement rules; `RecordServiceTransactionAction` records
  the existing transaction and quota consumption. The terminal fixes the provider.
- Specialized transport trip/vehicle workflows remain on their existing portal; this
  API supports generic transport entitlement transactions, not a replacement for those
  workflows. Reader-specific portal wiring is an integration task.

Existing browser camera scanners remain available. NFC readers use the versioned API
through an authenticated terminal bridge; no browser API token or fake UID-to-QR rewrite
is introduced.

## Administration and API Management

The ID card detail page has a permission-aware NFC panel. Organization administrators
need explicit NFC permissions and existing employee organization access. Provider users
cannot manage credentials. Terminal administration and global logs are limited to
Super/System Admins. JSON administration endpoints are documented in the integration guide.

API Management discovers all four new routes and required scopes from route middleware.
Use its existing endpoint sync and application editor to assign endpoints. NFC gates
require explicit assignments even for legacy applications with no assignments. Existing
QR application access behavior is preserved.

## Operations

Use transactional MySQL/PostgreSQL with row locking in production. SQLite tests validate
behavior but cannot prove production concurrency. Keep application, card, employee and
terminal state uncached on this path. Never reuse a verification result as service
authorization: each transaction requires fresh proof. Expired `nfc_challenges` are
deleted in bounded batches by `nfc:prune-challenges` (scheduled daily at 02:45,
`--hours` retention past expiry); old and missing nonces remain invalid either way,
so pruning is a storage concern and never a security control. Set log retention and
archival according to operational policy.
