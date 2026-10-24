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
| Monthly | partner access review | `api:access-review` |
| Quarterly | user access recertification per organization | Users and scopes pages; Audit Center |
| Quarterly | vulnerability and risk assessment | [../operations-security-policy.md](../operations-security-policy.md) §8 |
| Yearly and after major change | external penetration test of Core and `/api/v1` | [../vulnerability-management.md](../vulnerability-management.md) §6 |

## Open decisions

| Decision | Current behaviour | Risk | Owner |
| --- | --- | --- | --- |
| Users with no organization-scope rows | treated as not yet scoped: see every organization | an account created without scope rows is city-wide | security owner with business owner; needs a data clean-up before tightening |
| Penetration test | not performed | unknown exposure | security owner |
| Legacy card QR acceptance (`ID_CARD_ACCEPT_LEGACY_QR`) | on until reprint | card id alone can be presented | ID-card office |

## Incidents

[../incident-response-plan.md](../incident-response-plan.md); revoke partner
access through the scope or the application; key recovery
[../key-recovery-runbook.md](../key-recovery-runbook.md).
