# ADR 0010: Core platform P3 — workforce structure, card production and integrity

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation (ADR 0005), choosing the fail-closed option where the
business rule was not stated. Builds on ADR 0009.

## Decisions

### 1. Job masters

- Six master tables: `job_grades`, `job_levels`, `occupations`, `professions`,
  `employment_types`, `position_categories`. Each value has a unique code, an
  English and an Amharic name, an order and an active flag.
- **No deletion.** A value a position uses is deactivated, never deleted
  (`restrictOnDelete`). Only city-wide holders of `positions.master.manage`
  change values (`ManageJobMasters`), and every change is audited.
- **Choosing a value.** A new or changed reference must point at an active
  value. An existing reference to a value deactivated later is kept.
- **Grades in EUISIS forms.** The EUISIS position form sends `grade_level`,
  the grade code. It is mapped to the grade master, and an unknown code is
  refused (`UNKNOWN_GRADE` in imports). The free-text column follows the master
  so the EUISIS screens still read it.
- **Backfill** (`PositionMasterBackfill`, run by the migration and again after
  `core:import-from-euisis`):
  - every distinct free-text grade becomes a grade;
  - an occupation known only by id becomes a placeholder marked "to be named";
  - EUISIS occupations are copied first when the source has them.
  Nothing is invented and nothing is dropped.

### 2. Position lifecycle and history

- **Statuses.** draft → approved; approved ⇄ suspended; approved, suspended or
  draft → abolished (only when nobody holds the position); abolished →
  archived.
- **is_active** is kept in step, true only when approved, so every placement
  rule (TransferEmployee, approval, seats) refuses non-approved positions
  unchanged.
- **New positions.** A new position is approved when its creator holds
  `positions.approve`, otherwise it starts as a draft.
- **Reasons.** Suspend, reinstate and abolish need a reason.
- **History.** `position_histories` keeps one row per kind of material change:
  moved, retitled, regraded, reclassified, status_changed, dates_changed,
  recoded, capacity_changed. Each row records the fields from and to, the
  actor, the reason and the effective date. Rows are never updated.
- **Seats.** No new seats are approved for an abolished or archived position.

### 3. Placement provenance

- **New columns** on `employee_assignments`:
  - `assignment_type`: initial, transfer, promotion, demotion, reassignment or
    correction;
  - `reason_code`, from a fixed list;
  - `source_document_id`: must be a current, not rejected document of the same
    employee;
  - `created_by`, `approved_by` and `approved_at`;
  - `source_system` (core, api, import, legacy) and `external_reference`
    (unique per source);
  - `previous_assignment_id`;
  - `is_primary`.
- **Approval.** A first placement is approved when the employee is approved.
  A transfer is one step, so the person who records it is its approver.
- **Backfill.**
  - The type is read from the old reason ('registered' or 'transferred'),
    otherwise it is "unspecified".
  - The source is "legacy".
  - Each employee's placements are linked in date order.
- **One placement per employee.** The Core keeps one current placement per
  employee, and it is the primary one. Acting appointments and secondments
  (secondary placements) are **not supported**. Future-dated activation also
  stays unsupported: a transfer dated in the future is refused, as before.

### 4. Card templates

