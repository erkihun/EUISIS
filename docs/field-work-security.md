# Field Work Security and Privacy

## Permissions

The module has granular permissions in group `field_work`, under the
*Field Work Management* category. No single permission covers the whole
module.

| Duty | Permissions | Default roles |
|---|---|---|
| Own | `view_own`, `create_own`, `create_team`, `edit_own_draft`, `submit_own`, `cancel_own`, `check_in`, `check_out`, `complete` | Employee |
| Supervisor | `view_team`, `approve`, `return`, `reject` | Daily Activity Reviewer, Performance Manager |
| Oversight | `view_org` | HR Officer, Organizational Admin |
| GPS status | `location.view_status` | all of the above |
| Exact GPS | `location.view_precise` | Super Admin, System Admin only. **Withheld from City Admin and Public Service Bureau Admin** |
| Config | `manage_types` | Super Admin, System Admin, City Admin, Public Service Bureau Admin |
| GPS policy | `system-settings.manageFieldWorkGps` (System Settings) | Super Admin, System Admin. **Withheld from City Admin** |

A permission is necessary but not enough on its own:

- **own:** the user's linked employee must be a participant on the record (the
  requester is the lead participant).
- **team:** the user must be the request's resolved supervisor, or a
  line-manager assignment must cover a participant's snapshot placement.
- **oversight:** `OrganizationScopeService` must cover the request's *source*
  organization. Institution HR therefore sees its own organization and
  subtree; city-wide view needs an unrestricted role or scope. The
  *destination* organization gains no access: hosting a visit does not open
  the visitor's HR record.
- **decide:** the user must be the *live-resolved* immediate supervisor and
  must not take part in the request.

## Defence in depth

1. Route middleware: `auth` and `admin.access` on every route, plus
   `verified`, `mfa` and `force.password` on management routes. Mutating
   routes are throttled.
2. Form Requests validate shape. The decision request authorises against the
   record before it validates.
3. `FieldWorkRequestPolicy` checks every record action. A request id the user
   is not entitled to gets a 403, which closes IDOR on request, participant
   and event ids.
4. `FieldWorkService` re-checks requester, supervisor and participant
   identity, because `Gate::before` lets Super Admin through every policy.
   Super Admin therefore cannot approve a request unless they are its
   resolved supervisor.

### Forged input

| Attempt | Result |
|---|---|
| `employee_id`, `requester_employee_id`, `employee_assignment_id`, `organization_id` in the payload | Ignored. These are not fillable and are set from the signed-in user. |
| `status`, `supervisor_user_id`, `decided_by` | Ignored. Only `FieldWorkService` sets them, with `forceFill`. |
| `validation_status` or `distance_m` sent with a GPS reading | Ignored. The geofence is computed on the server. |
| A participant from another organization, an inactive one, or a duplicate | Refused with a validation error |
| A destination unit outside the chosen organization | Refused with a validation error |

## GPS privacy

- **Browser policy.** Before this module, `SecurityHeaders` sent
  `Permissions-Policy: geolocation=()`, which disables the Geolocation API on
  every page, so GPS check-in could not have worked. It is now
  `geolocation=(self)`: only same-origin scripts may *ask*, and the browser's
  own permission prompt remains the consent gate. Third-party frames stay
  blocked, and every other feature restriction is unchanged. The policy
  cannot be scoped to individual field work pages, because EUISIS is an
  Inertia SPA: the policy of the first document loaded applies to every page
  visited after it.
- **Event-based only.** The browser reads the position once, when the
  employee presses check-in or check-out (`enableHighAccuracy`,
  `maximumAge: 0`). There is no watch, interval, background or service-worker
  tracking, and no setting to enable any. Continuous tracking needs an
  approved policy first.
- **Immutable events.** Each capture is one row in
  `field_work_location_events`. The model throws on update and delete. The
  unique key (`participant`, `event_type`) prevents duplicate check-ins or
  check-outs even under a race, and the check-out never overwrites the
  check-in.
- **Server-side checks:** latitude between −90 and 90, longitude between −180
  and 180, accuracy at least 0, and a capture time no older than 10 minutes
  and no more than 2 minutes in the future (against replayed or stale
  readings). Distance is computed with the haversine formula. The statuses
  are `WITHIN_EXPECTED_AREA`, `OUTSIDE_EXPECTED_AREA`, `LOW_ACCURACY`
  (accuracy worse than 100 m) and `CANNOT_VALIDATE` (no expected point).
- **Who sees coordinates.** Ordinary viewers, including supervisors and HR,
  receive only the verification status. Latitude, longitude, accuracy and
  distance appear in the page payload only for
  `field_work.location.view_precise` within scope. Every such view writes a
  `field_work.precise_location_viewed` audit entry. Coordinates are never
  written to the general audit log: check-in and check-out audits carry the
  event id and status only. The planned destination point is shown to the
  requester and to precise viewers only.
- **Retention:** NEEDS_DECISION. No purge job exists yet.

## Audit

The audit log records these events: `field_work.created`, `.updated`,
`.submitted`, `.approved`, `.returned`, `.rejected`, `.cancelled`,
`.checked_in`, `.checked_out`, `.completed`, `.precise_location_viewed` and
`.type_saved`. Each request also keeps an append-only workflow history in
`field_work_histories`.

## Not in this release

There are no file attachments on field work, so there is no attachment IDOR
surface yet. There are no exports, so no export permission or CSV-injection
surface yet. Both belong to the reports work in the roadmap and must use
private storage, `csv_safe_row()`, queued generation and separate
permissions. Normal exports must exclude coordinates.
