# Addis One Intelligence — alerts, notifications and forecasts

Alerts are the *deduplicated, human-acknowledged* face of two signal sources:
technical incidents and city-wide business insights. Forecast snapshots are
the stored, later-evaluated predictions of the Forecasting area. Both run on
the scheduler and need no AI.

## Alert engine (`App\Services\Intelligence\Alerts\AlertEngine`)

Schedule: `intelligence:alerts` hourly (`routes/console.php`), one server,
no overlap.

| Source | Candidate | `source_key` | Domain |
| --- | --- | --- | --- |
| Active incidents (`open`, `investigating`, `mitigated`) | one per incident | `incident:<fingerprint>` | technical |
| Business insights of severity **medium or above** from Workforce, Public Voice, Performance and Data Quality, computed city-wide | one per insight key | `insight:<area>:<key>` | business |

Rules (brief §65–66):

- **One alert per condition.** A candidate whose `source_key` already has an
  active alert (`open` or `acknowledged`) raises `occurrences`, refreshes
  `last_detected_at`, evidence and parameters, and may raise severity; it
  never opens a second alert.
- **Clear runs.** A condition absent from a run counts one clear run; after
  `CLEAR_RUNS = 3` the alert resolves itself with `cooldown_until = now + 60
  minutes`. A resolved condition that reappears inside the cooldown is
  ignored; after it, a new alert opens.
- **Acknowledged alerts** still count occurrences and clear runs; resolving
  by hand sets a 24-hour cooldown; reopening clears it.
- **Not evaluated ≠ cleared.** If the business analyses could not run (no
  acting account, or an area threw), their alerts are left untouched and the
  command says so (`skipped_business`, `failed_areas`). A failing area never
  stops the others.

### Acting account for scheduled business analyses

The business analyses check grants and scope for a user. The platform has no
system account, so scheduled runs act as the **first active Super Admin**
(`SystemContext::actor()`) with an unrestricted scope (`SystemContext::cityWide()`).
That account must hold the catalog grants itself (the RBAC synchroniser gives
Super Admin all of them): `PublicFeedbackVisibility` checks target-type grants
directly, not through `Gate::before`. No Super Admin → business alerts are
skipped and reported; nothing is guessed. Documented as decision D-12.

### Visibility and actions (`AlertController`)

| Domain | May see | May acknowledge / resolve |
| --- | --- | --- |
| technical | `intelligence.incidents.view` or `intelligence.system_health.view` | `intelligence.incidents.manage` |
| business | any business area grant (except knowledge) **and** an unrestricted scope — the figures are city-wide | `intelligence.executive.view` |

Every acknowledge / resolve / reopen is audited (`IntelligenceAlertUpdated`,
before and after status, the action). The engine never acknowledges.

### Notifications (`AlertNotifier`, `IntelligenceAlertNotification`)

- Business alerts only (technical ones are already announced as incidents by
  `IncidentNotifier`).
- Recipients: active users holding `intelligence.executive.view` with an
  unrestricted scope.
- Severity at or above `notify_min_severity` (System Settings › Intelligence;
  `none` switches it off).
- At most once per alert per 24 hours; channels follow the Notifications
  settings (database always when enabled, mail when enabled and the user has an
  address). Sent after commit on the `notifications` queue.
- The message carries severity, area and a link — no figures, no evidence.
- Escalation (to whom, after how long, which channel) is
  `NEEDS_POLICY_DECISION_ALERT_ESCALATION`; nothing escalates today.

## Forecast snapshots (`ForecastSnapshotService`, `intelligence:forecast`)

Schedule: `intelligence:forecast --evaluate` daily at 04:10 while
`forecasting_enabled` is on; off → the command exits saying so and writes
nothing.

Each run writes, per metric (`complaints_monthly`, `vacancies_monthly`) and
per forecast point (three months ahead):

| Column | Meaning |
| --- | --- |
| `snapshot_id` | one id per run; earlier snapshots are never overwritten |
| `period` | target month `YYYY-MM` |
| `value`, `lower`, `upper` | prediction and band from `Forecaster::linear` |
| `method`, `model_version` | `linear` / `linear-v1`; a new method gets a new version |
| `confidence`, `points`, `r2` | from the fit |
| `assumptions`, `limitations` | window, data through, series start; the fit's stated limitations |
| `data_through`, `generated_at` | what the prediction knew and when |

Evaluation (`--evaluate`) fills `actual`, `abs_error`, `pct_error`,
`evaluated_at` for predicted months that have passed and have an actual in the
series; months with no data stay unevaluated (UNKNOWN beats a fake zero).
`quality()` summarises **MAE** and **MAPE** per metric over evaluated points;
the Governance page shows it. Under six monthly points the metric is reported
as insufficient and skipped (brief §72: no model before there is data).

Scope: city-wide (`scope_key = all`). Per-organization snapshots are not
written; the Forecasting page computes within the viewer's scope on demand.

## Tests

`tests/Feature/Intelligence/AlertsTest.php` (dedupe, occurrences, clear runs,
cooldown, business run, page visibility, audited acknowledge, cross-domain
403), `tests/Feature/Intelligence/GovernanceAndForecastTest.php` (snapshot
rows, evaluation, MAE/MAPE, snapshot immutability).
