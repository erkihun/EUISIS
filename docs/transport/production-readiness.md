# Transport Management — Production Readiness and Handoff

Verdict: **CONDITIONAL_TRANSPORT_MANAGEMENT_PRODUCTION_READY** — ready for a pilot (one organization, one provider, a few routes and vehicles) once the deployment items below are done; city-wide expansion after the pilot's reconciliation UAT and the staging load test. Policy decisions (tariffs, offline, retention, accounting hand-off) are marked, never invented.

## Handoff (the 65 items of the brief)

| # | Item | State |
| --- | --- | --- |
| 1 | Existing Transport code found | Providers, routes, vehicles, drivers, trips, passes, transactions, portal, scan, reports, settings (`docs/transport-provider-module.md`); reused, extended additively, legacy trip statuses migrated (`scheduled→planned`, `departed→in_progress`). |
| 2 | Domains reused | Employee, EmployeeAssignment, IdCard (stable `public_card_uuid`), Provider/ProviderUser, OrganizationScope, Audit, SystemSetting, Features, PublicHolidayCalendar, CalendarService, notifications, telemetry, Situation Room, Intelligence. |
| 3 | Domains created | Stops, schedules, service calendar, trip direction/lifecycle/vehicle changes/positions, devices, eligibility grants, exceptions, contracts, invoices, statements, adjustments. |
| 4 | Provider architecture | `providers` + `provider_services(transport)` + `transport_provider_profiles`; portal keys per user; isolation in every query and request. |
| 5 | Contract architecture | Versioned `transport_contracts`, maker-checker approval, snapshot on statements, required-rate validation per model. |
| 6 | Route | Provider route serving self / subtree / citywide. |
| 7 | Stops | Ordered, bilingual, optional coordinates, pickup/drop-off, soft-deleted. |
| 8 | Schedule | Per route and direction, service days, holiday behaviour, nightly planner `transport:plan-trips`. |
| 9 | Trip | planned → assigned → boarding → in_progress → completed / cancelled / failed; manifests; extra trips approved by staff. |
| 10 | Morning/evening direction | `inbound` / `outbound` on schedules, trips and boardings; distinct trips. |
| 11 | Vehicle | Approved capacity bounds boarding; availability; substitution recorded. |
| 12 | Driver | Assigned per trip; documents checked by data quality. |
| 13 | Device registry | Hashed bearer credential shown once, rotate/revoke, vehicle binding, heartbeat, silence detection. |
| 14 | Employee eligibility | Grants per employee or organization (self/subtree), authorization reference, lifecycle, re-evaluation. |
| 15 | Route assignment | Pass = route + boarding/evening stop + validity; suspended with reason on transfer/separation. |
| 16 | QR verification | Stable card URL → UUID; hash stored; one engine. |
| 17 | NFC verification | Same engine behind `transport_nfc` + `nfc_enabled`; `NfcCredentialService`. |
| 18 | Boarding transaction | Append-only evidence with trip, route, vehicle, direction, stop, device, method, snapshot; refusals recorded. |
| 19 | Duplicate protection | Idempotency key / nonce replay, per-trip partial unique index, per-direction-per-day policy, advisory lock (PostgreSQL). |
| 20 | Capacity controls | Effective capacity; block or warn; overcapacity exceptions; 52-on-50 test. |
| 21 | Trip manifest | Two tiers, PDF EN/AM, voided rows kept. |
| 22 | Offline architecture | Policy-gated sync, re-verification per item, grace window, sync states, settlement flag. |
| 23 | GPS support | Vehicle positions behind `transport_gps`, retention setting; never employee tracking. |
| 24 | Provider portal | Terminal, trips/dispatch, manifests, exceptions, devices, invoices, statements, disputes. |
| 25 | Dispatch | Admin, portal, terminal and device API; audit per action. |
| 26 | Employee My Portal integration | Route, stops, today's trips, morning/evening rides, recent boardings with reasons, privacy note. |
| 27 | Reports | Boardings, daily usage, route/vehicle utilization, provider delivery; CSV/XLSX; scoped. |
| 28 | Route analytics | Assigned vs riders, seats vs used, no-show rate, by direction. |
| 29 | Vehicle analytics | Trips, passengers, seat-trips, utilization, days operated, distance. |
| 30 | Usage analytics | Boardings ≠ unique riders ≠ employee-days ≠ trips, per day and per organization. |
| 31 | Contract/tariff versioning | Immutable approved versions; snapshot per statement. |
| 32 | Invoice | Provider claim lifecycle with attachment; never a verified figure. |
| 33 | Reconciliation | Claimed vs verified vs calculated vs adjusted vs approved; 100-employee fixture. |
| 34 | Settlement | Basis from evidence, model-driven lines, maker-checker, payment record, PDF. |
| 35 | Dispute/adjustment | Signed, evidenced, decided by a second person; evidence untouched. |
| 36 | Financial DECIMAL handling | Integer cents (`TransportMoney`), `DECIMAL(14,2)`, no floats, browser never recomputes. |
| 37 | Audit | 24 `AuditEventType::Transport*` events with reasons and request context. |
| 38 | OrganizationScope | Staff lists, reports, exports, voids, statements, Situation Room. |
| 39 | Provider isolation | Portal, device API, requests; tested across providers. |
| 40 | Device security | Hashed credentials, rotation, revocation, binding, throttling. |
| 41 | Privacy | Tiered data, hashed QR, no PII on tags, no employee GPS, retention flagged. |
| 42 | Events/integration | Audit, notifications, jobs, scheduler, telemetry; no outbox exists (noted). |
| 43 | Situation Room | `transport` category (grant `situation_room.transport.view`, follows the module flag). |
| 44 | AI Intelligence | Data-quality panel `quality_transport` with 12 checks. |
| 45 | System health | `transport` component: active/silent devices, offline backlog and age, stale trips, boardings/refusals per 15 min. |
| 46 | AM/EN | Every key in both locales; `npm run i18n:audit --strict` passes; PHP and TS reason vocabularies aligned. |
| 47 | Calendar | Ethiopian dates in exports and pages (`CalendarService`), public holidays in planning. |
| 48 | Mobile scanner UX | Continuous camera, keyboard wedge, audio/vibration, auto-reset, occupancy, trip activation, manual exception. |
| 49 | Security test | `TransportSecurityTest`, admin/portal/device API tests. |
| 50 | Concurrency test | DB-level duplicate refusal on every driver; PostgreSQL index + advisory lock test on the release gate (`phpunit.pgsql.xml.dist`). |
| 51 | Offline/replay test | `TransportDeviceApiTest` (disabled, enabled, stale, replayed batch). |
| 52 | Load test | `loadtest/k6/transport-boarding.js` + `transport:seed-loadtest` (synthetic only); not executed in this sandbox (no load generator / staging). |
| 53 | Morning UAT | Script in `uat.md`; automated equivalent in scenarios A, C, D, E. |
| 54 | Evening UAT | Script in `uat.md`; automated equivalent in scenarios A, H and trip lifecycle tests. |
| 55 | Reconciliation UAT | Script in `uat.md`; automated equivalent in scenarios F, G and the 100-employee fixture. |
| 56 | Automated tests | 141 transport tests (1 PostgreSQL-only), Situation Room and Intelligence suites green. |
| 57 | Full suite | 4,463 tests: 4,318 passed, 2 skipped, 143 failed in this sandbox — 117 are `Call to undefined function bccomp/bcadd` (the `bcmath` extension CI installs is absent here) and 25 are the same assessment/daily-activity flows failing downstream of it; the remaining one (`DesignImportTest`, 527 ≠ 528 rows) fails on `main`'s own CI (run 93). No transport, Situation Room, Intelligence or Integration test failed; those suites pass in full (413 + 1 PostgreSQL-only skip). |
| 58 | Build | `npm run typecheck` clean; `npm run build` succeeds; `pint --test --dirty` clean; `composer audit` and `npm audit` report no advisories. |
| 59 | Remaining P0 | None known for the pilot scope. |
| 60 | Remaining P1 | Run the migrations on PostgreSQL in staging (partial indexes build `CONCURRENTLY`; `NEEDS_DATA_REVIEW` guard aborts on existing duplicates); run the k6 morning profile; a documented accounting hand-off for approved statements. |
| 61 | Remaining P2 | Device heartbeat alerting through incidents; GPS map; NFC pilot once tags are issued; export of statements to the finance system. |
| 62 | NEEDS_POLICY_DECISION | `NEEDS_POLICY_DECISION_TRANSPORT_TARIFF` (rates per contract), `NEEDS_POLICY_DECISION_TRANSPORT_SETTLEMENT_MODEL` (`other_approved_model`), `NEEDS_POLICY_DECISION_OFFLINE_BOARDING` (enable, grace), `NEEDS_POLICY_DECISION_TRANSPORT_RETENTION` (boarding evidence, GPS), `NEEDS_POLICY_DECISION_TRANSPORT_ACCOUNTING_EXPORT`. |
| 63 | NEEDS_LANGUAGE_REVIEW | New Amharic strings in `resources/js/i18n/am/transport.ts` (verified-usage block), `lang/am/transport.php` (settings, reasons, statement), `lang/am/situation_room.php` (transport), `lang/am/intelligence_business.php` (transport checks), navigation and permission labels — machine-authored by the engineer, audit-clean, to be read by a native reviewer before the pilot. |
| 64 | NEEDS_DEPLOYMENT_CONFIGURATION | `FEATURE_TRANSPORT_*` flags per stage; `transport:plan-trips` in the scheduler host; queue worker for re-evaluation/notification jobs; PostgreSQL migration window; device credentials issued per bus; `TRANSPORT_*` rate limits reviewed; `bcmath` (used by the cafeteria suite) installed on CI/prod images. |
| 65 | Verdict | **CONDITIONAL_TRANSPORT_MANAGEMENT_PRODUCTION_READY** |

