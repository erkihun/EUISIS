# Employee availability

`App\Services\FieldWork\EmployeeAvailabilityService` reports only the statuses
that installed EUISIS data can prove:

| Status | Meaning |
|---|---|
| `official_field_work` | Checked in to approved or in-field work, and not yet checked out |
| `approved_not_checked_in` | Approved field work covers the moment, but there is no GPS check-in yet |
| `unknown` | Anything else. This does **not** mean absent or in the office. EUISIS has no attendance, leave, training or travel source that could prove either. |

Availability is a workflow status, not live tracking. The list exposes:
- the employee's identity and placement unit;
- the Field Work reference;
- a destination summary and the expected return.

It **never** returns coordinates. It is paginated (at most 100 rows per page).

## Who sees whom

The **Team Availability** page (`field-work.availability.index`) uses the
same scope rules as the rest of Field Work Management (see `FieldWorkAccess`):

- **Supervisors** (`field_work.view_team`) see the employees their explicit
  line-manager (reviewer) assignments cover, plus any request they decided.
- **HR oversight** (`field_work.view_org`) sees the organizations in its
  `OrganizationScopeService` scope.

There is still no general reporting-line model. Supervisor scope comes from
the explicit reviewer assignments that already define line-manager authority
for Daily Activity, EPMS and Field Work approval.

Statuses such as `IN_OFFICE`, `LEAVE`, `TRAINING` and `OFFICIAL_TRAVEL` are
added only when a module exists that can substantiate them.
