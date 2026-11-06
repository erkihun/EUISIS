# Agelgay · አገልጋይ (AA-IEMSS)

**Addis Ababa Integrated Employee Management & Service System**
**የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት**

Agelgay (አገልጋይ, "servant": the civil servant who serves the public) is the
Addis Ababa City Administration's platform for institution
structure, positions, the employee registry and identity (ID cards with a
stable verification QR), performance management, daily activity, field work,
grievances, public service feedback and employee services. It is a
Laravel 13 / PostgreSQL application with an Inertia React/TypeScript
front end, in English and Amharic.

Brand usage rules, the configurable identity (System Settings › General)
and the list of legacy identifiers kept for compatibility are in
[docs/branding.md](docs/branding.md). The product was known as "EUISIS"
until 2026-10-12 and as "Addis One" until 2026-10-21; those names survive only
in historical records and in technical identifiers listed there.

## Getting started

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
npm run build && php artisan serve
```

Tests: `vendor/bin/pest` (SQLite in memory; see [docs/testing.md](docs/testing.md)).
Production gates: `php artisan production:readiness` and
`php artisan security:production-check`.

## Where the product stands

The pilot release is tagged `v1.0.0-pilot` ([CHANGELOG.md](CHANGELOG.md)). A
**scope freeze** is in force until the pilot ends: no new module, only defects,
hardening, wording and user documentation ([docs/ROADMAP.md](docs/ROADMAP.md)).
The pilot is measured with `php artisan pilot:baseline`
([docs/pilot-measurement.md](docs/pilot-measurement.md)).

## Documentation

- [docs/user-guide/am/](docs/user-guide/am/README.md) — የተጠቃሚ መመሪያ (Amharic user guides, one per module)
- [docs/ROADMAP.md](docs/ROADMAP.md) — scope freeze and the post-pilot order
- [docs/production-monitoring.md](docs/production-monitoring.md) — health probes, error tracker, logs
- [docs/security/README.md](docs/security/README.md) — the living security documents (reports are in docs/archive)
- [docs/production-golden-path.md](docs/production-golden-path.md) — deployment, from a clean host to go-live
- [docs/branding.md](docs/branding.md) — product identity and brand policy
- [docs/public-feedback.md](docs/public-feedback.md) — public feedback, suggestions and complaints
- [docs/rbac-architecture.md](docs/rbac-architecture.md) — roles and permissions
- [docs/load-testing.md](docs/load-testing.md) and [docs/capacity-plan.md](docs/capacity-plan.md) — measured capacity
- [docs/security-requirements.md](docs/security-requirements.md) — security baseline

The repository directory and the Composer/npm package names keep their
historical identifiers; renaming them is a separate, deliberate change.

## Maintainer

Erkihun Bewket maintains this repository. Contribution history is in the Git
log, including co-author trailers.