## Pilot rollout
1. Flags: `FEATURE_TRANSPORT=true`, `FEATURE_TRANSPORT_SETTLEMENT=true`, NFC/offline/GPS off.
2. One organization grant (subtree), one provider with a contract version approved by a second person, 2–4 routes with stops and schedules, vehicles with approved capacity, one device per bus.
3. Week 1: boardings only; week 2: first invoice and statement; UAT scripts in `uat.md`.
4. Expand by organization and provider; keep `capacity_enforcement = block` unless policy says otherwise.

## Verification record (this environment)
* Transport suites: 141 passed, 1 skipped (PostgreSQL only).
* Situation Room + Intelligence suites: 199 passed.
* Full suite (in-process, `opcache.enable_cli=1`, 46 min): 4,318 passed / 2 skipped / 143 failed, all `bcmath`-dependent suites or `main`'s pre-existing `DesignImportTest` failure; see row 57. `main`'s CI baseline (run 93) is itself red on `AdminDashboardExperienceTest`, `TelemetryTest` (collector flush) and `DesignImportTest`.
* `npm run typecheck`, `npm run build`, `npm run i18n:audit` (strict), `composer audit --locked`, `npm audit`: clean.
* `php artisan migrate:status`: 275 ran, 0 pending (SQLite); PostgreSQL run pending in staging.
* Routes: 94 admin `transport.*`, 47 portal `provider.portal.transport.*`, 10 device API `api.transport.*`.
