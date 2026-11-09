# Deprecation register

Everything announced for retirement, and every temporary switch or
compatibility path, so none becomes permanent unnoticed. Process:
[../architecture/legacy-deprecation.md](../architecture/legacy-deprecation.md),
[api-lifecycle.md](api-lifecycle.md), [event-lifecycle.md](event-lifecycle.md).
Nothing listed here has been removed. Reviewed monthly.

## Legacy routes, tables, credentials and adapters

| Item | Kind | Owner | Replacement | Current use | Announced | Removal condition | Target | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Institution offices | data master + pages | Data Steward | Organization units | `backbone:duplicate-masters` | before 2026-10 ([../institution-office-deprecation.md](../institution-office-deprecation.md)) | no use for a quarter | not set | redirects in place |
| `users.national_id` | identity copy | Data Steward | employee record via the user's employee link | `backbone:duplicate-masters` | 2026-10 (ADR 0001) | count reaches 0 | not set | open |
| `api_clients` table | legacy partner store | Integration/API Owner | registered applications | unused in code; production row count to confirm | 2026-10-24 | production row count confirmed 0 | not set | open |
| Tokens with `provider:access` or `*` on registered applications | credentials | Integration/API Owner | exact scopes | `api:access-review` LEGACY_CREDENTIAL | 2026-10 | no LEGACY_CREDENTIAL finding | not set | refused per request |
| Legacy card QR (card id only) | credential format | Information Security Lead | signed public reference | verification logs | decision D-3 | no environment needs the flag | not set | refused by default |
| Main-sidebar copies of Core pages | navigation | System Owner | Core workspace | page usage | 2026-10-24 | stabilization exit and training done | not set | DUAL AVAILABLE |

## Flags and transition switches

| Switch | Kind | Owner | Purpose | Removal condition | State |
| --- | --- | --- | --- | --- | --- |
| `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT` | transition switch | Information Security Lead | lets `false` restore the old fail-open scope during rollout (ADR 0003) | `access:scope-gaps` empty in production for a month; then remove the `false` path | `true`; `false` fails readiness |
| `ID_CARD_ACCEPT_LEGACY_QR` | transition switch | Information Security Lead | accepts old card-id QR while reprints are pending | reprint finished | `false` |
| `CORE_MASTER_DATA_WRITES` | operational switch (keep) | System Owner | freezes master-data writes during cutover or incidents | — (permanent control) | `open` |
| `INTEGRATION_DISABLED_EVENT_TYPES` | operational kill switch (keep) | Integration/API Owner | stops publishing an event type without a release | — (permanent control) | empty |
| `FEATURE_TRANSPORT`, `FEATURE_TRANSPORT_SETTLEMENT` | module switches | transport module owner | module on/off per deployment | — while the module is optional | on |
| `FEATURE_TRANSPORT_NFC`, `FEATURE_TRANSPORT_OFFLINE`, `FEATURE_TRANSPORT_GPS` | rollout flags | transport module owner | staged rollout of terminal features | feature accepted in UAT and enabled everywhere, or dropped | off |
| `FEATURE_COURT_CASES` | rollout flag | court-case module owner | module not yet released | module accepted in UAT, or dropped | off |

A new flag gets a row here in the same pull request (owner, purpose, removal
condition).

## API endpoints and event types

None is deprecated today. A deprecated endpoint or event gets a row with:
deprecation date, replacement, consumer list (`api:consumers`, 90 days of
`api_request_logs`, webhook subscriptions), migration deadline, removal date,
owner.

| Item | Kind | Owner | Replacement | Consumers | Deprecated | Migration deadline | Removal date | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| — | | | | | | | | |
