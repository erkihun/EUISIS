# ADR 0009: Core platform P2 — identity and card integrity

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation (ADR 0005), choosing the fail-closed option where the
business rule was not stated. Builds on ADR 0008.

## Decisions

### 1. One evidence table for documents, the photo and the signature

- `employee_documents` holds every stored file version. A `kind` column
  (document, photo, signature) and a document type distinguish them. One table
  gives one lifecycle (versions, verification, audit, download) and never two
  copies of a file.
- **Versions.** The versions of a document share a `lineage_id`. Exactly one is
  current (partial unique index), and an employee has at most one current photo
  and one current signature. A replacement adds version n+1, and the earlier
  versions stay readable to authorized users.
- **Statuses.** A version is pending, verified, rejected, expired, replaced or
  archived. A verified row names its verifier (CHECK constraint), and an
  archived or replaced row is never current (CHECK constraint, migration
  `2026_10_27_140000`).
- **Who verifies.** Someone other than the uploader verifies or rejects, and a
  rejection needs a reason.
- **Storage.** Files sit on the private `evidence` disk under generated names,
  with the SHA-256 checksum stored. The original file name is metadata only.
- **File checks.** The server checks the bytes, not the browser's type:
  - PDF, JPEG or PNG only, and the extension must match the content;
  - images must decode; a photo must be portrait and at least the minimum size
    (settings);
  - PDFs may carry no scripts, launch actions or embedded files;
  - size limits are Core settings (`files.*`).
- **Deleting.** Nothing that served is deleted. Archive keeps the history. Only
  an unused pending upload of an employee not yet approved may be discarded.
- **Audit.** Every change is audited, and so is every document download and
  every signature view. Inline photo previews are not audited; a photo download
  is. Contents are never audited.
- **Backfill.** None. The imported `photo_path`/`signature_path` values point at
  files the Core does not hold, so they are not evidence. Imported employees
  need a photo uploaded before a new card.
- Migration: `2026_10_27_100000_create_employee_documents_table`.

### 2. Evidence requirements are settings, evaluated once

- **Approval.** `workforce.verification_requires_photo`,
  `workforce.verification_requires_signature` and
  `workforce.required_document_types` decide what approval needs. The photo and
  signature checks are off by default; no document types are required by
  default.
- **Cards.** `id_cards.require_photo` (on), `id_cards.require_verified_photo`
  (on) and `id_cards.require_signature` (off) decide what a card needs.
- **One evaluation.** `EmployeeEvidence` writes the rules once, as SQL, and
  returns codes:
  - `PHOTO_REQUIRED`, `PHOTO_NOT_VERIFIED`;
  - `SIGNATURE_REQUIRED`, `SIGNATURE_NOT_VERIFIED`;
  - `REQUIRED_DOCUMENT_MISSING:<type>`, `REQUIRED_DOCUMENT_NOT_VERIFIED:<type>`.
- Verification and approval refuse with one error per code. The manual "photo
  verified" and "documents verified" checkboxes are gone, because the system
  checks the evidence itself.

### 3. One card eligibility specification

- `CardEligibility` holds every rule as an `EligibilityRule`: SQL that is true
  when the rule fails. The same rules serve both uses:
  - a single decision selects the employee's flags;
  - the card request picker filters with `NOT rule` in one query (two queries
    for any number of employees, a regression test bounds it).
- Reason codes: `EMPLOYEE_NOT_APPROVED`, `EMPLOYEE_NOT_ACTIVE`,
  `NO_ACTIVE_ASSIGNMENT`, `ORGANIZATION_INACTIVE`, `POSITION_INACTIVE`,
  `IDENTITY_INCOMPLETE`, `DUPLICATE_CONFIRMED`, the evidence codes,
  `PENDING_CARD_REQUEST_EXISTS`, `ACTIVE_CARD_EXISTS`, `REPLACEMENT_REQUIRED`,
  `NO_CURRENT_CARD`, `REQUEST_TYPE_NOT_ALLOWED`, `CARD_IN_PRODUCTION`.
