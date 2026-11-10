# ADR 0037: One Letters module owns every official letter and template

- Status: accepted
- Date: 2026-11-09
- Owner rule (repeated): "I don't want letters and template management in
  different modules. One module, used by every module that needs a letter,
  and it owns letter management across organizations. Fresh module; take
  nothing from the existing letter code."

## Context

Official correspondence is how an institution acts: transfers, discipline
decisions, grievance decisions, court filings and recruitment offers all end
in a letter, and every bureau, sub-city and woreda keeps an incoming and
outgoing letter registry (ገቢ/ወጪ ደብዳቤ መዝገብ), today mostly on paper. Letter
code had started to grow inside individual modules (the Court module's
letters and templates, the Grievance decision letters, the Transfer and
Recruitment application documents), each with its own tables, numbering and
rendering. A fourth copy would deepen that split.

## Decision

1. **One owner.** `Modules/Letters` owns every letter, every letter template,
   letter numbering, routing, attachments and the letter timeline. Its tables
   are `letters`, `letter_registrations`, `letter_routings`,
   `letter_attachments`, `letter_events`, `letter_templates`,
   `letter_template_versions`, `letter_numbering_rules` and
   `letter_sequences` (one owner per table, ADR 0017). No other module stores
   letters or templates of its own from this decision on.
2. **Other modules ask, they do not store.** The public contract
   `Modules\Letters\Contracts\LetterIssuer` issues a draft outgoing letter from
   a published template for a caller's record (`regarding_type`,
   `regarding_id`) and returns the letter id and address;
   `Modules\Letters\Contracts\LetterLinks` lists the letters issued for a
   record. Callers link to the letter; they never read or write the letter
   tables.
3. **Fresh code.** The module is built from scratch. Nothing is copied or
   migrated from the Court, Grievance, Transfer or Recruitment letter code,
   which stays where it is until its owners decide to call the contract.
4. **One letter, one registry entry per institution.** A letter is one
   document (`letters`). Each institution that handles it has its own
   registry entry (`letter_registrations`) with that institution's own
   number, direction and handling status. A letter from institution A to
   institution B in the system is therefore one record with two faces: A's
   outgoing entry, numbered at dispatch, and B's incoming entry, numbered when
   B's registrar receives it. Letters from or to parties outside the system
   (a court, a citizen, a federal office) are registered by the one
   institution with the party's name and the scanned original.
5. **Numbering is the institution's, per language.** Each institution
   configures a numbering rule per direction, for one letter language or for
   both (pattern, reset policy, padding); the platform default is
   `{ORG}/{SEQ}/{EC_YEAR}` reset every Ethiopian year. Amharic and English
   letters of one institution always count separately (owner's rule, added the
   same day). Numbers are drawn from `letter_sequences` under a row lock and
   are never edited afterwards.
6. **Dispatched letters are immutable.** Once dispatched, a letter's subject,
   body and attachments cannot change; the rendered PDF is stored as an
   attachment at dispatch. A correction is a new letter that supersedes the
   old one (`supersedes_letter_id`); a reply references the original
   (`in_reply_to_letter_id`).
7. **Templates in two layers, versioned.** Platform templates (owner
   organization null, kept by a global role) and institution templates. A
   template is edited as a draft and published as a numbered version; a
   letter composed from a template records the version it used, so it always
   renders from that version.
8. **Scope fails closed (ADR 0003).** A registry entry is visible only inside
   the scope of its institution and only with `letters.view`. A letter
   document is visible to the institutions that hold a registry entry for it
   and to the people it was routed to. Routing targets, signatories and units
   are always validated against the record's institution, never an id the
   request supplied.
9. **Internal memos are letters.** Correspondence between units of one
   institution is the `internal` direction on the same model, so offices do
   not need a second tool.

10. **Registry practice from the field (second cut, same day; docs/letters-research.md).**
    Letters carry a security classification (public, internal, confidential,
    secret) whose restricted levels hide contents behind `letters.view_confidential`
    or a handling role; the institution's file code; copy recipients, each
    institution among them getting its own registry entry at dispatch; a
    dispatch register (mode, tracking reference, note) with acknowledgement of
    receipt on the sender's entry; the physical location of the paper; a
    verification code and QR code on every dispatched PDF with a public check
    page that shows issuer, number, date and status only; daily reminders for
    routing steps, receipts and signatures; and a formula-safe, audited CSV
    export of the registry.

11. **Letterhead is the institution's, with a platform default.** Emblems,
    bilingual names, parent body, contacts and motto per institution
    (`letter_letterheads`), falling back field by field to the platform default
    and the organization record; printed on every composed letter.

12. **Standards are the institution's (third cut, 2026-11-10).** A reply-time
    standard in working days by priority with default due dates,
    interim-reply requests and escalation to the signatories; a file
    classification scheme; the letterhead's footer, contacts placement, page
    numbers and official stamp; and email dispatch to parties outside the
    platform. Electronic signature and retention schedules stay open by the
    owner's decision.

13. **Headers and footers are composed, not fixed (owner, 2026-11-10).** An
    institution keeps several letter layouts ("header and footer"), one of
    them its default; platform layouts serve institutions without their own.
    Each layout holds its page setup (paper, orientation, margins, letter
    font, size, colour, where the number and date sit) and a header and
    footer built as rows of up to three columns of text lines and images.
    Every line has its own content, font, size, bold, underline, colour,
    alignment and letter language; text carries placeholders filled per
    letter. A letter records the layout it prints on (`letterhead_id`). A
    layout that letters printed on cannot be deleted, only deactivated. The
    module no longer uses the word "settings": the reply-time page is
    "Letter standards" (`/letters/standards`, `letter_standards`,
    `letters.standards.manage`).

## Roles and permissions

Group `letters`: `letters.view`, `letters.register`, `letters.compose`,
`letters.approve`, `letters.dispatch`, `letters.route`, `letters.handle`,
`letters.view_confidential`, `letters.letterhead.manage`, `letters.standards.manage`, `letters.templates.view`, `letters.templates.manage`,
`letters.templates.manage_platform`, `letters.numbering.manage`,
`letters.reports.view`, `letters.export`.

Default roles (`Modules\Letters\Contracts\Data\LettersRoles`): Letter
Registrar, Correspondence Officer, Letter Signatory, Letter Administrator
(all scoped) and Letter Template Steward (global). Every permission is
reachable through a non-administrator role (ADR 0026).

## Consequences

- Every module that needs an official letter gets it from one place with one
  look, one numbering and one registry, and the registry across institutions
  becomes possible because the institutions already live in the system.
- The Court, Grievance, Transfer and Recruitment letter features are
  unchanged by this decision. Their owners may later replace them with calls
  to `LetterIssuer`; that is a separate decision per module and is not a
  migration this ADR performs.
- Numbering conventions differ per institution, so numbering is configuration,
  not code.

## Migration impact

Additive only: nine new tables, one permission group, five default roles,
one feature flag (`FEATURE_LETTERS`, default on). No existing table changes.

## Security impact

Letters carry institutional decisions and personal data. Scope fails closed;
attachments live on the private disk and are served only through the policy
as downloads; every registration, number assignment, approval, dispatch,
receipt, routing step, download and template publication is written to the
platform audit log with the institution it concerns.

## Operational impact

Routes live under `/letters` (`letters.*`), gated by `features.letters`.
Scheduled work: none in this version (routing due dates are shown, not
chased). Backups: the private disk now holds letter scans and dispatched
PDFs.
