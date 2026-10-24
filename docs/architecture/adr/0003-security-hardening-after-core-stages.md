# ADR 0003: Security hardening after the Core stages

Status: accepted, 24 October 2026. Decided by the maintainers' delegate on the
project owner's instruction ("security first, as your recommendation, fix all
stages"). Items that need a business owner's approval are recommendations
only and are marked so.

## Context

The Core Stage 1–8 reports left open security and integrity gaps. The ones
that code can close are decided here; the rest stay with their owners
(penetration test, UAT, backup infrastructure, role holders).

## Decision 1: organization-level roles need an explicit scope

Before: an account with no organization-scope record was "not scoped yet"
and reached every organization, whatever its roles. An HR officer account
created without a scope row was therefore city-wide.

Now: an account holding any organization-level (scoped) role and no scope
record reaches only its linked employee's current organization, or nothing
when it has no linked employee. Accounts whose roles are all global, and
Super Admin, System Admin and City Admin, are unchanged; city-wide work for a
scoped role is granted with an explicit `citywide` scope record. Role-less
accounts with direct permissions keep the old reach and are listed for review.

- `OrganizationScopeService::requiresExplicitScope()` is the single rule; the
  vacancy list and the citywide-grant validation use it too.
- `php artisan access:scope-gaps` lists the accounts this confines (ids, names
  and roles only).
- `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT=false` restores the old rule for a
  transition; `production:readiness --strict` fails while it is off.

Rollout: run `access:scope-gaps` on production before deploying, give each
listed account a scope (or a global role when its work is city-wide), deploy,
run it again.

Which of the two: a `citywide` scope record lets an operational role see and
work on every organization's records. City-level functions that were built
for "unrestricted" actors — city assessment oversight (cycle and policy
administration, city verification), city-wide assessment forms, business
alerts in Intelligence — still need an unrestricted actor: City Admin,
Public Service Bureau Admin, or a custom role marked global in Roles. Before
this decision such staff got there implicitly by having no scope record; now
it is an explicit role choice. The test suite was updated the same way
(city-level test actors use global roles; operational test actors get
citywide or self scope records).

## Decision 2: audit-log tamper evidence

Each finished day of `audit_logs` is sealed (`audit:seal`, scheduled 00:20)
with an HMAC-SHA256 over every row of the day in a fixed order, chained to the
previous day's seal. The key is derived from `APP_KEY`, so someone with only
database access cannot recompute a seal; `APP_PREVIOUS_KEYS` keeps older
seals verifiable after a key rotation. `audit:verify` reports any sealed day
whose rows were changed, added or removed (`MODIFIED`), whose chain was broken
(`CHAIN_BROKEN`) or whose key is unknown. `production:readiness` verifies the
last seven days and warns when sealing lags. Retention removes the seals of
the days it prunes. No audit row is ever changed by sealing.

## Decision 3: provider card checks name their provider

A person calling `POST /api/v1/cards/verify` or
`POST /api/v1/services/{serviceType}/authorize` without `provider_code` skipped
the provider-scope check and matched an entitlement of any provider. Both now
answer 422 `provider_required` and record a `ProviderApiDenied` audit row.
Applications are unchanged (they are bound to one provider). On the authorize
route a body `service_type` that differs from the path is refused instead of
silently ignored.

## Decision 4: two more foreign keys

`employees.current_assignment_id → employee_assignments.id` and
`id_cards.previous_card_id → id_cards.id`, added the same guarded way as the
positions key: skipped (and logged) when orphaned rows exist, skipped on
SQLite, no cascade. The development seeders clear these pointers before they
delete demo rows.

## Decision 5: an empty scope set means nothing, everywhere

Three places still read an empty organization set as "no filter", so a
scoped user whose scope rows had all expired (risk SEC-5), or an
organization-level role without a scope record, saw everything there:

- the NFC management pages (credentials, verification logs, terminals,
  organization options): `NfcAccess::organizationScope()` now returns null
  only for an unrestricted user and an empty set otherwise;
- the organization-unit index and create form (organization list, unit tree,
  KPI counts, organization selection);
- the organization-scope delegation rule (a scoped actor with an empty set
  could grant any organization).

All three now use `OrganizationScopeService::isUnrestricted()`, like the rest
of the application. `EmptyScopeFailsClosedTest` covers them.

## Decision 6: build toolchain advisory

esbuild is raised to 0.28 (GHSA-g7r4-m6w7-qqqr, development server on
Windows; build-time only).

## Recommendation (needs business-owner approval): RPO and RTO

Recommended targets for the backup objectives, based on the designed
PostgreSQL PITR with continuous WAL archiving:

| Objective | Recommended | Basis |
| --- | --- | --- |
| RPO | 15 minutes | WAL archiving with `archive_timeout` of 60 s keeps the real loss near a minute; 15 minutes leaves room for archive lag alerts |
| RTO, application node lost | 1 hour | stateless nodes behind the load balancer |
| RTO, database restore (PITR) | 4 hours | restore, WAL replay and validation for a database of the 3M benchmark's size (13 GB); not yet timed, the first restore drill must confirm it |

These are **not** configured: `BACKUP_RPO` and `BACKUP_RTO` stay
`NEEDS_DECISION` until the business owner approves them in writing, then
operations sets them and `production:readiness` measures drills against them.
