# Legacy deprecation

Old paths that overlap with Core and what must happen before each is retired.
Nothing here is removed yet: the rule is that a legacy path goes only after
every consumer has moved and the evidence says it is unused.

| Legacy path | Replacement | State | Evidence needed before removal | Owner |
| --- | --- | --- | --- | --- |
| `institution_offices` and relationships | Organization units ([../institution-office-deprecation.md](../institution-office-deprecation.md)) | GET routes redirect; store creates a unit | `php artisan backbone:duplicate-masters` shows no reader and no new rows for a full quarter | Core data steward |
| `users.national_id` as an identity key | `employees.national_id` (encrypted, keyed hash) via the user's employee link | still populated for older accounts | `backbone:duplicate-masters` count of users relying on it is 0 | Security |
| `api_clients` table (from the foundation migration) | `external_applications` + Sanctum tokens | no code reads or writes it | row count 0 in production; then archive by a reviewed migration | Integration owner |
| Tokens carrying abilities an application no longer has (`provider:access`, `*`) | Exact scopes on registered applications | refused per request by `EnsureApiScope`; listed as `LEGACY_CREDENTIAL` | `api:access-review` shows no `LEGACY_CREDENTIAL` finding; old tokens revoked | Integration owner |
| Legacy card QR (card id only) | Signed public reference QR | accepted only while `ID_CARD_ACCEPT_LEGACY_QR` is on | reprint finished; flag off; verification logs show no legacy scans | ID-card office |
| Main-sidebar entries duplicated in the Core workspace | Core workspace pages | DUAL AVAILABLE ([core-workspace.md](core-workspace.md)) | usage shows people use the Core entries; training done | Product owner |
| Direct Golden Path model reads in service modules | Core contracts / `/api/v1` | frozen by ratchet (210 files) | per-module count reaches 0 ([module-integration-matrix.md](module-integration-matrix.md)) | Each module owner |

## Process

1. Announce the deprecation (release notes, in-app notice where people are affected) with a date.
2. Measure: logs, audit rows, `backbone:duplicate-masters`, `api:access-review`.
3. Switch reads off behind a flag or a redirect first; keep the data.
4. Remove code in a later release only after a full period with no use.
5. Never delete history: tables are archived or left read-only; no migration drops them without its own reviewed plan (`tests/Unit/MigrationSafetyTest.php`).

The register of everything currently deprecated is kept in [../governance/deprecation-register.md](../governance/deprecation-register.md).
