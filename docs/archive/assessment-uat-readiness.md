# Assessment UAT readiness matrix

Use synthetic data only. Capture tester, date, actual result, defect reference, and evidence URL for every row. Status begins `NOT_EXECUTED`; do not infer a pass from source review.

| ID | Role | Precondition / scenario | Expected result | Status |
|---|---|---|---|---|
| UAT-FORM-01 | Assessment Admin | Create draft with sections, criteria, decimal options | Draft validates; invalid criteria/options are rejected | NOT_EXECUTED |
| UAT-FORM-02 | Assessment Admin | Publish a valid form, then attempt edit/delete | Published version is immutable; new draft version preserves v1 | NOT_EXECUTED |
| UAT-CYCLE-01 | City Admin | Configure cycle and included institutions | Only selected institutions participate | NOT_EXECUTED |
| UAT-ELIG-01 | HR Officer | Snapshot eligibility with transfer/new hire/exclusion cases | Snapshot remains historical; controlled exclusions do not silently alter denominator | NOT_EXECUTED |
| UAT-ASSIGN-01 | City Admin | Generate assignments with manager, peer, committee rules | Server resolves forms/evaluators; no self or duplicate assignment | NOT_EXECUTED |
| UAT-EXEC-01 | Assigned Evaluator | Draft, evidence upload, submit valid assessment | Only own assignment is accessible; server calculates score | NOT_EXECUTED |
| UAT-REVIEW-01 | Reviewer | Return, resubmit, and finalize | Invalid transitions rejected; finalization is atomic | NOT_EXECUTED |
| UAT-OVERSIGHT-01 | Institution/City | Filter dashboard, employee drill-down, gender/distribution | Permission + organization/unit scope applies to every surface | NOT_EXECUTED |
| UAT-SUBMIT-01 | Signatory/City Reviewer | Submit, return, resubmit, start review, verify, finalize | Immutable submission snapshots; separation of duties enforced | NOT_EXECUTED |
| UAT-RESULT-01 | Employee | View own final result and acknowledge | No arbitrary employee ID; no anonymous evaluator identity leaked | NOT_EXECUTED |
| UAT-DEV-01 | Manager/Employee | Create gap and IDP from a final result | Separate non-punitive development workflow with approval controls | BLOCKED — workflow absent |
| UAT-APPEAL-01 | Employee/Committee | Submit, review, decide appeal with evidence | One eligible own-result appeal; private evidence; versioned result | BLOCKED — workflow absent |
| UAT-MOD-01 | Moderator | Review moderation item | Controlled review only; no forced distribution or hidden score edit | BLOCKED — workflow absent |
| UAT-CORR-01 | Requester/Reviewer/Approver | Request, approve, implement correction | Segregation of duties; original retained; new authoritative version | BLOCKED — workflow absent |
| UAT-EXPORT-01 | Report Viewer | Generate CSV/Excel/PDF | Scoped, authorized, private export; formula injection neutralized | NOT_EXECUTED |
| UAT-SEC-01 | Negative security tester | Manipulate record/response/submission/export/evidence IDs | 403/404 per convention; no cross-org or cross-employee disclosure | NOT_EXECUTED |
| UAT-LOCALE-01 | Amharic/English tester | Complete primary workflows in both locales | Localized labels/validation/statuses; Ethiopian display dates and Gregorian DB dates | NOT_EXECUTED |
| UAT-A11Y-01 | Keyboard/screen-reader tester | Evaluator form, result, appeal, IDP, dashboard | Labels, focus, errors, dialogs, contrast, and non-color status all usable | NOT_EXECUTED |

Release acceptance: zero BLOCKER and unresolved CRITICAL defects; HIGH defects require explicit documented risk acceptance. This matrix cannot be signed off until blocked workflows are implemented and the database-capable automated suite passes.
