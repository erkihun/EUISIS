# ADR 0014 — The cafeteria module as its own platform

- Status: accepted
- Date: 2026-10-27
- Deciders: product owner (decisions delegated, see "Decisions taken" below)
- Related: [ADR 0006](0006-standalone-core-platform.md) (the Core platform, whose skeleton this reuses), [ADR 0013](0013-court-ms-integration.md) (the integration pattern), `docs/integration/cafeteria-platform.md`

## Context

The cafeteria subsidy module (`docs/cafeteria-module.md`, `docs/cafeteria-policy-architecture.md`) is the
largest service module in EUISIS: 23 tables, ~60 screens, a provider portal with its own sign-in, scanning,
policies, settlements and reports. It already reads master data (employees, placements, cards) only through
the backbone contracts (`App\Backbone\Contracts`, ADR 0001), which made a physical separation possible.

The owner asked for the module extracted into a standalone application, **without redesigning** it, with the
two systems connected over an API.

## Decision

1. **`cafeteria-platform/` is the cafeteria module as its own system**: a Laravel backend
   (`cafeteria-platform/backend`, database `cafeteriadb`, dev port 8110) and the EUISIS screens copied
   unchanged (`cafeteria-platform/frontend`, built into the backend). It starts from the Core platform
   skeleton (ADR 0006: sign-in, MFA, roles, settings, audit, integration center, system health) with the
   Core's master-data screens removed.
2. **The screens are the EUISIS screens, 100% the same.** `frontend/scripts/sync-from-euisis.mjs` copies the
   pages it lists (every `Cafeteria/*` page, the provider-portal sign-in, the skeleton's administration pages)
   with their import closure, the translations and the UI kit. Nothing is edited by hand; the only differences
   are two script patches (unknown routes link to `#`, the cafeteria icons for the server-sent navigation).
3. **The backend is the EUISIS cafeteria backend, copied by the same script** (`BACKEND_DIRS`,
   `BACKEND_COPIES`): models, enums, services, actions, controllers, requests, resources, policies, exports,
   middleware, the provider portal, the audit action and the backbone contracts and data objects. Three
   one-line replacements keep the copies working over the platform's string-status master data. CI fails when
   a copy drifts from EUISIS.
4. **The schema is generated from the live EUISIS schema**, not replayed from EUISIS migrations:
   `backend/database/schema-from-euisis.php` reads `information_schema` of an EUISIS PostgreSQL database and
   writes one Blueprint migration for the cafeteria, provider-catalog, holiday, audit and service-transaction
   tables, keeping the foreign keys between them and to the mirrored master data. Regenerate it, never edit it.
5. **Master data is mirrored from EUISIS over the EUISIS API** (`EUISIS_URL`, `EUISIS_TOKEN`; an application
   registered in EUISIS › API Management with `organizations.read`, `organization_units.read`,
   `employees.basic_read`, `id_cards.verify`, `service_eligibility.check`):
   - `cafeteria:sync-from-euisis` mirrors organizations, units and employees with their current placement,
     incrementally (`updated_after`) after the first run. The EUISIS identifiers are kept, so cafeteria
     records name the same employee and organization in both systems.
   - A scanned card is resolved by `EuisisCredentialLookup`: the public reference the printed QR carries is
     verified with `GET /api/v1/id-cards/verify/{uuid}`, the card, holder, placement and organization are
     mirrored, and the mirrored card is evaluated by the single credential rule. A card verified within
     `EUISIS_CARD_FRESH_MINUTES` is not asked about again; while EUISIS cannot be reached, a card verified
     within `EUISIS_OFFLINE_GRACE_HOURS` is still accepted. The legacy `cardId|token` format carries a secret
     only EUISIS can check, so it is accepted only while EUISIS answers.
   - Mirrored cards are keyed by their public reference. Token hashes never leave EUISIS.
   - `ServiceEligibility` (employment, placement and card validity) is answered over the mirror; the
     cafeteria's own rules (access, assignment, policy, entitlement, exclusions) run unchanged.
6. **EUISIS reads cafeteria results back over the platform's API** (`/api/v1/integration/cafeteria/*`, an
   API client on the platform with the `cafeteria:read` scope): an employee's standing and meals (the
   employee portal's My Services), the cafeterias and a period's totals (for the Situation Room). The
   contract is in `cafeteria-platform/docs/openapi/core-integration-v1.yaml`.
7. **EUISIS keeps its cafeteria code and tables; a switch moves the module.** `CAFETERIA_PLATFORM_MODE=local`
   (default) changes nothing. `platform` makes the EUISIS cafeteria pages and the cafeteria side of the
   provider portal redirect to the platform on the same path, and makes the employee portal read an
   employee's cafeteria standing from the platform (`App\Services\CafeteriaPlatform\CafeteriaPlatformClient`).
   NFC credentials stay in EUISIS: on the platform a tap answers "not available" and the operator uses the QR.

## Decisions taken on the owner's behalf

| Decision | Choice and reason |
| --- | --- |
| Base of the new system | The Core platform skeleton (ADR 0006), because it already serves EUISIS screens with EUISIS route names, roles, settings and an integration API; writing a second skeleton would have meant a second design. |
| Master-data transport | The EUISIS API, as asked, with a local mirror so a counter keeps serving through a short EUISIS outage; the Core's direct-database import is not used. |
| Who verifies a card | EUISIS, at scan time, by the card's public reference; the mirror keeps the answer for a few minutes. Token hashes are never copied. |
| Card record identity on the platform | The public reference (the UUID in the QR), the one identifier both verification routes return. |
| What EUISIS shows in platform mode | A redirect to the same path on the platform (the screens are identical), rather than two live copies of the module. |
| Employee-portal activity in platform mode | Computed from the platform's meals over the EUISIS working-day calendar, in the shape the page already renders; the platform's own eligibility verdict rides along under `activity.platform`. |
| Roles | The three system roles receive the EUISIS cafeteria permissions: a viewer the read permissions, an officer the operating ones, an administrator all. Read-only cafeteria permissions do not make an account "privileged" for MFA. |
| Service transaction ledger | Mirrored on the platform (one row per meal, as EUISIS writes it); the service-terminal and entitlement links point back to EUISIS and are kept as plain columns. |
| Provider users of the old cafeteria portal (`Cafeteria/ProviderUsers/*` pages) | Not served: EUISIS has no such pages either (the routes there point at pages that do not exist). |

## Consequences

- The module can be run, deployed and scaled apart from EUISIS. EUISIS stays the writer of master data; the
  platform is the writer of cafeteria data.
- Two operations to keep in mind: `cafeteria:sync-from-euisis` on a schedule (it is idempotent), and the
  EUISIS application token for the platform plus the platform API client for EUISIS (both rotate from the
  respective API Management screens).
- The Situation Room's cafeteria analytics in EUISIS still read the EUISIS tables. In platform mode they
  should read `/api/v1/integration/cafeteria/summary`; that is a follow-up.
- Provider-portal self-service password reset by SMS uses the platform's SMS settings.
- The Core skeleton's master-data actions and controllers (organizations, positions, employees, cards,
  imports, print batches, code rules) are still in `backend/app` but no longer routed; pruning them is a
  follow-up that must keep the models, scope service and audit chain the cafeteria uses.
