# Security governance

Continuous security for Core and the services. Detailed controls are in the
existing security documents ([../security-requirements.md](../security-requirements.md),
[../rbac-architecture.md](../rbac-architecture.md),
[../production-security-checklist.md](../production-security-checklist.md),
[../vulnerability-management.md](../vulnerability-management.md)).

## Principles that do not change

- Permission = what, organization scope = where; both on every page and API.
- Partners read only what their scopes and organization bounds allow; machines never write master data.
- Public QR stays privacy-minimised.
- No service gets database credentials; no secret in code, Git, logs, tickets or documents.
- Sensitive-field redaction stays on.

## Recurring work

| Cadence | Task | Tool / record |
| --- | --- | --- |
| Every release | dependency audit | `composer audit`, `npm audit --omit=dev`; result in the release notes |
| Every release | production gate | `security:production-check`, `production:readiness --strict` |
| Daily (scheduled) | audit-log seal | `audit:seal`; `audit:verify` on demand and in `production:readiness` (last 7 days) |
| Monthly | partner access review | `api:access-review` |
| Monthly | accounts without an explicit scope | `access:scope-gaps` |
| Quarterly | user access recertification per organization | Users and scopes pages; Audit Center |
| Quarterly | full audit-log verification | `audit:verify` |
| Quarterly | vulnerability and risk assessment | [../operations-security-policy.md](../operations-security-policy.md) §8 |
| Yearly and after major change | external penetration test of Core and `/api/v1` | [../vulnerability-management.md](../vulnerability-management.md) §6 |

## Decisions taken (24 October 2026, security first)

| Decision | Behaviour now | Record |
| --- | --- | --- |
| Accounts with an organization-level role and no scope record | reach only their linked employee's current organization, or nothing; only accounts whose roles are all global (and Super/System/City Admin) stay city-wide. `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT=false` is a transition switch only and fails `production:readiness --strict` | [../architecture/adr/0003-security-hardening-after-core-stages.md](../architecture/adr/0003-security-hardening-after-core-stages.md) |
| Audit-log tamper evidence | every finished day sealed with a keyed checksum chained to the day before; `audit:verify` detects changed, added or removed rows | same ADR |
| Legacy card QR (card id only) | refused by default (`ID_CARD_ACCEPT_LEGACY_QR=false`); switch on only where a reprint is still pending, and `production:readiness` warns while it is on | decision D-3 |

## Open

| Item | State | Owner |
| --- | --- | --- |
| Penetration test of Core and `/api/v1` | not performed | security owner |
| Accounts given permissions directly without a role | still unscoped; listed by `access:scope-gaps` as DIRECT_PERMISSIONS_UNSCOPED; give them a role and a scope | security owner |

## Incidents

[../incident-response-plan.md](../incident-response-plan.md); revoke partner
access through the scope or the application; key recovery
[../key-recovery-runbook.md](../key-recovery-runbook.md).
