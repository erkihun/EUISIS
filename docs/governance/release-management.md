# Release management

Builds on [../change-management-policy.md](../change-management-policy.md)
(change categories, approval, freeze) and
[../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md).
Owner: Release Manager ([roles-register.md](roles-register.md)).

## Branches

- Work on a branch; merge to `main` through a reviewed pull request. No force-pushes, no rewritten history, commit authorship stays true.
- Sensitive paths need their owners' review ([`.github/CODEOWNERS`](../../.github/CODEOWNERS)): Golden Path writes, organization scope, authentication, API and event contracts, schema, card verification. A UI review alone does not approve them.
- The `production` branch is built from `main` by `scripts/release/build-production-branch.sh` (one commit per release, fast-forward only); it is never edited by hand.
- Deployments are done by the operations team, never automatically from a merge.

## Release types

| Type | What | Review | Testing | Window |
| --- | --- | --- | --- | --- |
| PATCH | bug fixes, copy, no contract or schema change | one reviewer; owner review for sensitive paths | CI green | any agreed window |
| MINOR | new features, additive API fields or events, additive migrations | owner review for every sensitive path touched; Business Owner for changed business rules | CI green; targeted manual check in staging; MariaDB/MySQL run of affected suites while the live host runs MySQL | planned window, users told |
| MAJOR | new API or event version, non-additive schema change, change to scope or authentication behaviour, platform upgrade | ADR; System Owner and Information Security Lead; Integration/API Owner for contracts | full suite on SQLite and MariaDB/MySQL; staging rehearsal of migration and rollback; UAT for changed workflows | announced window, rollback rehearsed |
| EMERGENCY | fix for an active incident or critical vulnerability | Incident Manager decides; one reviewer, after-the-fact review within two working days | the tests that cover the fix; full suite afterwards | immediately |

## Release gates

Automated (CI blocks the merge): tests, `composer audit --locked`,
`npm audit --audit-level=high`, Pint, translation audit, migration safety,
OpenAPI contract, event contract, architecture drift tests
([platform-governance.md](platform-governance.md#rules-enforced-by-code-ci-fails-when-broken)).

Before production (the Release Manager confirms each):

- [ ] CI green on the commit the production branch is built from.
- [ ] Static analysis reviewed — not available yet ([technical-debt.md](technical-debt.md) TD-12); until then the reviewers' read of sensitive paths stands in.
- [ ] Security scan reviewed: dependency audits clean or advisories formally accepted.
- [ ] Migrations reviewed against [Database changes](#database-changes); tested on the production engine (MySQL/MariaDB today).
- [ ] API compatibility checked ([api-lifecycle.md](api-lifecycle.md)); consumers listed from `api:consumers` when a contract changed.
- [ ] Event compatibility checked ([event-lifecycle.md](event-lifecycle.md)).
- [ ] Rollback prepared: previous release runs on the new schema; backup taken before `migrate --force`.
- [ ] Release note written ([../releases/](../releases/README.md)).
- [ ] After deploy: `core:smoke`, `backbone:audit-integrity`, `production:readiness`; watch for an hour.

A critical failure blocks the deployment. Go-live itself additionally needs the
gates in [../go-live/go-live-gates.md](../go-live/go-live-gates.md).

## Release notes

Every production release has a note in [../releases/](../releases/README.md):
version, date, source commit, features, bug fixes, database migrations, API
changes, event changes, security changes, dependency changes, known issues,
rollback notes. User-facing changes also get a short notice in English and
Amharic for administrators: what changed, from when, where to get help.
Significant releases add business impact, training, communication and support
impact.

## Versions

Each production release is named by its date and the production commit
(`2026-10-24-production-083bfdec`), and records the `main` commit it was built
from. Git tags `vYYYY.MM.N` on that `main` commit are proposed but not yet in
use ([technical-debt.md](technical-debt.md) TD-13). The API and event versions
are separate and change only by [api-lifecycle.md](api-lifecycle.md) and
[event-lifecycle.md](event-lifecycle.md).

## Feature flags

Flags (`config/features.php`, `INTEGRATION_DISABLED_EVENT_TYPES`, the
transition switches in `.env`) are for high-risk changes: a new workflow, a
new API version, a new hierarchy screen, new verification behaviour. Each new
flag gets a row in the [deprecation register](deprecation-register.md) with
its owner and the condition for removing it. A flag is not permanent
architecture: once the change is settled, the flag and the old path are removed.

## Environments

Development, testing (CI), UAT, staging and production are separate. Each has
its own database, `APP_KEY`, credentials and API tokens; production
credentials are never used outside production, and production data is not
copied to other environments without the Data Owner's approval and masking.
`APP_ENV=production` must be spelled exactly: several safety rules (demo
seeding refusal, readiness gates) key off it.

## Database changes

Every schema change states, in the pull request:

- [ ] the migration, guarded so a failed run can resume (`hasTable` / `hasColumn` / named keys under 64 characters for MySQL);
- [ ] rollback or compatibility: the previous release keeps working on the new schema;
- [ ] index impact (size, lock time on the largest tables);
- [ ] data migration plan for existing rows, run in batches;
- [ ] test coverage, on SQLite and on MySQL/MariaDB;
- [ ] production risk and the backup taken before it.

`MigrationSafetyTest` fails on a destructive step in a migration after the
baseline unless it carries a reviewed `migration-safety: reviewed` marker.

### Expand → migrate → verify → contract

A breaking schema change is spread over releases, never done in one:

1. **Expand**: add the new column or table (nullable or defaulted); deploy.
2. **Migrate**: write both where needed; backfill in batches; switch reads.
3. **Verify**: compare old and new (counts, integrity checks) for at least one release cycle.
4. **Contract**: remove the old column in a later release, once nothing reads it and the rollback window has passed.

Never drop a column in the same release that stops the application using it.
Never delete a migration that may have run in production.

## Hotfixes

Branch from the deployed `main` commit, smallest change, EMERGENCY or PATCH
checklist (tests may be targeted when the full suite cannot run in the
window, then run in full after), merge back to `main`, rebuild `production`.
