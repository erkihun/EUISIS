# Agelgay · አገልጋይ (AA-IEMSS)

**Addis Ababa Integrated Employee Management & Service System**
**የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት**

Agelgay (አገልጋይ, "servant": the civil servant who serves the public) is the
Addis Ababa City Administration's platform for institution
structure, positions, the employee registry and identity (ID cards with a
stable verification QR), performance management, daily activity, field work,
grievances, public service feedback and employee services. It is a
Laravel 12 / PostgreSQL application with an Inertia React/TypeScript
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

## Documentation

- [docs/production-golden-path.md](docs/production-golden-path.md) — deployment, from a clean host to go-live
- [docs/branding.md](docs/branding.md) — product identity and brand policy
- [docs/public-feedback.md](docs/public-feedback.md) — public feedback, suggestions and complaints
- [docs/rbac-architecture.md](docs/rbac-architecture.md) — roles and permissions
- [docs/load-testing.md](docs/load-testing.md) and [docs/capacity-plan.md](docs/capacity-plan.md) — measured capacity
- [docs/security-requirements.md](docs/security-requirements.md) — security baseline

The repository directory and the Composer/npm package names keep their
historical identifiers; renaming them is a separate, deliberate change.
