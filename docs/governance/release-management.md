# Release management

Builds on [../change-management-policy.md](../change-management-policy.md)
(change categories, approval, freeze) and
[../runbook/deployment-and-rollback.md](../runbook/deployment-and-rollback.md).

## Branches

- Work on a branch; merge to `main` through a reviewed pull request. No force-pushes, no rewritten history, commit authorship stays true.
- The production branch is built from `main` by `scripts/release/build-production-branch.sh`; it is never edited by hand.
- Deployments are done by the operations team, never automatically from a merge.

## Release checklist

- [ ] CI green: tests, translation audit, build, Pint.
- [ ] Migrations additive (`MigrationSafetyTest`); any exception has its own reviewed plan.
- [ ] OpenAPI and event catalog updated where the contract changed.
- [ ] `composer audit` and `npm audit --omit=dev`: no high or critical advisory.
- [ ] Release notes in English and Amharic for administrators; partner notes when the API or events change.
- [ ] Rollback stated: previous release runs on the new schema.
- [ ] After deploy: `core:smoke`, `backbone:audit-integrity`, watch for an hour.

## Versions

The repository has no release tags yet. Proposed: tag each production release `vYYYY.MM.N` on the commit the production branch was built from, so a rollback names an exact version. The API and event versions are separate and change only by [api-lifecycle.md](api-lifecycle.md) and [event-lifecycle.md](event-lifecycle.md).

## Hotfixes

Branch from the deployed tag, smallest change, same checklist (tests may be
targeted when the full suite cannot run in the window, then run in full
after), merge back to `main`.