- **Layout.** Templates have numbered versions: draft → active → retired. A
  version's layout is bounded settings (`CardTemplateConfig`), never markup:
  - orientation;
  - four colours (#RRGGBB);
  - header and footer texts (no `<`, `>` or control characters);
  - the fields shown, which always include the employee number, the card
    number and the QR, and the photo while cards require one.
- **Activation.**
  - One version is active at a time: a partial unique index, or a generated
    column on MySQL.
  - Someone other than its designer activates a draft, and the version active
    before is then retired.
  - Retired versions are kept.
- **Default template.** A standard template is seeded active, so printing
  works before anyone designs one. Blank headers show the city name from the
  ID card settings.
- **One renderer.** `CardRenderer` draws SVG for:
  - the template preview (sample data);
  - the card preview;
  - the print sheet.
  Every text is escaped. Images are only data URIs of the employee's own
  verified photo or signature and of the QR. The responses carry a
  `default-src 'none'` CSP. The EUISIS client-side card preview is not used by
  the Core; its route name now opens the server-drawn preview.

### 5. Print batches, quality check and reprints

- **Batches.** A batch number comes from the code generator
  (`numbering.print_batch_number_format`, default `PB-{year}-{seq:5}`). A
  batch holds cards waiting for their first print, or printed cards whose
  reprint was approved. A card is in one open batch at most.
- **Template version.** A batch is printed with the template version active
  when it was made, and each card records the version it was printed with.
- **Marking printed.** Each card goes through the same `ChangeCardStatus`
  "print" step as single-card printing:
  - the print count goes up;
  - the printer is recorded;
  - the quality check resets to pending;
  - a credential is issued if the card has none.
  A spoiled card stays waiting.
- **Visibility.** A batch is visible only when all its cards are in the
  reader's scope. Opening the print sheet is audited.
- **Quality check** (`cards.quality_check`):
  - It is done by someone other than the card's printer.
  - A failure needs notes.
  - A card is issued only after passing, under the setting
    `id_cards.require_quality_check` (default on).
  - Cards printed before P3 and not yet issued must be checked. Cards already
    issued are "not recorded".
- **Reprints** (`cards.reprint` plus a reason):
  - only for a printed card that has not been issued;
  - at most `id_cards.max_reprints` times (default 2);
  - beyond that, the card is revoked and replaced.
  After issue, a damaged card is replaced, never reprinted.

### 6. Card credentials

- **Separate table.** `digital_credentials` holds credentials apart from cards,
  and a card has at most one active credential.
- **Token.** A credential is a random 256-bit token. The Core keeps:
  - its HMAC-SHA256 under a numbered key, which is what is looked up;
  - the token encrypted with the application key, so a reprint shows the same
    QR and a key change can re-hash it.
- **QR.** The QR holds only the check address and the token.
- **Keys** (`CORE_CREDENTIAL_KEYS`) are listed newest first. An older key is
  accepted until its grace date. `core:credentials-rekey` moves active
  credentials onto the current key. Without configuration, one key is derived
  from APP_KEY.
- **Revocation.**
  - Revoking, losing, damaging or replacing a card revokes its credential.
  - A new credential supersedes the old one.
  - Rotation (`digital_credentials.rotate`, with a reason) marks the card for
    reprint. For an issued card, a replacement is requested instead.
- **Verification** (`CredentialVerifier`). It answers one denial code:
  CREDENTIAL_UNKNOWN, CREDENTIAL_REVOKED, CREDENTIAL_SUPERSEDED,
  CREDENTIAL_EXPIRED, KEY_RETIRED, then the card's own reasons. The public
  check describes no card behind a credential denial. Successful checks are
  counted.
- **Legacy QR codes.** The migration gives every existing card a legacy
  credential whose token is its public UUID, so printed QR codes keep
  verifying. A card with no credential row at all is still found by its UUID.
  Once a card has any credential, only a credential token reaches it.
- **API.** The verification APIs accept `token` as well as `card_uuid`.

### 7. Audit tamper evidence

- **Sealing.** `core:audit-seal` runs every five minutes. It gives every
  unsealed row a sequence number, the SHA-256 of its stored content, and a
  chain hash (the previous chain hash folded with its own). It then signs the
  range in `audit_seals`, using HMAC with `CORE_AUDIT_SEAL_KEY`, derived from
  APP_KEY if unset. Each seal head also goes to the application log, so a
  copy lives outside the database.
- **No global lock.** Rows get hashed at seal time, not at write time, so audit
  writes never wait on each other. The window without hash evidence is the
  sealing interval.
- **Append-only.** A trigger makes `core_audit_logs` append-only on PostgreSQL
  and SQLite. It refuses deletion, and any update except the sealer's one-time
  filling of a row's sequence and hashes. On MySQL the trigger is created
  where the server allows it.
- **Verification.** `core:audit-verify` runs nightly and from the Security
  Center as a queued job. It recomputes every sealed row's hash, the chain,
  the sequence (no gaps) and every seal. It records the result, and logs a
  critical entry on a mismatch.
- **What this does not stop.** Someone who holds both database owner rights
  and the seal key can rewrite history. The off-database copy of the seal
  heads is the evidence against that. Keep the seal key apart from APP_KEY in
  production.

### 8. Break-glass access

- **Request.** A person with `break_glass.request` asks for named permissions
  over one organization (and everything under it) or the whole city. The
  request gives a number of minutes, up to `security.break_glass_max_minutes`
  (default 240), and a reason of at least 20 characters.
- **Never granted this way:** managing roles, accounts and settings,
  integrations, the audit seal check, and break-glass itself.
- **Decision.** Someone else with `break_glass.approve` approves or rejects it.
  Approval starts the grant at once.
- **While in force.** The grant adds its permissions in `CorePermissions::for`,
  and widens the person's scope in `OrganizationScope`. Both read the grant
  once per request and check its time.
- **End.** It ends at its time even before `core:break-glass-expire` records
  it, or earlier by its holder or a `break_glass.revoke` holder.
- **Audit.** Every audit row written under a grant names it
  (`break_glass_grant_id`, part of the sealed content).
- **Notifications.** Approvers are not notified by message: the Security
  Center shows pending requests. Notifications are P4.

### 9. Webhook and API reliability

- **Suspension.** `consecutive_failures` counts failed attempts since the last
  success. At `integration.webhook_suspend_after` (default 20) the endpoint is
  suspended, with when and why, and audited. Its pending deliveries wait. An
  administrator resumes it (or switches it on), which resets the count and
  sends the waiting deliveries.
- **Secret rotation.** A rotated signing secret keeps signing alongside the new
  one for `integration.webhook_secret_grace_hours` (default 24). The header
  then carries two `v1=` values.
- **Retention** (`core:prune-integration-data`, nightly):
  - an event is removed after `integration.outbox_retention_days` (default
    90), only when none of its deliveries is pending or failed;
  - API call logs are removed after `integration.api_log_retention_days`.
- **Usage.** The Integration Center shows, per client: calls, errors, denials
  and mean time per call over 24 hours and 7 days.
- **Security fix.** The web Integration Center's write endpoints checked only
  `integrations.view` at the route. They now require `integrations.manage`,
  city-wide (the existing policies). The API side was already limited to
  city-wide administrators.

## Not done here (P4/P5 and beyond)

- Secondary placements and future-dated activation.
- Notifications to approvers.
- Hardware security modules for keys.
- External anchoring of seal heads beyond the application log.
