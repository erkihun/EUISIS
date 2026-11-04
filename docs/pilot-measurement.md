# Measuring the pilot

A pilot that ends with "it went well" has taught nothing. This page fixes the
indicators, the baseline and the comparison before the first institution signs
in, so the decision to roll out rests on numbers (ADR 0027 for the data rules; the
System Owner's pilot concept note for the sites).

## The indicators

`php artisan pilot:baseline` computes them for a period, for the whole city or
for one institution:

| Indicator | Meaning | Direction |
|---|---|---|
| `employees_registered` | Employees whose record was created in the period | up during onboarding, then flat |
| `grievances_submitted` | Grievances with a submission date in the period | neutral: more means trust, not more problems |
| `grievances_resolved` | Grievances resolved in the period | up |
| `grievance_median_resolution_days` | Median days from submission to resolution, for cases resolved in the period | **down** |
| `grievances_open_over_30_days` | Open cases older than 30 days at the period's end | **down** |
| `public_feedback_submitted` | Public feedback, suggestions and complaints received | up: the QR channel is being used |
| `public_feedback_average_rating` | Mean service rating given by the public | up |
| `id_cards_issued` | ID cards issued in the period | up during onboarding |
| `mfa_challenge_failures` | Failed MFA challenges (sign-in friction and attack attempts) | down after training |

Two of these are the headline figures for leadership: median grievance
resolution days and public feedback volume. The others explain them.

## The procedure

1. **Baseline, before go-live at the site.** The previous 90 days, from the
   paper or legacy records where the system was not yet in use. Where the
   legacy figure does not exist, record "no baseline" rather than an estimate.

   ```bash
   php artisan pilot:baseline --from=2026-08-01 --to=2026-10-31 --organization=<id> --save
   ```

2. **Every four weeks during the pilot**, the same command for the elapsed
   period, saved. Snapshots land in `storage/app/private/pilot/` and are copied
   to the pilot folder on the shared drive.

   ```bash
   php artisan pilot:baseline --to=2026-12-31 --organization=<id> --save
   ```

3. **At the end**, one table per site: baseline, month 1, month 2, month 3.
   The go/no-go memo quotes that table and nothing else as the outcome.

## What the numbers do not say

- A rising grievance count in month 1 is expected: cases that lived in drawers
  are being entered. Judge resolution time, not volume.
- Public feedback depends on QR codes being printed and displayed. A site with
  zero feedback has a display problem before it has a service problem; check
  Public feedback › Organization QR codes first.
- MFA failures spike in the first week of every new role group. Compare week 4
  with week 1, not week 1 with the baseline.

## Alongside the numbers

Two short structured interviews per site at week 2 and week 10 (an HR officer,
a grievance officer), using the questions in docs/go-live/uat-plan.md. They
explain the numbers; they do not replace them.
