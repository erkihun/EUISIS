# Work experience

The record of each employee's work experience, the employee's request for a
written certificate, and the certificate itself. Design and decisions:
`docs/architecture/adr/0040-work-experience-module.md`. User guide (Amharic):
`docs/user-guide/am/29-work-experience.md`.

## Who does what

| Who | Where | What |
| --- | --- | --- |
| Employee | My Portal > My records > My work experience | reads the recorded experience, asks for a certificate with a short form, withdraws a waiting request |
| HR officer | Employee management > Work experience | records and corrects experience: salary and corrections of an assignment, earlier service, service elsewhere with evidence |
| HR officer | Employee management > Work experience requests | accepts or sends back, prints, records the sending; enters a request for an employee who has left |
| HR officer, organizational administrator | Employee management > Work experience settings | the signatory of each institution, salary on or off, service elsewhere on or off |

## Layout

```
Contracts/Data          WorkExperienceRoles (permission sets), WorkExperienceStatus
Domain/Models           WorkExperienceRecord, WorkExperienceRequest, WorkExperienceOfficeSetting
Application/Services    WorkExperienceAccess     who may see and do what (scope fails closed)
                        WorkExperienceTimeline   assignments + records → lines and totals
                        WorkExperienceRecords    save and delete the office's records
                        WorkExperienceRequests   submit, accept, send back, rewrite, print count, sent, cancel
                        WorkExperienceOffice     per-institution settings
                        WorkExperienceNotifier, WorkExperiencePresenter
Application/Notifications  WorkExperienceNotification (rendered in the reader's language)
Http/Controllers        Employee/MyExperienceController, RecordController, RequestController, SettingsController
Infrastructure/Resources/views  certificate.blade.php (the A4 page that is printed)
Database/Migrations     tables; permissions
Lang/{en,am}            server messages and the certificate's text
```

Pages: `resources/js/modules/work-experience/pages` (rendered as
`modules/work-experience/<Page>`), shared pieces in `lib.tsx`, dictionaries
in `i18n/{en,am}.ts` under the key `workExperience`.

## Rules worth knowing

- Feature flag `work_experience` (`FEATURE_WORK_EXPERIENCE`, default on);
  route prefixes `work-experience.` and `employee.work-experience.`.
- A request is accepted only when the institution has a signatory and at
  least one line would be printed.
- The certificate is frozen at acceptance. "Write again from the record"
  refreshes it while the request is accepted and not sent.
- "Sent" needs at least one printing.
- Certificate numbers: `WE/<sequence>/<Ethiopian year>` per institution,
  unique by index; two officers accepting at once get consecutive numbers.
- Evidence files are on the private `local` disk under
  `work-experience/<employee id>`; every download is audited.
- Every write is in the audit trail (`work_experience.*` events).

## Tests

`tests/Feature/WorkExperience/WorkExperienceModuleTest.php`.
