# Public Tip-off (ጥቆማ) — business rules

Amharic: የሕዝብ ጥቆማ — የንግድ ደንቦች

A Tip-off is a report from the public about something **suspected or
observed**. It is never a finding. Nothing in the system treats a Tip-off as
proof, and nothing about a Tip-off starts discipline, a sanction, a status or
position change, or an EPMS score change.

Internally a Tip-off is still a `suggestion` row (`ServiceFeedbackType::Suggestion`).
That value is a production identifier in routes (`/{token}/suggestion`),
stored rows and routing rules, so it was kept. Only the words the public and
staff read changed: **Tip-off / ጥቆማ**.

## 1. Exactly two types

| Code (stored) | Amharic | English | Meaning |
|---|---|---|---|
| `CORRUPTION` | የሙስና ጥቆማ | Corruption Tip-off | Information about suspected corruption |
| `IMPROPER_PRACTICE` | የብልሹ አሠራር ጥቆማ | Improper Practice Tip-off | An observed or suspected improper practice, maladministration or broken process |

- `App\Enums\PublicTipType`. The stored value is the code, never a label.
- Every **new** Tip-off must carry exactly one type (`required`, `Rule::enum`).
  `OTHER`, lower-case codes and translated labels are refused (422).
- Feedback and Complaint never carry a type (`prohibited`). PostgreSQL enforces
  the same rule with `esf_tip_type_chk`, and the model does too
  (`assertTipTypeIntegrity`).
- The tip type is not a category, a service, a target type or a submission
  type. Each is a separate column.

## 2. Identity: optional for a Tip-off

| Type | Full name | Phone | E-mail | Anonymous | Service received |
|---|---|---|---|---|---|
| Feedback | required | required | optional | **no** | required (employee target) |
| **Tip-off** | optional | optional | optional | **yes** | **not required** |
| Complaint | required | required | optional | **no** | optional |

There is one matrix, `App\Services\ServiceFeedback\PublicSubmissionPolicy`.
The Form Requests (through `ValidatesPublicSubmitter`), the submission service
and the public page all read it. The page receives it as the `requirements`
prop and only mirrors it; the server stays authoritative.

The old city-wide `identity_mode` setting (anonymous / identified / optional)
is retired. It is no longer read or editable, and the stored value is left in
place. Historical rows keep the `submitter_identity_mode` they were taken
under, including any anonymous Feedback or Complaint.

## 3. No service is needed

A person may report what they saw or know without ever having received a
service. A Tip-off never requires `position_service_id`. A related service
can be given as optional context under "More details".

## 4. The target is fixed by the QR

The scanned employee or institution is the Tip-off's target, resolved on the
server from the opaque token and checked again on the final POST. Ids, target
types, assignments, channels or references in the payload are ignored. Naming
another person or office in the narrative or in *Other person(s)/office(s)
involved* does not re-target the row. Reviewers may add investigation subjects
or request a referral (`public_feedback_subjects`, `public_feedback_referrals`);
both are separate, controlled records.

## 5. Reference, channel and dates are the system's

- Reference: code rule `public_tip_off` (`PTO-{YEAR}-{SEQUENCE}`), issued in the
  same transaction. Complaints keep `PSC-…`.
- Channel: `intake_channel = WEB_QR` (`PublicIntakeChannel`). The public is never
  asked how the Tip-off was received. `PHONE`, `ORAL` and `WRITTEN` exist for a
  future staff intake; no screen writes them today.
- Received: `submitted_at`, set by the server.

## 6. Confidentiality

- A tipster's name, phone, e-mail, organization and address need
  `service_feedback.view_submitter_identity`. E-mail and address are encrypted
  at rest, as the phone already was. All identity columns are `$hidden` on the
  model, so serialising a row never carries them.
- Every opening of a submission whose sender left any detail, by a user who
  may see it, writes `service_feedback.submitter_identity_viewed` to the audit
  log. The audit entry holds codes only, never the details.
- Search by submitter name is limited to holders of the identity grant, so a
  search cannot reveal who sent what.
- Dashboard and report aggregates carry counts only: no tipster, no narrative,
  no evidence.
- IP address and user agent are kept for abuse prevention only (`$hidden`). They
  are never presented as the tipster's identity.

## 7. Routing

- A **corruption** Tip-off is routed only by a rule that names
  `tip_type = CORRUPTION`. A rule with no tip type, including every rule
  written for "suggestions" before Tip-off types existed, never receives one.
  With no designated rule it is stored as **UNROUTED** and the routing
  administrators are told. It is never discarded.
- An **improper-practice** Tip-off matches rules naming its type or no type.
- **OFFICIAL_TIP_ROUTING_POLICY_REQUIRED**: no official corruption-investigation
  route is defined in this system. Until one is, designate handlers with
  corruption rules per institution.

## 8. Legacy suggestions

Suggestions stored before Tip-off types keep `tip_type = NULL`. They are not
deleted, re-labelled or back-filled. Analytics count them as
`UNCLASSIFIED_LEGACY`, so the three buckets always add up to the total.

## 9. Not decided here

- **OFFICIAL_RETENTION_POLICY_REQUIRED**: no retention period is defined for
  Tip-offs or their evidence. Nothing is purged automatically.
- **MALWARE_SCANNER_REQUIRED**: see `docs/public-evidence-security.md`.

Related: `docs/public-tipoff-form.md`, `docs/public-submission-rules.md`,
`docs/public-evidence-security.md`, `docs/public-qr-identity.md`,
`docs/situation-room-community-voice.md`.
