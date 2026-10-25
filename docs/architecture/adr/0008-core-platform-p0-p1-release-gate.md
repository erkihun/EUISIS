# ADR 0008: Core platform P0/P1 release gate

Status: accepted 2026-10-25. Decided by the engineering lead under the project
owner's delegation (ADR 0005). Where the business rule could not be inferred,
the fail-closed option was taken. Applies to the standalone Core platform
(`core-platform/`, ADR 0006) and builds on ADR 0007.

## Context

The gap matrix (`core-platform/docs/golden-path-gap-matrix.md`) left one P0 and
five P1 blockers before the Core could replace EUISIS as the writer of master
data. They are:

- organization scope that defaulted to the whole city;
- no verified-employee stage;
- no employee approval;
- no second factor;
- no screens for the governed hierarchy;
- no screens for the Integration Center.

All six are implemented with regression tests. The rules they needed are
recorded here.

## Decisions

### 1. Organization scope is explicit (P0)

- `users.scope_type` and `api_clients.scope_type` take one of three values:
  - `citywide`: the whole city;
  - `subtree`: an organization and everything under it;
  - `self`: that organization only.
- `null` means **no access**. An empty scope is never read as "everything".
- Migration `2026_10_26_100000_make_organization_scope_explicit` backfills the
  existing rows:
  - an account or client with no organization becomes `citywide`, because that
    was its effective access before;
  - every other account or client becomes `subtree`.
- An account without scope reaches only sign-out, password, MFA, its profile and
  the dashboard, where it is told why. Everything else answers 403
  `NO_ORGANIZATION_SCOPE`, on the screens and on `/api/v1`.
- A record outside the reader's scope is not found (404). Scope-aware route
  bindings run after the scope check (middleware priority), for:
  - organizations, units, positions and establishments;
  - employees;
  - cards and card requests.
- Only a city-wide administrator grants city-wide access. The users screen
  offers "City-wide …" role variants for that.
- An administrator may not grant beyond their own reach, nor narrow their own
  scope.
- Any change to an account's access ends its tokens and sessions.
- `core:create-user` takes `--citywide`, `--organization=<code>` and `--self`.
  Without one of them the account has no access.

### 2. The verified-employee stage (P1)

- A new employee is a `draft` with a *pending* placement. Draft details can be
  edited.
- The steps are:
  - `submitted` (an officer);
  - `verified` (another officer, with the checklist);
  - `active` (approval).
- A submitted or verified record can be returned to draft with a reason.
- Records that existed before the stage are `legacy_approved`. They keep their
  status, as migration `2026_10_26_110000_add_employee_verification_workflow`
  sets it.
- Separation of duties:
  - the submitter may not verify;
  - the approver may be neither the registrar, the submitter nor the verifier.
- Verification refuses a record with an unreviewed possible duplicate.
- Approval (`employees.approve`, administrators) activates the pending
  placement. It first checks again that the position is active, belongs to the
  organization and has a free seat.
- Card eligibility requires an approved employee (`EMPLOYEE_NOT_APPROVED`).
- The integration API and the events publish approved employees only.
  `employee.approved` is announced to subscribers as `employee.created` and
  `assignment.created`.
- The photo and document checks exist as settings
  (`workforce.verification_requires_photo`, `…_documents`). They are off, since
  the document system is P2.

### 3. Multi-factor authentication (P1)

- TOTP (RFC 6238) is the second factor. The secret is encrypted at rest.
- Eight recovery codes are issued. They are stored hashed, shown once, and each
  works once.
- Who must use it is a Core setting per category. The defaults are:
  - city-wide administrators: required;
  - organization administrators: required;
  - officers: required;
  - viewers: optional.
- A person who enrolled voluntarily is challenged too, and may switch off only
  a factor their category does not require.
- Every page, Inertia visit and XHR of the screens passes `RequireMfa`. Only the
  MFA pages, sign-out, the forced password change and the session heartbeat
  (`session.*`) are exempt. A direct URL, another tab or the browser history
  cannot skip it.
  - A refused write answers 303.
  - JSON callers get 403 `MFA_REQUIRED` or `MFA_ENROLLMENT_REQUIRED`.
