# Public Tip-off form — from paper to the QR web form

Amharic: የሕዝብ ጥቆማ ቅጽ — ከወረቀት ወደ ድረ-ገጽ

The digital form keeps the **business information** of the paper Tip-off
intake form. It does not copy the paper's layout. Anything the system already
knows is recorded automatically, staff fields move to the internal workflow,
and the citizen sees only what they can answer.

> **Source note.** The paper form was described in the requirements but was
> not attached in this implementation session. The mapping below is built from
> the fields the requirements name (tipster details and address, Tip-off type,
> facts, how it was received, date received, receiving and registering officer,
> signature, forwarding, internal reference, action taken). **Verify it
> against the official paper form before sign-off** and add any field it holds
> that is not listed here.

## 1. Paper → digital mapping

| Paper form field | Digital public input | System generated | Internal workflow | Removed / not needed | Reason |
|---|---|---|---|---|---|
| Tipster full name | `client_name` (optional) | | | | A tipster may stay anonymous |
| Tipster phone | `client_contact` (optional) | | | | Voluntary, encrypted at rest |
| Tipster e-mail | `client_email` (optional) | | | | Voluntary, encrypted at rest |
| Address: city / sub-city / woreda–kebele / other | `submitter_address.{city,sub_city,woreda,detail}` (optional, folded under "Additional contact/address information") | | | | Progressive disclosure; never mandatory |
| Tip-off type: corruption / improper practice | `tip_type` (**required**, two radio cards) | | | | The one classification a tip must carry |
| Facts / details of the tip (ፍሬ ነገር) | `message` (**required**) | | | | One narrative field (`comment` column) |
| Who is reported | — | Target from the scanned QR (employee or institution) | | Re-entering the name | The QR already identifies the target |
| Other persons / offices involved | `involved_parties` (optional) | | | | Context only; never re-targets |
| When it happened | `incident_date` (optional, approximate) | | | | Unknown dates must not block a tip |
| Where it happened | `incident_location` (optional) | | | | |
| Evidence attached | `attachments[]` photo / video / audio / PDF (optional) | Kind, size, SHA-256, private path | Download audited | | Evidence is submitted, not proof |
| How it was received (phone / oral / written) | — | `intake_channel = WEB_QR` | `PHONE` / `ORAL` / `WRITTEN` reserved for staff intake | Asking the citizen | The system knows it came through the website |
| Date the tip was received | — | `submitted_at` | | Asking the citizen | Server time |
| Reference / file number | — | `reference_number` (`PTO-YYYY-NNNNNN`, code rule `public_tip_off`) | | Typing it | Generated; never user-supplied |
| Receiving officer / registration officer | — | | Routing rule → handler; `public_feedback_assignments` | Public field | Staff work, not the citizen's |
| Receiver's signature / approval signature | — | | Status history + audit log (actor, time) | Wet signature | Authenticated actions replace signatures |
| Forwarded to / forwarded date | — | | Assignment and reassignment history with timestamps | Public field | |
| Action taken / decision | — | | Workflow status, review note, outcome; referral as a **new** linked record | Public field | Never shown on the public form |
| Service received | Optional "Related service" under *More details* | | | Required "Service received" | A tipster may never have been served |

Not collected at all: national ID, passport, date of birth, salary.

## 2. The public form (mobile first)

One column, large touch targets, sticky **Submit**, with progress shown while
files upload. Sections, in order:

1. **Target**: employee photo, full name and job designation, or the
   institution's name and logo. Read-only.
2. **የጥቆማ ዓይነት / Tip-off Type \***: two large native radio cards (keyboard
   and screen-reader accessible, `required`).
3. **የጥቆማው ዝርዝር / Tip-off Details**: the factual description \*. Then
   *More details (optional)*: date of occurrence, where, other persons/offices
   involved, related service.
4. **ማስረጃ ያያይዙ / Attach Evidence** (optional): Photo, Video, Audio (and PDF)
   buttons with `image/*`, `video/*` and `audio/*` hints, each file removable
   before sending, and early warnings on size and count.
5. **የጠቋሚው መረጃ / Tipster Information** (all optional): full name, phone,
   e-mail. Then *Additional contact/address information*: city, sub-city,
   woreda/kebele, other.
6. **Submit**: the receipt shows the reference, the time, the Tip-off type and
   the target as the visitor saw it. It never shows a handler.

## 3. Field contract (browser → server)

`tip_type`, `message`, `incident_date`, `incident_location`, `involved_parties`,
`position_service_id`, `category_id`, `client_name`, `client_contact`,
`client_email`, `submitter_address[city|sub_city|woreda|detail]`,
`attachments[]`, `form_token`. Optional fields arrive empty. Anything not on
this list is ignored or refused. `tests/Feature/PublicFeedback/PublicFormSubmissionTest.php`
posts exactly what the browser sends.
