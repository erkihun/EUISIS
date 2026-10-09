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

### Testing My Portal from a phone or tablet

`127.0.0.1` and `localhost` identify the phone itself, not the development
computer. The normal development configuration deliberately binds to that
loopback address. To test on the same trusted Wi-Fi/LAN:

1. Find the computer's IPv4 address (`ipconfig` on Windows). For the current
   Wi-Fi network it is `192.168.8.2`; use the current address if it changes.
2. Add this **only to the untracked local `.env`**:

   ```dotenv
   VITE_DEV_SERVER_HOST=192.168.8.2
   VITE_DEV_SERVER_PORT=5173
   ```

3. Run the application with `php artisan serve --host=0.0.0.0 --port=8000`
   and run `npm run dev` in a second terminal. Vite will publish its assets and
   HMR connection using the configured LAN address.
4. On the phone, open `http://192.168.8.2:8000` (never `127.0.0.1:8000`).
   Allow inbound TCP 8000 and 5173 only on the Windows **Private** network if
   Windows Firewall asks.

For a production-like local check, run `npm run build`, stop Vite, and expose
only the Laravel server on the trusted network. Do not bind a production
deployment directly to `0.0.0.0`; use its HTTPS reverse proxy/domain instead.

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
