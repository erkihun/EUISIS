# Archived documents

Dated reports and superseded plans. They are kept as evidence of what was found
and decided at the time; **they are not updated** and must not be read as the
current state. For the current state start at
[../security/README.md](../security/README.md) and the governance folder.

| Document | Written | Superseded by |
|---|---|---|
| security-gap-analysis.md | 2026-06 | security-requirements.md, the checklists |
| security-remediation-roadmap.md | 2026-06 | governance/technical-debt.md |
| security-score-report.md | 2026-06 | production-security-checklist.md |
| rbac-security-audit.md | 2026-08 | rbac-architecture.md, ADR 0026 |
| security-assessment-report.md | 2026-09 | production-security-checklist.md |
| security-assessment-phase2.md | 2026-09 | production-security-checklist.md |
| security-boundary-hardening-report.md | 2026-09 | security-attack-surface.md; its tests remain in tests/Feature/Security |
| backup-recovery-handoff.md | 2026-09 | backup-recovery-architecture.md, runbooks/ |
| security-audit.md | 2026-10 | security/README.md |
| security-hardening-plan.md | 2026-10 | security-hardening-checklist.md |
| assessment-production-readiness.md | 2026-10 | go-live/go-live-gates.md |
| assessment-uat-readiness.md | 2026-10 | go-live/uat-plan.md |
| assessment-performance-test-plan.md | 2026-10 | load-testing.md, capacity-plan.md |
| assessment-appeals.md, assessment-moderation.md, assessment-result-correction.md, assessment-result-versioning.md | 2026-10 | stubs folded into assessment-oversight.md and assessment-scoring.md |

Adding to this folder: move the file with `git mv`, add a row here, and fix
inbound links (grep the file name across docs/, app/ and tests/).
