# Deprecation register

Everything announced for retirement. Process: [../architecture/legacy-deprecation.md](../architecture/legacy-deprecation.md),
[api-lifecycle.md](api-lifecycle.md), [event-lifecycle.md](event-lifecycle.md).
Nothing listed here has been removed.

| Item | Kind | Replacement | Announced | Earliest removal | Evidence of non-use | State |
| --- | --- | --- | --- | --- | --- | --- |
| Institution offices | data master + pages | Organization units | before 2026-10 ([../institution-office-deprecation.md](../institution-office-deprecation.md)) | when `backbone:duplicate-masters` shows no use for a quarter | pending | redirects in place |
| `users.national_id` | identity copy | employee record via the user's employee link | 2026-10 (ADR 0001) | after count reaches 0 | `backbone:duplicate-masters` | open |
| `api_clients` table | legacy partner store | registered applications | 2026-10-24 | after production row count is confirmed 0 | pending | unused in code |
| Tokens with `provider:access` or `*` on registered applications | credentials | exact scopes | 2026-10 | after `api:access-review` shows no LEGACY_CREDENTIAL | `api:access-review` | refused per request |
| Legacy card QR (card id only) | credential format | signed public reference | decision D-3 | when no environment needs the flag | verification logs | refused by default (`ID_CARD_ACCEPT_LEGACY_QR=false`) |
| Main-sidebar copies of Core pages | navigation | Core workspace | 2026-10-24 | after stabilization exit and training | usage | DUAL AVAILABLE |

No API endpoint or event type is deprecated today.
