# Local Development Runbook

## Target Stack

- PHP 8.4
- Composer
- Node.js current LTS
- PostgreSQL
- Redis

## First-Time Setup

1. Install PHP, Composer, Node.js, PostgreSQL, and Redis.
2. Copy `.env.example` to `.env`.
3. Configure database, Redis, mail, and storage settings.
4. Generate the application key.
5. Run migrations and seed demo data.

## Expected Environment Variables

- `APP_NAME`
- `APP_ENV`
- `APP_URL`
- `DB_CONNECTION=pgsql` or `DB_CONNECTION=postgres`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `REDIS_HOST`
- `REDIS_PORT`
- `FILESYSTEM_DISK`
- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis`
- `SESSION_DRIVER=redis`
- `SANCTUM_STATEFUL_DOMAINS`

## Common Commands

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan test
npm run build
```

## Seeding

```bash
# Reference / system data (permissions, roles, settings, code rules, catalogs)
php artisan db:seed

# Development / QA / UAT demo dataset — never production
php artisan db:seed --class=DemoDataSeeder
php artisan demo-data:validate
```

`DemoDataSeeder` is never called by `DatabaseSeeder`, refuses to run when `APP_ENV=production` (there is no override), is safe to re-run, and never truncates or deletes. Outside `local`/`testing` it needs `DEMO_USER_PASSWORD`. What it creates: [demo-seed-data.md](../demo-seed-data.md).

## Development Services

- Web app: `php artisan serve`
- Queue worker: `php artisan queue:work`
- Horizon: `php artisan horizon`
- Vite: `npm run dev`

## Storage Notes

- Employee photos and documents must use private storage.
- For local development, use a private local disk path outside the public web root if needed.

## Quality Commands

```bash
./vendor/bin/pint
php artisan test
composer audit
npm audit
```

## Current Notes

- The local repository `.env` currently uses `DB_CONNECTION=postgres`; the app now supports both `pgsql` and `postgres` aliases.
- Demo seeding publishes the hierarchy through `PublishHierarchyVersionAction`, so subtree authorization and demo closure paths are generated from the same code path used by the application.
- The current web MVP includes scoped employee list/detail/update flows, a dedicated employee transfer module, position CRUD, card request/approve/print/issue/incident/replace flows, provider detail pages, and entitlement grant forms.
- PostgreSQL local seeding no longer depends on MySQL-only `FOREIGN_KEY_CHECKS` statements.

## Expected Seeded Demo Data

- root city organization
- demo sub-cities, woredas, bureaus, and service providers
- roles and permissions
- demo users
- demo employees, cards, entitlements, and sample transactions
