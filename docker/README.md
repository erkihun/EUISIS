# Running Agelgay (EUISIS) with Docker

The stack in `compose.yaml` runs the whole application the way the production
manual describes it, in containers: nginx in front of PHP-FPM, a PostgreSQL
database, a queue worker and the scheduler. Everything is built from this
checkout, so the containers always run the code you have here.

## Requirements

- Docker Desktop 4.x with the WSL 2 backend (Windows) or Docker Engine 24+ with
  the Compose plugin (Linux).
- About 4 GB of free disk for images and the database volume.

## First run

```bash
cp docker/env.example .env.docker
```

Generate an application key and paste it into `APP_KEY=` in `.env.docker`:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

Then build and start everything:

```bash
docker compose up --build -d
```

The first build takes several minutes (Composer, npm, Vite). When `docker compose ps`
shows `app` as *healthy*, open <http://localhost:8080>. The health endpoint is
<http://localhost:8080/up>.

Migrations run automatically on every start of the `app` container while
`AUTO_MIGRATE=true` in `.env.docker`. Seed a fresh database with the reference
data and the demo organisations and accounts:

```bash
docker compose exec app php artisan db:seed
```

With `APP_ENV=local` the demo accounts (`super.admin@demo.local`,
`city.admin@demo.local`, `hr.officer@demo.local`, `provider.transport@demo.local`)
get the fixed password from `database/seeders/Concerns/DemoPasswords.php`.

The demo seeder builds its data with model factories, so it needs Composer's dev
packages (Faker). `compose.yaml` therefore builds the image with
`WITH_DEV_DEPS=true`. For a lean production-style image, build with
`WITH_DEV_DEPS=false docker compose build` and seed only the reference seeders
the `DatabaseSeeder` lists in its production guard.

## Everyday commands

| What | Command |
| --- | --- |
| Start / stop | `docker compose up -d` / `docker compose down` |
| Rebuild after code changes | `docker compose up --build -d` |
| Logs (all services) | `docker compose logs -f` |
| Artisan | `docker compose exec app php artisan <command>` |
| Shell in the app container | `docker compose exec app bash` |
| psql | `docker compose exec db psql -U euisis euisis` |
| Reset the database (deletes data) | `docker compose down -v` then `up --build -d` |

The database is also reachable from this machine on `127.0.0.1:15432` with the
`DB_USERNAME` / `DB_PASSWORD` values from `.env.docker` (for pgAdmin or DBeaver).
Set `DB_HOST_PORT` in your shell to use a different port.

## Services

| Service | Image | Role |
| --- | --- | --- |
| `db` | `postgres:18-alpine` | database; data persists in the `db-data` volume |
| `app` | built, target `app` | PHP 8.4 FPM; waits for the database, links storage, migrates, caches config/routes/views |
| `web` | built, target `web` | nginx on port 8080; serves `public/` and passes PHP to `app` |
| `queue` | same image as `app` | `php artisan queue:work --queue=default,notifications,long` |
| `scheduler` | same image as `app` | `php artisan schedule:work` (every entry in `routes/console.php`) |

`app`, `queue` and `scheduler` share the `storage` volume, so uploads, logs and
cached views are the same in all three. `web` mounts it read-only to serve files
on the public disk.

## How the image is built

`Dockerfile` has four stages:

1. `vendor` runs `composer install` (`--no-dev` unless `WITH_DEV_DEPS=true`).
2. `assets` runs `npm ci` and `npm run build` (TypeScript check plus Vite). It
   copies `vendor/tightenco/ziggy` from the previous stage because
   `vite.config.js` resolves the `route()` helper from there.
3. `app` is `php:8.4-fpm` with `pdo_pgsql`, `pgsql`, `pdo_mysql`, `intl`, `gd`,
   `zip`, `bcmath`, `pcntl`, `exif`, `opcache` and `redis`. It copies the source,
   the vendor directory and the built assets, and dumps an optimised autoloader.
   OPcache runs with timestamp validation off, so a code change needs a rebuild.
4. `web` is `nginx:alpine` with a copy of `public/`.

`docker/entrypoint.sh` is the entrypoint of the `app` image. Its first argument
picks the role: `php-fpm` (default), `queue`, `scheduler`, or any other command,
which runs as `www-data`.

`.dockerignore` keeps `.env`, `vendor`, `node_modules`, `storage` contents,
tests, reports and editor state out of the image.

## Environment

`.env.docker` is the only environment file the containers read; the local `.env`
is ignored. The Laravel `DB_*` values and the `POSTGRES_*` values must agree,
because the first configure the application and the second initialise the
database container on its first start.

`CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` use the database, as on
the production server, so no Redis container is needed. The `redis` PHP
extension is in the image if you later add one.

## Known log lines

- Every five minutes the scheduler logs `Scheduled command [... backup:health --monitor]
  failed with exit code [1]`. That is the application reporting that no backup
  system is configured, not a container fault. To quiet it locally, enable the
  report driver (`BACKUP_STATUS_ENABLED=true`, `BACKUP_DRIVER=report`) and create a
  sample report with `docker compose exec app php artisan backup:local-report`.
- `CACHE_STORE` is `database`, not `file`: the three PHP containers share one
  storage volume and the file store raced on its lock files.

## Production notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`,
  `SESSION_SECURE_COOKIE=true` and a real `APP_URL`, and put a TLS-terminating
  proxy in front of `web`.
- Keep `AUTO_MIGRATE=false` in production and run `php artisan migrate --force`
  as a release step, as the deployment manual describes.
- The database volume is the only state besides `storage`; back both up.
