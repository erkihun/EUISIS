# Roadmap and scope freeze

- Status: in force from 2026-11-04, when the owner asked for the project review's
  recommendations to be applied. The order and the "Not planned" list are the
  implementer's proposals.
- Reviewed at the end of the pilot.

## Where the product stands

The modules under Modules/ and the core still in app/, 27 architecture decision
records, the automated test suite (six CI shards), both languages, a
3-million-employee capacity benchmark and the go-live gates are in place. The
next thing to learn comes from users, not from more code.

## Scope freeze until the pilot ends

**No new module and no new domain** is started until the pilot's end review.
Work during the pilot is limited to:

1. Defects found by pilot users, in the order the pilot sites report them.
2. Hardening that the production gates or the monitoring surface
   (docs/production-monitoring.md).
3. Translation and wording corrections from the Amharic review.
4. Documentation for the people using the system (docs/user-guide/am/).

A new idea goes into the "After the pilot" list below with one line of
justification, not into a branch. The person proposing it writes the line.

## The pilot

- Sites and sequence: the Amharic pilot concept note the System Owner holds
  (a shared document, not in this repository).
- Measurement: docs/pilot-measurement.md, `php artisan pilot:baseline`.
- Exit criteria: the baseline-versus-month-3 table per site, the defect
  register (docs/go-live/defect-register.md) at zero open severity-1
  defects, and the restore drill passed within the last 35 days.

## After the pilot, in this order

| # | Item | Why | Decision needed |
|---|---|---|---|
| 1 | **Fayda national ID integration** (identity verification at registration and card issuance) | Removes duplicate identity capture, strengthens the employee registry, aligns with the national digital ID policy. | Agreement with the National ID Program, API access, data-sharing terms under Proclamation 1321/2024 (ADR 0027). |
| 2 | Live SMS provider contract | The gateway exists (`HttpSmsGateway`, budget caps); production needs the Ethio Telecom or aggregator endpoint and the spend approval. | Provider, monthly cap. |
| 3 | AI gateway approval | Off by default (ADR 0027). An in-country or on-premises endpoint and the allowed data classes must be recorded before it is switched on. | Hosting location, data classes. |
| 4 | Finish the modular monolith migration | Core employees, organizations and the remaining `app/` services (Modules/MIGRATION.md resume point); then delete `Modules/legacy-aliases.php` and `Modules/legacy-class-map.php` when the ratchet reaches zero. | None; engineering time. |
| 5 | Retire the standalone repositories | core-platform, court-ms and cafeteria-platform are superseded by the core in app/, Modules/Court and Modules/Cafeteria (the owner's 2026-10-29 "focus on EUISIS" decision). Archive them on GitHub once the last read-only consumer is confirmed gone. | Confirmation that no environment still runs them. |
| 6 | Docker as a supported deployment | The compose stack works (2026-11-03) but production runs on hosts; keep it for evaluation only until an operator asks for it. | None until asked. |

## Not planned

- A mobile application. The public pages and My Portal are phone-sized web
  pages; the pilot will show whether anything more is needed.
- Payroll. Out of the mandate; integration points exist through the API.
- Any further standalone satellite application (2026-10-29 decision).

## How this page is kept

Every merge that adds a capability updates CHANGELOG.md. This page changes
only at a pilot review or by a System Owner decision recorded as an ADR.
