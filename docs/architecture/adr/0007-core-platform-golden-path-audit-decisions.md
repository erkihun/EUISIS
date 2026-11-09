# ADR 0007: Core platform decisions from the Golden Path audit

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation (ADR 0005), choosing the integrity-first, compatibility-safe
option each time. Applies to the standalone Core platform (`core-platform/`,
ADR 0006).

## Context

An end-to-end audit of the standalone Core (organization → hierarchy → unit →
position → employee → placement → card → verification) found defects and gaps.
The confirmed defects are fixed in code with regression tests. Several fixes
needed a rule choice; they are recorded here so the code has one rule each.

## Decisions

1. **Audit subjects are strings.** `core_audit_logs.subject_id` was a UUID
   column, but accounts have integer ids and settings groups are named by key.
   On PostgreSQL every account and settings change failed. The column is now
   `varchar(64)`; existing values keep their text. Audit rows also record their
   source (`frontend`, `api_client`, `console`) and the API client, and known
   secret keys are masked whatever a caller passes.
2. **Hierarchy history is never rewritten.** The governed path is a version:
   draft (a copy of the structure in force), review, approval by a *second*
   city-wide administrator, publication on its effective date. Publishing
   archives the version in force (it stays queryable: `GET /v1/hierarchy?on=`).
   Scheduled and retrospective publication are not supported yet. The immediate
   parent change on the organization form stays for daily work, but it now
   publishes a snapshot of the structure (one per day), so the structure of each
   earlier day is preserved.
3. **Leaving service and deletion.** An organization may become inactive,
   merged, dissolved or archived only when no child organization, unit,
   position or placement under it is still active; the refusal names every
   reason. It may be deleted (soft, with a reason, code kept reserved) only when
   nothing ever referred to it: no hierarchy version of any status, unit,
   position, establishment, placement (past or present) or scoped account.
4. **National ID.** The Core keeps only a keyed hash of a national ID, used to
   refuse a second record for the same person; the number itself is not stored.
   The key is `NATIONAL_ID_HASH_KEY`, the same variable EUISIS uses, so imported
   and new hashes compare when it is shared.
5. **Duplicates are never merged automatically.** A registration that matches
   an existing employee (same name and date of birth = strong; same name, phone
   or e-mail = possible) stops with `409 POSSIBLE_DUPLICATE`. The officer either
   stops or records that it is a different person with a reason; the decision is
   audited and kept in `employee_duplicate_reviews`. Data Quality lists
   name-and-birth-date pairs that have no decision. A match outside the officer's
   organizations shows only its number and name.
6. **One-time passwords are replaced at first sign-in.** Accounts made by an
   administrator or `core:create-user`, and password resets, must choose their
   own password before anything else (`403 PASSWORD_CHANGE_REQUIRED`). Policy
   defaults, changeable in Core settings › Security: 12 characters, upper and
   lower case and a digit, no fragment of the name or e-mail, none of the last 5
   passwords. These are engineering defaults, not a legal requirement. Nobody
   may remove the last active city-wide administrator or narrow their own access.
7. **Integrations read; they never write.** As in ADR 0002, machine clients get
   read scopes and the card check only. Assignment changes and other commands
   stay with Core officers, so no external write endpoint (and no idempotency
   key) exists yet. A future command API needs its own ADR, idempotency keys and
   an approval step.
8. **Events come from the audited change.** Each audited action that other
   systems care about maps to integration events written in the same
   transaction (outbox). Payloads carry identifiers, codes, statuses and dates;
   `employee.updated` names the changed fields, never their values. Webhooks are
   signed `v1=HMAC-SHA256("<timestamp>.<body>")`, delivered after commit, and
   retried after 1, 5, 15, 60, 180, 360 and 720 minutes.
9. **Card photo rule is off by default.** The Core has no photo upload yet
   (photos come with imported records), so requiring a photo would block every
   card for a person registered in the Core. `id_cards.require_photo` turns the
   rule on once photos are managed here.
10. **"Transferred" means transferred out of the city service.** It is a
    separation status (placement closed, live cards suspended). A move inside the
    city is a transfer of placement; the employee stays `active`.
11. **Expired cards say so.** `core:expire-cards` (daily) marks cards past their
    expiry date as `expired`, audited; verification refused them already.

## Not decided here (needs people, not code)

- Whether MFA is mandatory for administrators, approvers and card officers, and
  with which second factor (MFA is not implemented in the Core yet).
- Retention periods for audit rows, API call logs, events and deliveries
  (nothing is purged automatically).
- Who is named as the second hierarchy approver and the card approvers.

## Consequences

- Migrations `2026_10_25_180000` to `2026_10_25_200000` are additive; the only
  type change widens `subject_id` without changing a value.
- Consumers follow `core-platform/docs/integration-api.md`.
- Still outside the Core platform (stay in EUISIS or are future work): employee
  documents, card templates, print batches and the quality-check step, imports,
  reports and exports, notifications, MFA and break-glass access.
