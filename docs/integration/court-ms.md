# court-ms integration (tribunal case system)

Decision record: [ADR 0013](../architecture/adr/0013-court-ms-integration.md). The court-ms
side is documented in that repository's `docs/euisis-integration.md`.

Three flows, each system pulling from the other:

| Flow | Direction | What moves | Runs |
| --- | --- | --- | --- |
| 1 Master data | court-ms reads EUISIS | Organizations, units, positions of linked people, employees who are parties | `euisis:sync` in court-ms, every 5 minutes |
| 2 Referral | EUISIS sends to court-ms | A tribunal case an officer refers: case type, title, summary, parties, chosen PDFs | Queued job `SendCourtMsReferral` (`integrations` queue) |
| 3 Status | EUISIS reads court-ms | Intake review and reason, case status, hearing date, decision date and summary | `court-ms:poll` in EUISIS, every 10 minutes |
| 4 Employee portal | EUISIS reads court-ms, then sends the employee there | An employee's court cases, next hearings and unread notices; a one-time link that signs them into the court portal | My Portal > My court cases, when the employee opens it |

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

### 2. Issue the EUISIS token in court-ms (Flows 2 to 5)

On the court-ms server:

```bash
php artisan euisis:integration-token --ip=<EUISIS server IP>
```

It prints the token once. Rotate with the same command; revoke with `--revoke`. A token
issued before Flows 4 and 5 gains their abilities (`people:read`, `people:sign-in`,
`people:file`, `letters:read`) without changing with
`php artisan euisis:integration-token --refresh-abilities`. With court-ms's
`EUISIS_PARTIES_ONLY` on (the default with its EUISIS connection), cases can no longer be
started in the court portal: employees file here (Flow 5).

### 3. Configure EUISIS

```env
COURT_MS_ENABLED=true
COURT_MS_URL=https://court.example.gov.et
COURT_MS_TOKEN=cms_...
# Optional: grievance category code → court-ms case type id, pre-selected on the form
COURT_MS_CASE_TYPE_MAP={"TRANSFER":4}
COURT_MS_DEFAULT_CASE_TYPE_ID=
# Flow 4: My Portal > My court cases. COURT_MS_PUBLIC_URL when browsers reach court-ms
# at another address than COURT_MS_URL; sign-in links are followed only to that host.
COURT_MS_EMPLOYEE_PORTAL=true
COURT_MS_PUBLIC_URL=
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

- **Employee portal (Flow 4):** applicants at the tribunal are city employees. My Portal >
  My court cases lists the employee's court cases (status, review, next hearing) and
  unread notices, read from court-ms and cached for a minute. *Open* and *Open the court
  portal* ask court-ms for a one-time link (two minutes, used once)
  and open it in a new tab, signed in as the employee. The first time, court-ms opens
  the employee's court account from their record (national ID, phone, email and gender
  are required), or links the one that has their national ID; it never takes over an account
  linked to another employee. court-ms stays the system of record; EUISIS stores no case.
- **Filing a case (Flow 5):** employees file here, not in court-ms. *File a new case* opens
  My Portal > My court cases > File a case: case type (from court-ms), title, what
  happened, relief, the respondent institution (only active institutions registered here;
  the employee's own is pre-selected), the grievances it concerns (their own, submitted
  ones) and more PDFs. Each chosen grievance goes with its facts, the letters issued to
  the employee (as responses) and the issued decisions, as plain text, and its PDFs: those
  letters and the evidence the employee submitted (at most 20 PDFs of 10 MB). court-ms
  starts the case in intake review; the form id makes a second send file it once. An
  account with no employee record cannot file.
- **Court letters (Flow 5):** when the tribunal approves a letter on a case against an
  institution registered here, `court-ms:poll` receives it with its PDF
  (`court_ms_letters`, PDFs on the `local` disk under `court-ms/letters/`) and notifies
  the institution's tribunal officers (`grievances.tribunal` within that institution).
  They read it under Tribunal Cases > Court letters; people with `court_cases.view` or a
  city-wide scope see every institution's letters. Opening the PDF marks it read. A
  letter whose PDF cannot be read stops the run there; the next poll starts from it.

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
| No *My court cases* in My Portal | `COURT_MS_EMPLOYEE_PORTAL=true` with the three connection values, and the account linked to an employee record. |
| My court cases says court-ms cannot be reached | court-ms reachable; the token holds `people:read` (`--refresh-abilities`). |
| Opening or filing says the employee record is incomplete | The employee needs a national ID, phone, email and gender (male or female) before a court account can be opened. |
| File a case says the court system cannot be reached | court-ms reachable; the token holds `people:file` and `referrals:read` (case types). |
| The poll fails with "court-ms letters" | The token holds `letters:read` (`--refresh-abilities` in court-ms); a `503` on a letter's PDF is retried on the next poll. |
| No court letters for an institution | Its tribunal officer holds `grievances.tribunal` with a scope row for that institution; the letter was approved with "send to respondent" on a case whose respondent is that institution. |

## Tests

`tests/Feature/Grievances/CourtMsIntegrationTest.php`,
`tests/Feature/Integration/CourtMsEmployeePortalTest.php` and
`tests/Feature/Integration/CourtMsEmployeeFilingTest.php` (EUISIS) and
`tests/Feature/Euisis/*` (court-ms) cover access, idempotency, failure handling, the
sync, the update list and the status mapping.
