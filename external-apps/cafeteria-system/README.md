# Cafeteria System

A **standalone** cafeteria application that integrates with the EUISIS Unified
ID platform over HTTP only.

It has **no connection to the EUISIS database**, shares no tables, and holds no
foreign keys into EUISIS. Employee identity and service eligibility are
resolved per scan through the EUISIS integration API.

---

## 1. Architecture

| Responsibility | Owner |
|---|---|
| Employees, ID cards, organizations | **EUISIS** |
| Card validity + service eligibility | **EUISIS** |
| API tokens, scopes, request logging | **EUISIS** |
| Provider users, scan workflow | **Cafeteria System** |
| Service transactions, reports, settlements | **Cafeteria System** |

```
Cafeteria System  ──HTTPS + Bearer token──>  EUISIS API
   (own DB)                                   (own DB)
```

The two databases never touch.

---

## 2. Status: scaffold

This is a **complete, reviewable scaffold**, not a running application. It
contains the integration client, business services, models, migrations, routes,
controllers and tests. It still needs a Laravel skeleton around it — see
Installation.

---

## 3. Installation

```bash
cd external-apps/cafeteria-system

# 1. Generate a Laravel skeleton in a temp dir, then merge its runtime files
#    (artisan, bootstrap/, public/index.php, base config/) into this folder.
composer create-project laravel/laravel /tmp/cafeteria-skeleton "^12.0"
cp -r /tmp/cafeteria-skeleton/{artisan,bootstrap,public} .
cp -n /tmp/cafeteria-skeleton/config/*.php config/

# 2. Install dependencies from the composer.json in this folder
composer install
npm install

# 3. Configure
cp .env.example .env
php artisan key:generate

# 4. Create the cafeteria database, then
php artisan migrate
```

Register the `cafeteria` auth guard in `config/auth.php`:

```php
'guards' => [
    'cafeteria' => ['driver' => 'session', 'provider' => 'cafeteria_users'],
],
'providers' => [
    'cafeteria_users' => [
        'driver' => 'eloquent',
        'model' => CafeteriaSystem\Models\CafeteriaUser::class,
    ],
],
```

Register the scan rate limiter in a service provider:

```php
RateLimiter::for('cafeteria-scan', fn ($request) => Limit::perMinute(
    (int) config('euisis.scan_rate_limit')
)->by($request->user('cafeteria')?->getKey() ?: $request->ip()));
```

---

## 4. Environment variables

| Variable | Purpose |
|---|---|
| `EUISIS_API_BASE_URL` | EUISIS base URL, no trailing slash |
| `EUISIS_API_TOKEN` | Bearer token from EUISIS API Management |
| `EUISIS_API_TIMEOUT` | Seconds before a call fails closed (default 10) |
| `CAFETERIA_PROVIDER_CODE` | Identifies this cafeteria installation |
| `CAFETERIA_SCAN_RATE_LIMIT` | Scans per minute per user (default 30) |

`DB_*` must point at the **cafeteria** database — never EUISIS.

---

## 5. Registering in EUISIS

1. Sign in to EUISIS as Super Admin / System Admin / API Manager.
2. **System Settings → API Management → New Application**.
3. Name `Cafeteria System`, code `CAFETERIA`.
4. Tick these scopes:
   - `id_cards.verify`
   - `employees.basic_verify`
   - `service_eligibility.check`
   - `service_transactions.create`
5. Set a rate limit (e.g. 300/min) and optionally an IP allowlist.
6. Open the application → **Generate Token**.
7. Copy the token **immediately** — it is shown once — into `EUISIS_API_TOKEN`.

To rotate: generate a new token, deploy it, then revoke the old one.

---

## 6. API integration

| Call | EUISIS endpoint | Scope |
|---|---|---|
| Verify scanned card | `GET /api/v1/id-cards/verify/{token}` | `id_cards.verify` |
| Check eligibility | `GET /api/v1/employees/{employee}/service-eligibility` | `service_eligibility.check` |
| Record transaction | `POST /api/v1/services/{serviceType}/transactions` | `service_transactions.create` |

All calls go through `EuisisApiClient`. Writes send an `Idempotency-Key` so a
retried request cannot double-record.

The QR carries only `https://<euisis>/verify/card/<uuid>` — an opaque
reference, no personal data.

---

## 7. Serving rules

Enforced in `ServeEmployeeService`, in order, failing closed at each step:

1. Card token resolves in EUISIS
2. Card is valid (blocks expired / revoked / lost / replaced / suspended)
3. Employee status is active
4. EUISIS confirms service eligibility
5. No prior served transaction for that employee, provider and day

---

## 8. Security

- API token read from the environment only; never committed or stored in the DB.
- Cafeteria uses its own `cafeteria` guard — EUISIS credentials do not work here,
  and cafeteria accounts have no route into EUISIS admin.
- Only a minimal snapshot is persisted: employee number, name, organization,
  card status, eligibility result, timestamp.
- **Never stored:** national ID, phone, email, address, salary, documents.
- Scan endpoint is rate limited.
- Failed verifications are audited — they are the security signal.
- `cafeteria_api_logs` records metadata only; there is no body column.

---

## 9. Local schema

`cafeteria_providers` · `cafeteria_users` · `cafeteria_service_transactions` ·
`cafeteria_api_logs` · `cafeteria_settlements` · `audit_logs`

No foreign key points outside this database.

---

## 10. Tests

```bash
php artisan test
```

`tests/Feature/ServeEmployeeTest.php` covers the pipeline against a mocked
EUISIS API: active card served, inactive/revoked/lost/expired blocked, employee
inactive blocked, ineligible blocked, duplicate blocked, connection failure
fails closed, missing scope and revoked token fail closed, sensitive fields
never persisted, and API calls logged as metadata.

---

## 11. Blockers

1. **Laravel skeleton required.** This folder holds application code, not a
   bootable app. Follow Installation, or move it to its own repository — which
   is the recommended end state for a genuinely separate system.

2. **`POST /api/v1/service-transactions/verify-and-record` does not exist** in
   EUISIS. The client targets the existing
   `POST /api/v1/services/{serviceType}/transactions` instead. If you want the
   combined verify-and-record contract, it must be added to EUISIS first.

3. **`GET /api/v1/service-eligibility/check` does not exist** in that form.
   EUISIS exposes `GET /api/v1/employees/{employee}/service-eligibility`, which
   is what the client calls.

4. **Subsidy and pricing rules were not ported.** The reference module computes
   subsidy, weekly ledgers and payable splits from EUISIS-side rules. Those
   columns exist here and default to zero. Porting that logic needs a decision:
   either the cafeteria owns pricing, or EUISIS exposes it over the API.

5. **The existing EUISIS cafeteria module is untouched and still live.** This
   app does not disable or replace it. Decommissioning is a separate task.
