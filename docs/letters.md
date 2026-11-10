# Letters

Official correspondence for every institution in the system: the incoming and
outgoing registry, composing from templates, signature and dispatch, routing
inside the institution, numbering and reports. Decision: [ADR 0037](architecture/adr/0037-letters-module.md).
Code: `Modules/Letters`, routes `routes/letters.php` (`/letters`, `letters.*`),
pages `resources/js/Pages/Letters`, dictionary `letters.*`
(`resources/js/i18n/{en,am}/letters.ts`), server text `lang/{en,am}/letters.php`.
Flag: `FEATURE_LETTERS` (default on).

## What it does

| Step | Who | What happens |
| --- | --- | --- |
| Register incoming | Letter Registrar | A letter from outside the system (or from an institution not using the module) is registered; the institution's incoming number is drawn at once; scans are attached. |
| Compose | Correspondence Officer, Signatory, Administrator | An outgoing or internal letter is drafted, blank or from a published template; the composer fills the template's fields. |
| Sign | Letter Signatory | The named signatory (or anyone with `letters.approve` in the institution) signs or returns the draft with a reason. |
| Dispatch | Registrar, Signatory, Administrator | The sender's registry entry is created and numbered, the PDF is frozen as an attachment, the letter becomes immutable. A recipient in the system gets an entry awaiting receipt and its registrars are notified. |
| Receive | Recipient's Registrar | The recipient institution receives the letter and draws its own incoming number. |
| Route | Registrar, Administrator | The letter is forwarded to a unit or a person with an instruction and a due date; the addressee acknowledges, completes with an outcome, or returns it. |
| Close | Registrar, Administrator | The institution's handling is filed once every routing step is completed or returned. |
| Reply | Officer of the receiving institution | A new outgoing draft addressed back to the sender, referencing the original. |

An internal memo is the `internal` direction on the same model: one registry
entry, routed to the unit after dispatch, no receipt step.

What the registries of the world add, and the module has since the second cut
([research note](letters-research.md)):

- **Classification.** `classification` on the letter: public, internal
  (default), confidential, secret. Confidential and secret contents (subject,
  text, parties, files, PDF) open only to the author, signatory, approver,
  dispatcher, routed addressees and holders of `letters.view_confidential` in
  scope. Everyone else in scope sees the registry entry with number, dates,
  institution and status; the subject reads as the classification.
- **File code.** The institution's own file or subject code on the letter,
  searchable in the registry and printed on the letterhead.
- **Copies (ግልባጭ).** Copy recipients on composed letters; each institution
  among them gets its own `pending_receipt` entry at dispatch and its
  registrars are notified; external copies print on the letter.
- **Dispatch register.** Dispatch records the mode (in the system, by hand,
  post, courier, email, fax), the tracking reference and a note. The sender's
  desk later records the acknowledgement of receipt (date, how) on its own
  entry: proof of delivery.
- **Physical location.** Cabinet, shelf or box on the registry entry, set at
  registration or receipt.
- **Verification.** A 12-character code issued at dispatch, printed with a QR
  code on the PDF. `/verify/letter/{code}` (no sign-in) confirms issuer,
  number, date, dispatch time and language, nothing else; unknown codes get
  the same page with "not found".
- **Reminders.** `letters:send-reminders` (daily 06:30): routing steps two
  days before due and daily once overdue, letters awaiting receipt after two
  days, drafts awaiting signature after three days; each row at most once a
  day; every reminder is a timeline event.
- **Header and footer.** `letter_letterheads`: any number of layouts per
  institution (one `is_default`), platform layouts (organization null) for
  institutions without their own, and a built-in layout when none exists.
  Each layout stores `page` (paper, orientation, margins, letter font, size,
  colour, reference block left or right), `header` and `footer` as JSON rows
  (`Modules/Letters/Application/Services/LetterLayout.php` defines and
  normalizes the structure: up to 6 rows, 3 columns per row, 8 lines per
  column), its `images` (private disk, sniffed PNG/JPEG/SVG, scaled to print
  size, max 8) and `stamp_path`. A line is text or an image; text lines carry
  their own font (Abyssinica SIL or Noto Sans Ethiopic, the only bundled
  fonts with both scripts; Abyssinica has no bold, neither has italic),
  size, bold, underline, colour and the letter language they print on.
  Placeholders: `{{institution.name}}`, `{{institution.name_am}}`,
  `{{institution.name_en}}`, `{{institution.code}}`, `{{unit.name}}`,
  `{{letter.number}}`, `{{letter.date_ec}}`, `{{letter.date_gc}}`,
  `{{today.date_ec}}`, `{{page}}`; unknown ones are refused on save, and a
  line that is empty for a letter is skipped. The header prints on the first
  page, the footer on every page inside the bottom margin, the stamp beside
  the signature on dispatched letters only. Letters pick a layout on the
  compose page (`letters.letterhead_id`); `LetterheadResolver::pick` takes the
  chosen one if it is active and belongs to the institution or the platform,
  else the institution's default, else the platform's, else the built-in
  layout. Pages: `/letters/letterheads` (list with "Add new"), `/create`,
  `/{id}/edit` (editor with live preview and placeholder palette),
  `/{id}/preview` (sample PDF), duplicate, make default, delete (refused once
  letters printed on it). Permission `letters.letterhead.manage`; platform
  layouts need city-wide reach.
