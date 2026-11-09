# Security documentation: the living set

This is the entry point. Everything listed here is kept current; a document
that is not listed is either a module page or a dated report in
[../archive/](../archive/README.md), which records what was true on the day it
was written and is never updated.

## Policy and requirements

| Document | What it fixes |
|---|---|
| [../security-requirements.md](../security-requirements.md) | The baseline every release must meet |
| [../governance/security-governance.md](../governance/security-governance.md) | Recurring work, access review, privileged and break-glass access, secrets, patches |
| [../operations-security-policy.md](../operations-security-policy.md) | What operators may do, and how |
| [../password-security-policy.md](../password-security-policy.md) | Passwords, breach check, lockout |
| [../security-mfa.md](../security-mfa.md) | MFA for privileged roles |
| [../hosting-security-requirements.md](../hosting-security-requirements.md) and [../infrastructure-security.md](../infrastructure-security.md) | The host, network and data-residency requirements |
| [../architecture/adr/0027-personal-data-protection-compliance.md](../architecture/adr/0027-personal-data-protection-compliance.md) | Personal data protection under Proclamation 1321/2024 |

## Checklists used at release and at go-live

| Document | When |
|---|---|
| [../production-security-checklist.md](../production-security-checklist.md) | Every release (`php artisan security:production-check`) |
| [../security-hardening-checklist.md](../security-hardening-checklist.md) | Host and application hardening at go-live |
| [../insa-security-testing-checklist.md](../insa-security-testing-checklist.md) | External security testing scope |
| [../security-attack-surface.md](../security-attack-surface.md) | The inventory the tests are planned from |
| [../go-live/penetration-test-scope.md](../go-live/penetration-test-scope.md) | Penetration test scope |

## Runbooks

- [../runbooks/data-subject-requests.md](../runbooks/data-subject-requests.md): access, rectification, erasure, objection; breach notification.
- [../runbooks/disaster-recovery.md](../runbooks/disaster-recovery.md), [../runbooks/restore-validation.md](../runbooks/restore-validation.md): recovery and the restore drill.
- [../key-recovery-runbook.md](../key-recovery-runbook.md): APP_KEY loss.
- [../production-monitoring.md](../production-monitoring.md): health probes, error tracker, logs.

## Per-module security pages

Each service module has a page named `<module>-security.md` next to its domain
documents (daily activity, employee feedback, employee transfer, field work,
grievance, intelligence, NFC, organization feedback form, public evidence,
public feedback, service monitoring, situation room, assessment). They describe
that module's threat model and controls and change with the module.

## Dated reports

Assessments, audits, score reports, gap analyses and hardening plans from
2026-06 to 2026-10 are in [../archive/](../archive/README.md). They are
evidence of what was found and fixed, not a description of the current state;
open items from them were carried into the checklists above or into
[../governance/technical-debt.md](../governance/technical-debt.md).