- A session passes the challenge once, for the Core session length. The session
  id is renewed after enrolment and after each challenge.
- A code is accepted once: its 30-second step must be newer than the last
  accepted one. Concurrent attempts are serialized by a row lock. Attempts are
  throttled (6 a minute).
- API sign-in asks for the code (`MFA_CODE_REQUIRED`) and names the token
  `core-frontend+mfa`. When MFA applies, a token without that mark reaches
  nothing but sign-out. Tokens issued before the requirement was switched on
  therefore stop working.
- A lost device is reset in one of two ways, and either way the holder enrols
  again:
  - by an administrator who manages the account
    (`POST /api/v1/users/{id}/mfa-reset`, with a reason);
  - on the server (`core:reset-mfa`), for when no administrator can sign in.
- The audit records `mfa.enrolled`, `mfa.challenge_failed`,
  `mfa.recovery_used`, `mfa.reset` and `mfa.disabled`. No secret or code is
  ever audited, logged, returned by the API or sent to the page after
  enrolment.
- `config('core.mfa_enforce')` (`CORE_MFA_ENFORCE`, default **true**) exists for
  the test suite: it runs with MFA off and switches it on in the MFA tests. In
  production, System health reports an error if it is off.
- Migration: `2026_10_26_120000_add_multi_factor_authentication_to_users`.

### 4. The governed hierarchy on the screens (P1)

- City-wide administrators work on the EUISIS screens, using the routes and
  paths of HierarchyVersions:
  - Create, Edit and EditTree;
  - the relation modal and its organization search.
- Every change goes through `ManageHierarchyVersion`, the same action as the
  API.
- A relation is an organization's one structural parent (`reports_to`):
  - adding a second parent is refused;
  - editing a relation moves the organization;
  - removing a relation makes the organization a root of that version.
- A relation takes its version's effective date, and a version's end date is
  set when the next version is published. Both date fields are refused rather
  than silently ignored.
- The approver must be a city-wide administrator who did not prepare, change or
  submit the version. "Changed" is read from the audit (`hierarchy.updated`,
  `hierarchy.parent_changed`). The rule is `approvalRefusal()`, the same for the
  API and the screens.
- Publication follows ADR 0007: on the effective date, and only while the
  version it was copied from is still in force (`publicationRefusal()`).
- The Show page gains an optional `governance` panel, for city-wide
  administrators only. It shows:
  - the steps, with who did each and when;
  - the impact of a pending version;
  - only the actions the server allows, all of them confirmed.
- Only a draft is edited. Other states redirect with a message, and the action
  refuses them.

### 5. The Integration Center on the screens (P1)

- EUISIS `Core/IntegrationCenter` gains an optional `center` prop with five
  tabs:
  - API clients;
  - webhooks;
  - deliveries, with a manual retry;
  - the event log;
  - the API call log.
- It is for city-wide administrators (`integrations.view`,
  `api_management.view`). The navigation shows it only to them.
- The API and the screens share `ManageIntegrations` (changes and audit) and
  `IntegrationCenter` (reads). Nothing is duplicated in React.
- A created or rotated secret is in clear only in the one-time flash
  `integration_secret_once` that follows the action. It is never in later
  props, the API listings or the audit. The client stores only the token hash.
- Session data is **encrypted by default** (`SESSION_ENCRYPT`, default `true`),
  because one-time secrets pass through the session between the action and the
  page.
- A revoked client stays revoked. Webhooks are not tied to an API client: they
  are the Core's own subscriptions, scoped by organization.

## Consequences

- Existing accounts keep the access they had (the scope backfill). Existing
  employees stay approved. No data is rewritten.
- Privileged accounts enrol at their next sign-in. Integrations that sign in as
  a privileged account must send the code.
- Turning session encryption on signs everyone out once.

## Not in this gate (P2+)

These are untouched, as the gate brief required:

- employee documents, photos and signatures;
- business imports;
- the granular permission redesign;
- card templates, print batches and NFC;
- break-glass access;
- reports, exports and notifications;
- OpenAPI generation;
- retention policies.