- **Reply-time standard.** `letter_standards` per institution (platform
  default as fallback, built-in defaults 10 / 3 / 1 working days): a letter
  registered or received without a due date gets one from the standard by
  priority (`LetterStandards::dueDate`, Monday to Friday). The reminder run
  asks the holders for an interim reply within `interim_reply_days` of the
  date when no reply has started, and escalates to the institution's
  signatories once overdue past `escalation_after_days`, each once. Reports
  show the on-time rate. Page `/letters/standards` (`letters.standards.manage`).
- **File classification scheme.** `letter_file_classes` per institution with
  a platform scheme as fallback; picked at registration and composing
  (`file_class_id`), filterable in the registry; the free-text file code stays.
- **Email dispatch.** Dispatch mode `email` mails `LetterDispatchedMail` (the
  frozen PDF, number, verification code and check page) to the address given
  on the dispatch form or the letter's external party email; the address
  becomes the dispatch reference and an `emailed` event is recorded.
- **Export.** `GET /letters/registry/export` (letters.export) streams the
  registry with the page's filters as CSV, formula-safe (`csv_safe_row`),
  audited, confidential subjects masked unless the exporter may read them.

## Model

- `letters` — the document. `organization_id` owns it (sender of composed
  letters, receiver of letters from outside). Status: draft → pending_approval
  → approved → dispatched; `received` for registered incoming letters;
  `cancelled` for withdrawn drafts.
- `letter_registrations` — one per institution handling the letter: its own
  `registry_number` (unique per institution and direction: the incoming and
  outgoing registers are separate books), `direction`, handling `status`
  (pending_receipt, registered, routed, in_progress, closed), received and due
  dates, physical location, acknowledgement of receipt.
- `letter_routings` — forwarding steps inside one institution.
- `letter_attachments` — scans, annexes and the dispatched PDF on the private
  disk; served only through the policy, as downloads, sniffed by content.
- `letter_events` — the timeline (append-only).
- `letter_templates`, `letter_template_versions` — templates with a draft text
  and numbered published versions; letters record the version they used.
- `letter_numbering_rules`, `letter_sequences` — numbering per institution and
  direction; the sequence row is bumped under a row lock.

## Numbering

Inside one institution, Amharic and English letters are numbered separately:
every sequence is per institution, direction, language and reset period
(owner's rule, 2026-11-09). A rule names one language or applies to both.
Rule lookup, most specific first: institution + language → institution, both
languages → platform + language → platform, both → the built-in
`{ORG}/{SEQ}/{EC_YEAR}` restarting every Ethiopian year. Tokens: `{ORG}`
institution code, `{SEQ}` padded sequence, `{EC_YEAR}`, `{GC_YEAR}`, `{DIR}`
the rule's direction code, `{LANG}` the rule's language code (default AM/EN).
Numbers are drawn at registration
(incoming), at dispatch (outgoing, internal) and at receipt (the recipient's
incoming entry). They never change.

## Templates and placeholders

`{{ key }}` tokens. Built-in keys (`TemplateRenderer::BUILT_IN`): `letter.*`
(number, date, date_ec, date_gc, subject, external_reference), `sender.*`,
`recipient.*`, `signatory.name`, `today.*`. Custom keys must be declared on the
template (key, labels, required); publishing refuses undeclared keys. Values
are HTML-escaped; unknown keys render empty. The body is rich text sanitized
on save (`RichTextSanitizer`) and again on display (`RichTextViewer`).

## Access

Permissions (group `letters`): view, register, compose, approve, dispatch,
route, handle, view_confidential, templates.view, templates.manage, templates.manage_platform,
numbering.manage, reports.view, export. Default roles: Letter Registrar,
Correspondence Officer, Letter Signatory, Letter Administrator (scoped),
Letter Template Steward (global). `LettersAccess` pairs every permission with
`OrganizationScopeService` against the record's institution; a routed person
sees the letter routed to them and nothing else; platform templates and the
platform numbering default need city-wide reach. The Super Admin role bypass is
exempted for every model (the policies decide on state).

## Contract for other modules

`Modules\Letters\Contracts\LetterIssuer::issue(IssueLetterRequest)` drafts an
outgoing or internal letter from a published template for a caller's record
(`regarding_type`, `regarding_id`) and returns a `LetterSummary` (id, status,
number, url). `LetterLinks::forRecord()` lists the letters issued for a record.
No caller uses it yet; the Court, Grievance, Transfer and Recruitment letter
features are unchanged (ADR 0037 §3).

## Operations

Audit: every registration, number, transition, routing step, download and
template change (`AuditEventType::Letter*`), tagged with the institution.
Notifications (`LetterNotification`, database channel): awaiting receipt,
awaiting signature, returned, signed, routed. Files: `storage/app/letters/<id>/`
on the `local` disk. Tests: `tests/Feature/Letters`, scenario
`tests/Support/LettersScenario.php`.
