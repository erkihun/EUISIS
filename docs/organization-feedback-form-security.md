# Organization feedback form: security

Threats to the organization QR feedback form
([organization-public-feedback-form.md](organization-public-feedback-form.md))
and the control that answers each one, with the evidence. Broader public
feedback controls are in [public-feedback-security.md](public-feedback-security.md)
and [public-evidence-security.md](public-evidence-security.md).

## 1. Controls and evidence

| Threat | Control | Evidence |
|---|---|---|
| Submitting about another institution (target tampering) | The token in the URL is resolved on the server on every POST. `organization_id`, `organization_target_id`, `target_type`, `employee_id`, `source_organization_qr_id` posted by a browser are ignored. | `OrganizationFeedbackFormTest`: "keeps the scanned institution as the target whatever ids the browser posts", "ignores workflow, reference and version fields a browser adds" |
| Another institution's service or office (IDOR) | `service_id` must be a published, in-force service of the resolved institution; `organization_unit_id` an active office of it. Checked after the token resolves. | "refuses a service or an office of another institution, and stores nothing" |
| Forged option or question | Answers are keyed by question code, and each value is checked against that question's own option codes (`Rule::in`). A code from another question, an unknown code, a duplicate or too many choices are refused. Answers to questions the version does not ask are refused. | "refuses a choice belonging to another question and malformed answers" (14 cases), "refuses out-of-range ratings, unknown choices and over-long text" |
| Mass assignment | `status`, `reference_number`, `question_set_id`, `intake_channel`, `submitter_identity_mode` are set by the service, never from input. | "ignores workflow, reference and version fields…" |
| Stale or forged questionnaire version | The stated version must be the published one. With a required question, a missing version is refused. Validation, controller and service read the same version per request (scoped binding). | "refuses a submission answering a version that is no longer published", "refuses a form opened before a questionnaire was published", "loads the published questionnaire once per request" |
| Disabled, revoked or rotated QR; inactive or archived institution | Resolved again at POST: the send is redirected to the generic page and nothing is stored. Every case renders the same "unavailable" page. | "blocks a submission once the QR is revoked or the institution deactivated", "blocks a submission when the QR was disabled…", "shows the same unavailable page for an archived institution, an unknown token and a tampered token" |
| Stored XSS | Text is stored as typed (plain text). Every screen renders it through React text nodes. The one `dangerouslySetInnerHTML` (`RichText`) takes only purified CMS HTML. | "stores Amharic, English and markup in written answers exactly"; in the browser, `<script>alert(1)</script>` appeared as literal text on the review step and no dialog fired |
| CSRF | Laravel CSRF is active on every POST. A POST without a token gets 419, which these routes turn into a redirect back with a "page expired" notice. | Live probe: CSRF-less POST → 302 to the page, no row stored |
| Abuse / flooding | `throttle:service-feedback-submit`: per token+IP and per IP, every 10 minutes. Defaults are 3 and 8; Public Feedback › Settings can change them. Counters are in the shared cache store, so they hold across app servers. The service search and the page are `throttle:30,1`; the logo `throttle:service-feedback-photo`. | `tests/Feature/Security/RateLimitingTest.php`, PublicFeedback rate-limit tests |
| Double submission | Button disabled while sending, one-time form token (consumed, restored on failure), 120-second duplicate fingerprint over the content and answers inside a cache lock. | Browser: a double tap sent one POST. "stores a double-tapped Feedback once, with one set of answers" |
| Evidence exposure | Evidence is stored on the private `local` disk under a random name. Its MIME type is checked against the extension. Size, count and total are limited. The file is served only to authorized staff. | Live probe: `/storage/service-feedback/…` → 403. Evidence tests in `PublicFeedback*` |
| Reference enumeration / tracking | There is no public tracking endpoint. A reference number reveals nothing on its own. References come from `CodeRuleService` (`PSC-`, `PTO-`), never the row id. | "gives the receipt only the type, the institution, the reference and the time" |
| Internal ids on the public page | No organization id is sent. The logo is served by token (`/feedback/{token}/logo`, 404 for any other token) instead of `/storage/…/{organization id}/…`. | "serves the institution logo through the scanned token…"; browser: page HTML has no organization id |
| PII at rest | `client_contact` and `client_email` are encrypted. Identity fields are hidden from staff without `service_feedback.view_submitter_identity`. IP and user agent are kept for abuse investigation only. | DB check: contact stored encrypted |
| PII in analytics | Situation Room payloads carry aggregates only. No name, phone, narrative, written answer or evidence. | `SituationRoomQuestionAnalyticsTest` (payload scan), `SituationRoomScopeTest` |
| Questionnaire tampering by staff | `service_feedback.manage_questions` and a city-level account are both required. A published version cannot be edited or deleted; each action is audited. | `PublicFeedbackQuestionnaireAdminTest` |

## 2. Known gaps

| Code | Gap |
|---|---|
| MALWARE_SCANNER_REQUIRED | Evidence is MIME-checked but not virus-scanned (`scan_status = not_scanned`). |
| NEEDS_POLICY_DECISION | Public tracking of a reference is not built. If it is, it must require a second factor (for example the phone number given), not the reference alone. |
| DEPENDENCY | `npm audit` reports 10 advisories, all in build/dev tooling (Tailwind 3 toolchain, `concurrently`, `shell-quote`, `braces`). None reaches the browser bundle. Upgrading Tailwind is a major change, tracked separately. `composer audit`: none. |
