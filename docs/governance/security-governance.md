# Security governance

Continuous security for Core and the services. Detailed controls are in the
existing security documents ([../security-requirements.md](../security-requirements.md),
[../rbac-architecture.md](../rbac-architecture.md),
[../production-security-checklist.md](../production-security-checklist.md),
[../vulnerability-management.md](../vulnerability-management.md)).
Owner: Information Security Lead ([roles-register.md](roles-register.md)).

## Principles that do not change

- Permission = what, organization scope = where; both on every page and API.
- Partners read only what their scopes and organization bounds allow; machines never write master data.
- Public QR stays privacy-minimised.
- No service gets database credentials; no secret in code, Git, logs, tickets, chat or documents.
- Sensitive-field redaction stays on.

## Recurring work

| Cadence | Task | Tool / record |
| --- | --- | --- |
| Every release and in CI | dependency audit | `composer audit --locked`, `npm audit --audit-level=high`; result in the release note |
| Every release | production gate | `security:production-check`, `production:readiness --strict` |
| Daily (scheduled) | audit-log seal | `audit:seal`; `audit:verify` on demand and in `production:readiness` (last 7 days) |
| Weekly | security-log review: failed sign-ins, refused API calls, scope refusals, MFA failures | Audit Center, `api_request_logs`, application log |
| Monthly | privileged-account review | `access:review` (stale sign-in, missing MFA, disabled holders) |
| Monthly | partner access and credentials | `api:access-review`, `api:consumers` |
| Monthly | accounts without an explicit scope | `access:scope-gaps` |
| Quarterly | user access recertification per organization | Users and scopes pages; `access:review`; Audit Center |
| Quarterly | full audit-log verification | `audit:verify` |
| Quarterly | vulnerability and risk assessment | [../operations-security-policy.md](../operations-security-policy.md) §8 |
| Quarterly | secrets rotation check | [Secrets](#secrets) table |
| Yearly and after major change | independent penetration test of Core and `/api/v1` | [../go-live/penetration-test-scope.md](../go-live/penetration-test-scope.md) |

Static analysis (Larastan/PHPStan, Semgrep) is not installed yet
([technical-debt.md](technical-debt.md) TD-12).

## Access review

Reviewed groups: Super Admin, System Admin, City Admin, Public Service Bureau
Admin and every other MFA-required role (`access:review`); Core and
organization administrators, HR officers, ID officers and auditors (Users page
per organization); API clients (`api:consumers`, `api:access-review`);
provider users (Service Providers pages). For each account: business need,
organization scope, last use, privilege level. Stale access is disabled, not
deleted, so its audit history stays. Each review is recorded in the change or
review ticket with the date, reviewer and decisions; the reports print ids,
names and roles only.

## Privileged access

- MFA is required for every role in `MFA_REQUIRED_ROLES` (baseline: Super Admin, System Admin, City Admin, Public Service Bureau Admin, Cafeteria Admin) and can be extended in System Settings › Security.
- Super Admin accounts are few (two named people is the target), personal, never shared, never demo accounts.
- Role and scope changes are audited; separation of duties holds for card approval and change-request approval.
- Not built yet: approval-gated, time-limited elevation (step-up) for sensitive administration ([technical-debt.md](technical-debt.md) TD-17).

## Break-glass access

There is no hidden or permanent emergency account, and none may be created.
Emergency access is a named person's normal account temporarily given the
needed role, with: the reason in the change ticket, approval by the Incident
Manager (or after the fact within two working days when no one can be
reached), the change visible in the audit log, removal of the role when the
incident is closed, and review of what was done (Audit Center) in the incident
review.

Since 25 October 2026 the grant is made with
`php artisan access:elevate <email> "<role>" --minutes=<1-480> --reason="…" --approved-by=<second person's email>`:
the approver must be another account, the reason is required, and the role is
removed automatically when the time is up (`access:expire-elevations`, every
minute). Both the grant and the removal are audit events
(`temporary_role_granted`, `temporary_role_ended`); a role the account already
held is never granted or removed this way.

## Secrets

Rotation is tracked here (dates and owners only, never values). A secret that
has been shown outside its vault (chat, e-mail, a ticket, a screenshot) is
rotated at once.

| Secret | Owner | Rotation | Last rotated |
| --- | --- | --- | --- |
| Database credentials | Database/Infrastructure Administrator | yearly and on exposure | NOT_RECORDED |
| `APP_KEY` | Technical Custodian | only with `APP_PREVIOUS_KEYS` and a rehash plan ([../key-recovery-runbook.md](../key-recovery-runbook.md)) | NOT_RECORDED |
| National-ID hash key | Information Security Lead | only with `security:rehash-national-ids` | NOT_RECORDED |
| Partner API tokens | Integration/API Owner | before expiry; on offboarding | per token (`api:consumers`) |
| Webhook signing secrets | Integration/API Owner | yearly and on exposure | NOT_RECORDED |
| Object storage keys | Technical Custodian | yearly | NOT_RECORDED (not used yet) |
| SMS provider credentials | Technical Custodian | yearly and on exposure | NOT_RECORDED |
| Mail credentials | Technical Custodian | yearly and on exposure | NOT_RECORDED |
| AI provider keys | Technical Custodian | yearly and on exposure | NOT_RECORDED (AI off by default) |

## Security patches

Tracked components: Laravel and PHP packages, PHP itself, MySQL/MariaDB (and
PostgreSQL where used), Redis, Node and the npm tree, the operating system
packages of every server. Critical advisories are patched within 24 hours,
high within 72 hours ([../vulnerability-management.md](../vulnerability-management.md) §3),
through an EMERGENCY or PATCH release; a critical fix is never held back for
the release calendar. Dependency state on 24 October 2026: every direct
dependency on its latest release, `composer audit` and `npm audit` clean.

## Penetration test findings

Findings are tracked by severity (Critical, High, Medium, Low), each with an
owner, the fix, the verification (retest evidence) and, where accepted, the
risk acceptance signed by the System Owner and Information Security Lead. An
unresolved Critical or High is never marked closed and blocks go-live (gate
G4). No penetration test has been performed yet.

## Decisions taken (24 October 2026, security first)

| Decision | Behaviour now | Record |
| --- | --- | --- |
| Accounts with an organization-level role and no scope record | reach only their linked employee's current organization, or nothing; only accounts whose roles are all global (and Super/System/City Admin) stay city-wide. `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT=false` is a transition switch only and fails `production:readiness --strict` | [../architecture/adr/0003-security-hardening-after-core-stages.md](../architecture/adr/0003-security-hardening-after-core-stages.md) |
| Audit-log tamper evidence | every finished day sealed with a keyed checksum chained to the day before; `audit:verify` detects changed, added or removed rows | same ADR |
| Legacy card QR (card id only) | refused by default (`ID_CARD_ACCEPT_LEGACY_QR=false`); switch on only where a reprint is still pending, and `production:readiness` warns while it is on | decision D-3 |

## Open

| Item | State | Owner |
| --- | --- | --- |
| Penetration test of Core and `/api/v1` | not performed | Information Security Lead |
| Accounts given permissions directly without a role | still unscoped; listed by `access:scope-gaps` as DIRECT_PERMISSIONS_UNSCOPED; give them a role and a scope | Information Security Lead |
| Partner tokens live 8 hours (global `SANCTUM_TOKEN_EXPIRATION_MINUTES`) with no per-application lifetime | decide a lifetime and rotation per application ([technical-debt.md](technical-debt.md) TD-16) | Integration/API Owner |
| Time-limited elevation and break-glass expiry | built 2026-10-25 (`access:elevate`, `access:expire-elevations`); a web screen for it is not built | Information Security Lead |

## Incidents

Every significant incident gets a review: timeline, impact, root cause,
containment, recovery, corrective actions with owners and dates. The review
looks for system improvements, not blame
([../incident-response-plan.md](../incident-response-plan.md)). Revoke partner
access through the scope or the application; key recovery
[../key-recovery-runbook.md](../key-recovery-runbook.md).

The quarterly trend review looks across incidents and logs for repeated
authorization refusals, queue failures, API errors, database incidents,
event-delivery failures and card-verification outages, and turns patterns
into debt items or ADRs.
