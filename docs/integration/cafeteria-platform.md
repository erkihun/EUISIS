# Cafeteria platform (the cafeteria module as its own system)

Decision record: [ADR 0014](../architecture/adr/0014-cafeteria-platform.md). The platform itself is
documented in [`cafeteria-platform/README.md`](../../cafeteria-platform/README.md).

Three flows:

| Flow | Direction | What moves | Runs |
| --- | --- | --- | --- |
| 1 Master data | platform reads EUISIS | Organizations, units, employees with their current placement | `cafeteria:sync-from-euisis` on the platform, on a schedule (every 10 minutes is fine; incremental after the first run) |
| 2 Card verification | platform reads EUISIS | The card a scanned QR refers to, its holder and placement | At scan time (`EuisisCredentialLookup`), cached for a few minutes |
| 3 Results | EUISIS reads the platform | An employee's cafeteria standing and meals, the cafeterias, a period's totals | On request (employee portal), `cafeteria-platform:ping` for a check |

## Set up

Do it on a test pair first.

### 1. Register the platform in EUISIS (Flows 1 and 2)

System Settings → API Management → new application, for example code `CAFETERIA_PLATFORM`:

- organization access: city-wide;
- allowed IPs: the platform server;
- scopes: `organizations.read`, `organization_units.read`, `employees.basic_read`, `id_cards.verify`,
  `service_eligibility.check`;
- endpoints: `GET /api/v1/organizations/directory`, `GET /api/v1/organizations/{organization}/units`,
  `GET /api/v1/employees`, `GET /api/v1/employees/{employee}`,
  `GET /api/v1/employees/{employee}/service-eligibility`, `GET /api/v1/id-cards/verify/{token}`,
  `POST /api/v1/cards/verify`, `GET /api/v1/cards/{cardUuid}`.

Issue a token (shown once) for the platform's `EUISIS_TOKEN`.

### 2. Configure the platform

```env
EUISIS_URL=https://euisis.example.gov.et
EUISIS_TOKEN=<token from step 1>
EUISIS_CARD_FRESH_MINUTES=5
EUISIS_OFFLINE_GRACE_HOURS=24
```

Then, in `cafeteria-platform/backend`:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan cafeteria:euisis-ping
php artisan cafeteria:sync-from-euisis --full
```

Make sure the scheduler runs `cafeteria:sync-from-euisis` (add it to `routes/console.php` of the platform
with the interval you want), and create the first administrator with `php artisan core:create-user`.

### 3. Register EUISIS on the platform (Flow 3)

On the platform, Integration Center → API clients → new client for EUISIS with the scope `cafeteria:read`
(and the EUISIS server's IP). The secret is shown once.

### 4. Configure EUISIS

```env
CAFETERIA_PLATFORM_MODE=local        # platform, once the platform serves the cafeteria
CAFETERIA_PLATFORM_URL=https://cafeteria.example.gov.et
CAFETERIA_PLATFORM_TOKEN=<secret from step 3>
```

`php artisan config:cache`, then `php artisan cafeteria-platform:ping`.

## Using it

- **Mode `local`** (default): nothing changes in EUISIS; the platform can be filled and checked alongside.
- **Mode `platform`**: the EUISIS cafeteria pages (`/cafeteria/...`, the cafeteria side of the provider
  portal, `/cafe`) send people to the same path on the platform; My Services in the employee portal reads the
  employee's cafeteria standing and meals from the platform. The EUISIS cafeteria tables keep their history.
- **Scanning on the platform**: the scanner takes the QR code of the ID card. Each new card is verified with
  EUISIS once and then served from the mirror for a few minutes; a card EUISIS no longer knows is refused and
  marked revoked on the mirror. NFC taps answer "not available" on the platform.

## What the platform API answers (scope `cafeteria:read`)

| Call | Answer |
| --- | --- |
| `GET /api/v1/integration/cafeteria/employees/{employee}/entitlement` | eligible, reason, the policy in force (amounts, currency, daily uses), the cafeteria, meals used today, any exclusion |
| `GET /api/v1/integration/cafeteria/employees/{employee}/transactions?limit=30` | the employee's meals, newest first |
| `GET /api/v1/integration/cafeteria/providers` | the cafeterias (locations) |
| `GET /api/v1/integration/cafeteria/summary?from=&to=` | scans, meals, employees served, subsidy and what employees paid, per cafeteria |

Errors follow the platform's integration contract (`{message, code, errors}`; `SCOPE_DENIED`, `NOT_FOUND`,
`RATE_LIMITED`).

## Local rig (this workstation, 2026-10-27)

- EUISIS at `http://127.0.0.1:8000` holds the application `CAFETERIA_PLATFORM_LOCAL` (city-wide, 7 endpoints).
- The platform runs from `.claude/launch.json` (`cafeteria-platform`, port 8110) on PostgreSQL `cafeteriadb`
  (`cafeteriadb_test` is for a PostgreSQL test run), with the API client "EUISIS (local)".
- EUISIS `.env` has `CAFETERIA_PLATFORM_MODE=local`, the platform URL and its token; the platform `.env` has
  `EUISIS_URL`/`EUISIS_TOKEN`. Both pings answer; the first full sync mirrored the 21 organizations and 28
  employees of the local EUISIS database.
