# court-ms integration (tribunal case system)

Decision record: [ADR 0013](../architecture/adr/0013-court-ms-integration.md). The court-ms
side is documented in that repository's `docs/euisis-integration.md`.

Three flows, each system pulling from the other:

| Flow | Direction | What moves | Runs |
| --- | --- | --- | --- |
| 1 Master data | court-ms reads EUISIS | Organizations, units, positions of linked people, employees who are parties | `euisis:sync` in court-ms, every 5 minutes |
| 2 Referral | EUISIS sends to court-ms | A tribunal case an officer refers: case type, title, summary, parties, chosen PDFs | Queued job `SendCourtMsReferral` (`integrations` queue) |
| 3 Status | EUISIS reads court-ms | Intake review and reason, case status, hearing date, decision date and summary | `court-ms:poll` in EUISIS, every 10 minutes |

## Set up

Do it on a test pair first.

### 1. Register court-ms in EUISIS (Flow 1)

System Settings → API Management → new application, for example code `COURT_MS`:

- organization access: city-wide;
- allowed IPs: the court-ms server;
- scopes: `organizations.read`, `organization_units.read`, `positions.read`,
  `employees.basic_read`, `employee_assignments.read`, `changes.read`;
- endpoints: `GET /api/v1/changes`, `GET /api/v1/changes/watermark`,
  `GET /api/v1/organizations/directory`, `GET /api/v1/organizations/{organization}`,
  `GET /api/v1/organizations/{organization}/units`,
  `GET /api/v1/organizations/{organization}/positions`, `GET /api/v1/employees`,
  `GET /api/v1/employees/{employee}`.

Issue a token (shown once) and hand it to the court-ms administrator for `EUISIS_TOKEN`.
The change feed must be on (`INTEGRATION_CHANGE_FEED_ENABLED=true`).

### 2. Issue the EUISIS token in court-ms (Flows 2 and 3)

On the court-ms server:

```bash
php artisan euisis:integration-token --ip=<EUISIS server IP>
```

It prints the token once. Rotate with the same command; revoke with `--revoke`.

### 3. Configure EUISIS

```env
COURT_MS_ENABLED=true
COURT_MS_URL=https://court.example.gov.et
COURT_MS_TOKEN=cms_...
# Optional: grievance category code → court-ms case type id, pre-selected on the form
COURT_MS_CASE_TYPE_MAP={"TRANSFER":4}
COURT_MS_DEFAULT_CASE_TYPE_ID=
```

Run `php artisan migrate`, make sure a queue worker serves `integrations`, and that the
scheduler runs (`court-ms:poll` every ten minutes). `php artisan config:cache` after any
change to these values.

### 4. Configure court-ms

```env
EUISIS_ENABLED=true
EUISIS_URL=https://euisis.example.gov.et
EUISIS_TOKEN=<token from step 1>
```

Run `php artisan migrate`, then `php artisan euisis:sync` once (it bootstraps the
organization cache), and make sure its scheduler runs.

## Using it

- **Referral:** open the tribunal case (Grievances → Tribunal Cases). The "Tribunal court
  (court-ms)" panel shows the referral form to an officer who handles the case. The
  officer checks every field; only what is on the form is sent. The state reads
  *Sending*, then *Sent to the tribunal* with the court-ms case number (a link into
  court-ms for staff who have an account there), or *Not sent* with the reason and a
  *Send again* button.
- **Status:** every ten minutes the panel and the register follow court-ms. The register
  moves open → hearing → decided → closed and never backwards. A return or rejection
  shows the tribunal's reason. The grievance history records each change; returns,
  hearings and decisions notify the people on the tribunal case.

## Status mapping

| court-ms | EUISIS register | Also set |
| --- | --- | --- |
| review `awaiting_review` | open | — |
| review `returned` or `rejected` | open (court review state shows the return) | reason, notification |
| review `accepted` and a hearing | hearing | `hearing_date`, notification |
| a published decision | decided | `decision_date`, `decision_summary`, notification |
| case `closed` or `dismissed` | closed | — |

## Troubleshooting

| Symptom | Check |
| --- | --- |
| No referral form | `COURT_MS_ENABLED`, `COURT_MS_URL`, `COURT_MS_TOKEN` set and config cached; the user holds `grievances.tribunal` and handles the case's current stage. |
| "The case types could not be read" | court-ms reachable from EUISIS; token valid (`401` = wrong token, `403 ip_not_allowed` = add the EUISIS IP with `euisis:integration-token --ip=`). Case types are cached ten minutes. |
| Stays *Sending* | A worker on the `integrations` queue; `failed_jobs`; the panel shows the attempt count. |
| *Not sent* with a 422 | court-ms refused the content (usually the case type). Correct and send again. |
| Status never changes | `php artisan court-ms:poll` by hand; the panel shows the last poll error. |

## Tests

`tests/Feature/Grievances/CourtMsIntegrationTest.php` (EUISIS) and
`tests/Feature/Euisis/*` (court-ms) cover access, idempotency, failure handling, the
sync, the update list and the status mapping.