- **What changed.** The picker's separate rule (`eligibleTypes`) is removed. The
  four renamed codes (`EMPLOYEE_INACTIVE`, `NO_EFFECTIVE_ASSIGNMENT`,
  `PENDING_REQUEST`, `LIVE_CARD_EXISTS`) have their new names above.
- A replacement now meets the same employee rules as a new card when it is
  requested, not only at approval.

### 4. Duplicate review

- **Who decides.** A registrar's "a different person" is a statement, not the
  decision. Each pair is a pending review that someone else decides:
  `not_duplicate`, `confirmed_duplicate` or `needs_more_information`.
- **Existing decisions.** Earlier "distinct" decisions become `not_duplicate`
  with their registrar as decider (no review is invented). Migration:
  `2026_10_27_110000_extend_employee_duplicate_reviews`.
- **When matching runs.** Matching runs again after a change to a name, date of
  birth, phone, e-mail or national ID, never against the record itself. A
  national ID change is stored only as its hash and refused when another
  employee holds it.
- **Effect of a confirmed duplicate.** The flagged record is blocked from
  verification, approval and cards until a controlled merge. Merge itself is
  not built.

### 5. Granular permissions and policies

- **Roles.** Roles are rows (`core_roles`, `core_role_permissions`, migration
  `2026_10_27_120000_create_core_roles_tables`). The three system roles keep
  their capabilities, split into finer permissions:
  - each card step;
  - placements;
  - hierarchy preparing, approving and publishing;
  - imports;
  - roles.
- **City-wide permissions** count only for city-wide accounts: settings,
  integrations, hierarchy governance, roles management and deleting
  organizations.
- **Role safeguards.** A role cannot lose the last city-wide administration
  access, and nobody can remove it from their own role. System roles and roles
  still held are not deleted.
- **Policies** combine permission, organization scope (`OrganizationScope`,
  fail-closed) and record state. Actions keep the business invariants, and no
  role-name conditionals remain.
- **The users screen** encodes a role option as role id × 10 + 1 (for an
  organization) or + 2 (city-wide).

### 6. Business imports

- **Lifecycle.** Upload, then a queued validation, then preview and error
  review, then approval by a second person, then queued processing, then
  completed, failed or cancelled.
- **Rules reused.** Rows are validated with the Core's rules and processed
  through `SaveOrganization`, `SavePosition`/`SaveEstablishment`,
  `RegisterEmployee` and `TransferEmployee`, as the approver.
- **Retries.** A row is locked, written and marked processed in one
  transaction, so retries never repeat a row.
- **What enters as a draft:**
  - employees enter as drafts;
  - organizations enter as drafts (or active if the file says so);
  - their parents go into a hierarchy draft for the governed review, never the
    published hierarchy. Organization imports therefore need city-wide
    hierarchy permission.
- **Files and storage.**
  - CSV or .xlsx without macros, streamed (OpenSpout);
  - size and row limits are settings;
  - rows are stored encrypted;
  - the error report neutralizes formulas and masks national IDs.
- Migration: `2026_10_27_130000_create_import_batches_tables`.
- **Queues.** Production needs a queue worker (`QUEUE_CONNECTION=database`, as
  in `.env.example`). Local development may run `sync`.

### 7. Integrity

- One card request waiting per employee is a database guarantee (migration
  `2026_10_27_140000`).
- A replaced card already verifies as `CARD_REPLACED`.
- Two selectors leaked outside the scope and are fixed:
  - the unit form's parent options for another organization;
  - the dashboard's city-wide count of unplaced employees.

## Not in this gate

These are P3+, untouched: grade/occupation normalization, position status
history, future-dated placements, card templates, print batches, quality
check, digital credentials, audit sealing, break-glass, reports, notifications,
dashboard redesign, backups, OpenAPI and retention.
