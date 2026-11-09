# NFC security model

## Trust and assurance

An NFC UID is not an authentication credential. No raw UID, employee PII, entitlement,
salary or document is stored on the chip by this feature. The optional UID fingerprint
column is reserved; the current API neither collects nor authenticates by UID.

Reference credentials are random, nonsequential identifiers, separate from employee
numbers, card numbers, database IDs and QR UUIDs. Copying a reference is possible:
online reference verification is explicitly lower assurance. Transactions require
cryptographic card possession and fail closed with the default unavailable adapter.

Secure hardware can support mutual authentication and AES, but protocol details are
technology-specific. For example, [NXP's DESFire EV3 documentation](https://www.nxp.com/products/MF3DHx3)
describes mutual authentication; this project does not claim to implement that protocol.

## Authorization

Sanctum authenticates an active application. The NFC gate independently enforces live
application scopes and explicit active endpoint assignments. Existing external middleware
adds IP allowlists and rate limiting. NFC does not inherit legacy empty-assignment or
provider-scope bypasses. Every verification also checks an active registered terminal
bound to the calling application. Service and provider selection come from its registry.

Application authentication alone is not independent terminal hardware authentication.
An adapter must validate a terminal certificate/channel when that assurance is required.
Never put application bearer tokens in public browser code, chip memory or logs.

All credential, card, employee and terminal states are read online. Lost, revoked,
replaced, suspended and expired credentials fail; the underlying ID card must remain
current, active, unrevoked and unexpired, and its employee active. Shared eligibility
and service-specific rules run before accounting. No positive-result cache masks revocation.

## Anti-replay and concurrency

Server-generated 256-bit nonces are stored only as hashes with expiry, credential,
terminal and context hash. Proof binds purpose, reference and relevant transaction
options. A locked challenge is consumed once, before adapter verification. Invalid
proof attempts consume their correctly bound challenge too. Expired or missing
challenges fail. Context mismatch never authorizes a different transaction.

NFC state checks and accounting share a transaction. Lifecycle operations serialize
on card/credential rows. Cafeteria QR and NFC serialize on the same card/employee
rows, and consume the same subsidy/day records. Provider entitlement rows are locked
while checking and consuming quota. Validate locking behavior under load on the
production database; SQLite does not exercise row locks.

If the database transaction fails, both accounting and challenge consumption roll back.
No success should be dispensed without a committed response. The next retry repeats
all live state checks. Reconcile ambiguous network outcomes by transaction reference.

## Keys and rotation

Database columns contain only key version/reference and optional certificate reference.
There is no master secret implementation or raw-key storage. The key manager contract
delegates verification to an HSM/KMS; adapter code must not copy master secrets into
models, logs or source. Derive/diversify card keys using the selected vendor protocol.
Provision a new reference/version on rotation, retire the old credential, and physically
personalize the card before activation. Lost credentials cannot be reactivated.

## Audit and deployment limits

Dedicated NFC logs and existing audit logs record lifecycle, verification, service
decisions, replay and terminal administration. NFC audit metadata is an explicit
allowlist; it never receives raw request payloads, proofs, nonces or key material.
Failed adapter exceptions are reduced to a safe reason code, not logged verbatim.

Before a hardware rollout, finish chip personalization, SDK integration, key custody,
certificate verification, secure reader distribution, and known-answer/concurrency
acceptance testing. The test-only HMAC adapter models transcript binding and is never
registered in production. The reference path is usable for online status enquiries;
the repository alone cannot authenticate a physical secure chip.
