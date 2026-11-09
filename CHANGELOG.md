# Changelog

All notable changes to Agelgay (አገልጋይ, formerly EUISIS / Addis One). Dates are
the merge dates on `main`. Versions are git tags; `v1.0.0-pilot` is the first
tagged release and marks the start of the scope freeze (docs/ROADMAP.md).

## [1.0.0-pilot] — 2026-11-04

The pilot release. Everything below this heading is in it.

### Added in this release

- Application error tracking through a Sentry-compatible log channel
  (`error_tracker`, GlitchTip recommended) and the production monitoring page.
- `pilot:baseline`: the pilot indicators for a period, per institution, saved as
  snapshots (docs/pilot-measurement.md).
- ADR 0027: personal data protection under Proclamation 1321/2024, with the
  data subject request and breach notification runbook.
- Amharic user guides for every module (docs/user-guide/am/).
- CI runs the suite as four shards on PHP 8.4 and 8.5.
- Roadmap with the scope freeze and the post-pilot order (Fayda first).

### Changed

- Point-in-time security and assessment reports moved to docs/archive; one
  living security index at docs/security/README.md.
- docs/runbook merged into docs/runbooks.

## 2026-11 — Situation Room completion, Proclamation 94/2019 data

- Situation Room: every module covered by 26 categories; wall animations;
  refreshed briefing, category and wall screens.
- Organization design seeded from Proclamation 94/2019: 71 bodies with Amharic
  codes, mandates, functions, classifications, a snapshot per version.
- ISIC Rev.4 and ISCO-08 complete in both languages; an occupation for every
  ISCO-08 unit group.
- Default role catalog complete (ADR 0026): 105 roles, every permission reachable
  through a working role; Amharic role names.
- Reporting lines (ADR 0025): one reporting model, supervisor resolution,
  acting appointments and bounded delegations.
- ID cards: configurable templates, bulk printing and a print station
  (ADR 0021); the digital ID prints no issue or expiry date.
- Docker Compose stack for the whole application.

## 2026-10-31 — Court module, code rules, strategy hierarchy

- court-ms converted to Modules/Court (ADR 0020): filing from My Portal with
  co-applicants and lawyers, cases against an institution reach its officer,
  appeals, letters, record PDFs, notices in the page's language.
- Every hand-built record numbered through code rules (ADR 0023).
- Strategy hierarchy entered the WLMS way (ADR 0019): main strategies, goals,
  plans; daily work linked to activities.
- Service Monitoring module: form builder, institution submissions, review,
  sub-city and city consolidation, verification, visits, action items.
- Reception desks module; redesigned Messages workspace.
- Modular monolith (ADR 0017): Transport, Cafeteria, Field work, Daily work,
  Communication, Performance, Assessment, Discipline, Feedback, Transfer and
  Recruitment moved behind public contracts with table ownership.

## 2026-10-24 to 2026-10-29 — Core platform and go-live tooling

- Master backbone contracts (ADR 0002), Core workspace stages 1–8: occupancy
  ledger, governed hierarchy, verified-employee stage, MFA for privileged
  roles, Integration Center, signed webhooks, versioned API, break-glass
  access, audit seals, data-quality lifecycle, System Health, OpenAPI.
- Go-live gates, validated recovery targets, retention matrix (ADR 0004);
  strict production review, probes, release, backup and restore-drill tooling
  (ADR 0012); UAT suite and dataset.
- Organization registration production rules (ADR 0022).
- Public feedback throttle and service requirement (ADR 0024).

## 2026-10-19 to 2026-10-22 — Intelligence, Transport, branding

- Addis One Intelligence: system health, telemetry, detection and incidents;
  business intelligence areas; AI gateway with redaction, knowledge assistant,
  root-cause assistant, alerts, forecasts and governance.
- Assistant (global help desk with support tickets) and public-site Help.
- Verified transport usage: trips, devices, one boarding engine, settlement,
  provider portal and analytics.
- Organization design: legal source register, legacy baselines, staged imports,
  organogram history, as-of and compare.
- Product renamed to Agelgay · አገልጋይ (2026-10-21); Tailwind 4; redesigned
  sign-in and profile.

## 2026-10-10 to 2026-10-14 — Public feedback, grievance statute, organization design

- Public feedback: unified target model, organization QR codes, identity modes,
  routing rules, categories, tip-offs with media evidence, dashboards, reports
  and exports; QR pages as phone-app screens.
- Grievance module aligned to Regulation 201/2018: statutory filing and
  committee deadlines snapshotted, committee composition enforced,
  force-majeure review, initial-resolution precondition.
- Organization design phases 1–10: mandates, service catalog, processes,
  occupations and competencies, design studies, structure validation,
  workforce planning, position profiles and job descriptions, traceability,
  mapping and data quality; production gates A and B.
- Health probes `/health/live` and `/health/ready`; shared object storage;
  3-million-employee capacity gate with k6 scenarios.
- Field Work Management module.

## 2026-06 to 2026-08 — Foundations

- Organizations, organization units, institution offices and relationships;
  hierarchy versions, tree UI, Excel structure import.
- Employees, positions, ID cards with a stable verification QR, Ethiopic PDF
  fonts, Ethiopian calendar.
- Grievance and administrative tribunal module (first version); employee
  service eligibility across service modules.
- Soft deletes with a recycle bin and audited permanent deletion; role
  assignment and privilege-escalation guards; audit events.
- Localized admin sign-in; display settings and exports.

[1.0.0-pilot]: https://github.com/erkihun/EUISIS/releases/tag/v1.0.0-pilot
