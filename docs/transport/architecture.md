# Transport Management — Architecture

Transport Management records **verified transport usage**: who boarded which provider's vehicle, on which route, trip and direction, proven by the employee's existing ID card (QR or NFC). Everything financial is derived from that evidence under an approved contract; nothing financial is typed in by a provider.

## Position in Addis One

| Concern | Owner | Transport reuses it as |
| --- | --- | --- |
| Employee identity | `employees`, `employee_assignments` | the only employee master; `employee_id` on every transport row |
| Card identity | `id_cards` (`public_card_uuid`, status) | the credential a scan resolves; the UUID never changes |
| Providers | `providers`, `provider_services`, `provider_users` | the transport provider and its portal accounts |
| Organization scope | `OrganizationScopeService` | every staff list, report and action |
| Audit | `WriteAuditLogAction` / `AuditEventType::Transport*` | 24 transport event types |
| Settings | `SystemSetting` (`group = transport`) via `TransportSettings` | the behaviour switches (never tariffs) |
| Feature flags | `config/features.php` | `transport`, `transport_nfc`, `transport_offline`, `transport_settlement`, `transport_gps` |
| Calendar | `PublicHolidayCalendar`, `CalendarService` | service days and Ethiopian dates in exports |
| Notifications | `TransportNotification` through `NotificationPresenter` | employee notices (route assigned, changed, suspended, trip cancelled) |

## Layers

```
Scan sources ─┬─ Provider portal terminal   (session, provider.transport.scan)
              ├─ Admin terminal              (session, transport-scan.create)
              └─ Device API /api/v1/transport (bearer credential, AuthenticateTransportDevice)
                               │
                 TransportBoardingService ─── one decision engine ───┐
                   CardCredentialResolver (QR hash / NFC)            │ evidence
                   TransportEligibilityService (pass / grant)        │
                   duplicate · capacity · trip authority · policies  │
                   BoardingLock (pg advisory) + partial unique index │
                               ▼                                     ▼
                     transport_transactions (append-only) ── transport_exceptions
                               │
        TransportSettlementService.basis() ── counts only from verified rows
                               │
        TransportSettlementCalculator ── contract snapshot × approved rates (integer cents)
                               │
        statement → review → approve (distinct approver) → paid; disputes/adjustments beside it
```

* **Controllers are thin**: validation in Form Requests (`app/Http/Requests/Transport`, `.../Api/Transport`, `.../ProviderPortal`), authorization in route middleware + request `authorize()`, work in services under `app/Services/Transport`.
* **One engine for every scan source.** The portal, the admin terminal, the device API and the offline sync build a `BoardingRequest` and call `TransportBoardingService::board()`. The decision (`BoardingDecision`) carries the result code, the transaction and any warnings.
* **Evidence is immutable.** A boarding is never edited; it is voided (`voided_at`, reason, actor) or adjusted on the statement. Refusals are recorded as evidence too.
* **Money is integer cents** (`TransportMoney`), formatted as `DECIMAL(14,2)` strings; the server never computes money in floating point and the browser never recomputes it.

## Key services

| Service | Responsibility |
| --- | --- |
| `Boarding\TransportBoardingService` | the decision pipeline, duplicates, capacity, exceptions, voids, replay by idempotency key |
| `Boarding\CardCredentialResolver` | QR value → stable card UUID (hash stored, value never); NFC reference → card |
| `TransportEligibilityService` | pass / employee grant / organization grant / pay-as-you-go / not required, alternative-route policy, snapshot |
| `TransportRouteAccessService` | which organizations a route serves (self / subtree / citywide) |
| `TransportTripService`, `TransportTripPlanner`, `TransportServiceCalendar` | lifecycle, dispatch, vehicle substitution, nightly planning from schedules |
| `TransportEligibilityReevaluator` | re-evaluation on transfer / status change (observer + queued job) |
| `TransportDeviceService` | registration, credential rotation, revocation, resolve by hashed bearer |
| `Settlement\*` | contracts (versioned, maker-checker), invoices, basis, calculator, statements, adjustments, reconciliation |
| `TransportReportService`, `TransportExportService` | reports, manifests, CSV/XLSX/PDF in EN/AM |
| `TransportDataQualityService` | master-data checks for the desk and Intelligence |
| `TransportSettings` | typed access to the `transport` settings group (memoised in cache) |

## Deployment shape

Same monolith, same queue. Nightly `transport:plan-trips --days=2` (00:20). Queued jobs: `ReevaluateTransportEligibility`, `NotifyTransportTripCancelled`. Telemetry probe `transport` in `TelemetryCollector`. PostgreSQL is the release target (partial unique indexes, advisory locks); SQLite runs the suite in CI.

## Out of scope by design

Attendance, payroll, discipline, employee GPS, a transport employee master, an accounting ledger, direct provider database access.
