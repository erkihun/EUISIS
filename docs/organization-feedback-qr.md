# Institution feedback QR — operations

The institution (organization) feedback QR is the second public entry point
of the public feedback flow: one stable code per institution, printed on a
poster, resolving server-side to that institution for rating, suggestion and
complaint. It is **not** a second employee QR: employees keep the card
identity they already have (`docs/employee-feedback-security.md`).

## 1. Lifecycle

| Action | Route | Permission (plus the institution in the actor's scope) | Effect | Audit event |
| --- | --- | --- | --- | --- |
| Create / enable | `POST /organizations/{id}/feedback-qr` | `service_feedback.organization_qr.create` | ensures one active code (64-hex opaque token, unique across all rows ever); idempotent | `service_feedback.organization_qr_generated` |
| Disable | `POST …/feedback-qr/disable` | `organization_qr.disable` | status `disabled`, `disabled_at`; the public URL answers 404 at once; the token is kept and can be re-enabled | `organization_qr_disabled` |
| Enable | `POST …/feedback-qr/enable` | `organization_qr.disable` | status `active` again; same token, posters stay valid | `organization_qr_enabled` |
| Rotate | `POST …/feedback-qr/rotate` | `organization_qr.rotate` | revokes the current code (`revoked_at`, `revoked_by`, reason) and issues a new one in the same transaction; `rotated_from_id` links them; the old token is never reissued | `organization_qr_rotated` |
| Revoke | `POST …/feedback-qr/revoke` | `organization_qr.rotate` | revokes without replacement (institution has no public code until created again) | `organization_qr_revoked` |
| Export PNG / PDF | `GET …/feedback-qr/png`, `…/pdf` | `organization_qr.export` | printable artefact with the institution's name (EN/AM), code and the public URL; the QR encodes the public URL only | `organization_qr_exported` |
| View | `GET /organizations/{id}/feedback-qr`, list `/feedback-admin/organization-qr` | `organization_qr.view` | status, enabled/disabled/rotated times, scan count, last scan, who did what | — |

One active code per institution is enforced by the database (partial unique
index `org_feedback_qr_one_active`), not only by the service. The
`OrganizationFeedbackQrAccess` helper requires both the permission and
`OrganizationScopeService::canAccessOrganization`, so a scoped administrator
cannot mint or revoke another institution's code by guessing the id.

## 2. What survives what

| Change | Token | Stored submissions |
| --- | --- | --- |
| institution rename, logo, branding | unchanged | keep `target_name_snapshot` |
| hierarchy / structure version, administrator change | unchanged | unchanged |
| new services, categories, routing rules | unchanged | unchanged |
| disable / enable | unchanged | unchanged |
| rotate / revoke | old token answers 404; new token (rotate) works at once | keep `source_organization_qr_id` pointing at the old row, which is retained |
| institution archived (soft delete) | resolver answers 404 | retained |

Proofs: `tests/Feature/PublicFeedback/PublicFeedbackQrStabilityTest.php`,
`OrganizationFeedbackQrTest.php`, `TargetResolverTest.php`.

## 3. Printing and visual quality

The PNG export renders the code at 12 pixels per module (a few hundred
pixels square, crisp at A5 and acceptable at A4) and encodes only the public
URL. The PDF (`resources/views/pdf/organization-feedback-qr.blade.php`)
places the code as vector SVG with the institution's logo when one is
uploaded, its name in English and Amharic and the public URL written under
the code for phones that cannot scan. Both downloads are audited
(`organization_qr_exported`) and sent with `Cache-Control: no-store`. Print a
test copy and scan it with two phone models before distributing; the landing
page shows the institution name, so a wrong poster is visible at the first
scan. Posters printed before a rotation are invalid the moment the rotation
is saved — rotate only when the old posters can be replaced the same day, or
disable first and re-enable after the swap.

## 4. Incident response

| Situation | Do | Do not |
| --- | --- | --- |
| Poster photographed and shared, flood of junk from one code | the per-code throttle already limits it; if abuse continues, **disable** the code (instant 404), investigate with the audit trail and the submission metadata (IP, user agent — visible only to abuse investigators, never in the UI), then **rotate** and reprint | delete submissions; retarget anything |
| Code printed on a poster for the wrong institution | disable; export the right institution's code; reprint | rotate the wrong institution's code (its own posters are fine) |
| Institution merged or closed | archive the institution (its code answers 404); stored submissions stay readable in scope | delete the QR row (history points at it) |
| Suspected token leak from the database | rotate every affected code (`OrganizationFeedbackQrService::rotate`), reprint | reuse old tokens |
| Need to pause intake city-wide | the `enabled` policy switch (Public Feedback Management → Settings) takes every public page offline with a notice | disable 240 codes one by one |

Every action above writes an audit row with actor, institution and reason;
`feedback:data-quality` reports codes without an institution, duplicates and
institutions in service without a code; `production:readiness` reports how
many active institutions have a code.
