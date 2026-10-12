# Operations Security Policy

**Project:** Addis One  
**Date:** 2026-05-27  
**Review cycle:** Annually or after any major incident

---

## 1. Administrative Responsibilities

| Role | Responsibility |
|---|---|
| System Administrator | User account creation/deactivation, server patching, backup monitoring |
| Security Lead | Quarterly vulnerability assessments, incident triage, policy updates |
| DevOps Engineer | Deployment pipeline, infrastructure monitoring, certificate management |
| IT Admin | Physical access control, hardware maintenance |
| Project Manager | Vendor/hosting agreement management, compliance reporting |

---

## 2. Emergency Communication

In the event of a security incident:

1. **Immediate (0–1 hour):** Contact Security Lead and System Administrator via dedicated emergency channel (Telegram group: EUISIS-Security-Emergency)
2. **Short-term (1–4 hours):** Security Lead notifies Project Manager and IT Director
3. **Escalation (4+ hours):** Project Manager notifies Addis Ababa City Administration IT Leadership
4. **External (if required):** Notify INSA via official channels within 24 hours of a confirmed breach
5. **Public/City Administration:** Communications drafted by IT Director only — no unauthorised statements

---

## 3. Content Management

- All user-visible content changes must go through the change management process (see `docs/change-management-policy.md`)
- System settings (logo, messages, localization) are managed via the Addis One admin UI by authorized System Settings administrators
- No content changes are to be made directly in the database
- Amharic text must be reviewed by an approved Amharic language reviewer before publishing

---

## 4. Documented Operation Procedures

| Procedure | Document | Location |
|---|---|---|
| Local development setup | `docs/runbook/local-development.md` | Repo |
| Production deployment | `docs/runbook/deployment.md` | (To be created) |
| Database backup and restore | `docs/backup-and-disaster-recovery.md` | Repo |
| Certificate renewal | Hosting provider runbook | (To be obtained) |
| Incident response | `docs/incident-response-plan.md` | Repo |
| Patch management | `docs/patch-management-plan.md` | Repo |

---

## 5. Formal Access Granting and Revocation

### Granting Access
1. HR/Project Manager submits access request to System Administrator
2. System Administrator creates the user account in Addis One (`/users/create`)
3. Appropriate role and organisation scope are assigned based on job function
4. MFA enrollment required for privileged roles (Super Admin, City Admin)
5. New user must change their initial password on first login
6. Access grant is recorded in the audit log

### Revoking Access
1. HR/Project Manager notifies System Administrator of termination or role change
2. System Administrator deactivates the account immediately (`/users/{user}/deactivate`)
3. System Administrator revokes all Sanctum API tokens
4. For privileged roles: verify all active sessions are terminated
5. Revocation recorded in audit log
6. Access review completed within 24 hours of notification

### Contractor Access
- Contractor accounts must be time-limited (set `effective_to` in UserOrganizationScope)
- Contractor access must be reviewed weekly
- All contractor access must be revoked on contract end date

---

## 6. Access Privilege Categories

| Category | Description | Roles |
|---|---|---|
| Viewer | Read-only access to assigned org data | Report Viewer, Auditor |
| Operator | Create/edit within assigned org | HR Officer, Settlement Officer |
| ID Card Officer | Manage ID card lifecycle | ID Card Officer |
| Cafeteria Operator | Access cafeteria scan and management | Service Provider User |
| Administrator | Manage users, settings, system config | Institution Admin, Sub-city Admin, Woreda Admin |
| City Administrator | Cross-org access within city | City Admin |
| Super Administrator | Full system access | Super Admin |

---

## 7. Credential Update Schedule

| Credential | Rotation Frequency | Notes |
|---|---|---|
| Admin account passwords | 90 days | Enforce via policy; Addis One does not auto-expire |
| APP_KEY | On compromise only | Document procedure; rotate all encrypted PII fields |
| Database password | 180 days | |
| Sanctum token expiry | 30 days (43200 min) | Configured via `SANCTUM_TOKEN_EXPIRATION_MINUTES` |
| SSL certificate | Before expiry (auto-renew) | Alert 30 days before |
| SMTP credentials | 180 days | |

---

## 8. Quarterly Vulnerability and Risk Assessment

**Schedule:** First Monday of each quarter (February, May, August, November)

**Activities:**
1. Run `composer audit` — document any findings
2. Run `npm audit` — document any findings
3. Review `docs/insa-secure-website-management-audit.md` — update status
4. Review open risks in `docs/security-hardening-plan.md`
5. Review access list — identify stale/excess permissions
6. Review audit logs for anomalies from the past quarter
7. Update this document if any procedures have changed
8. Security Lead signs off on quarterly report

---

## 9. Additional Security Testing Based on Risk

| Trigger | Action |
|---|---|
| New major feature (card, cafeteria, transfers) | Peer security review of the feature branch |
| Dependency with known CVE | Immediate update + test cycle |
| Hosting provider incident | Full security review of affected components |
| Failed pen test finding | Remediate within SLA; re-test before next release |
| INSA standard update | Re-audit affected areas within 30 days |

---

## 10. Periodic Testing and Auditing

| Activity | Frequency | Owner |
|---|---|---|
| INSA compliance audit | Annually | Security Lead |
| External penetration test | Annually | Contracted party |
| Internal vulnerability scan | Quarterly | DevOps |
| Backup restore test | Monthly | DevOps |
| Access rights review | Quarterly | System Admin |
| Incident response drill | Annually | All team |
| SSL certificate expiry check | Monthly automated | DevOps |
| Web monitoring review | Weekly | System Admin |

---

## 11. Web Monitoring for Breach / Misuse

Monitor the following for anomalies:
- `audit_logs` table: unusual `actor_user_id` or `event_type` patterns
- Authentication failures > 20/hour from a single IP
- Card verifications > 100/hour from a single provider
- 500 error spikes (potential exploit attempts)
- After-hours access to administrative functions
- Mass record access patterns (potential data exfiltration)

Alert channels: Email → Security Lead; Telegram → EUISIS-Security-Emergency group

---

*Owner: Security Lead*  
*Approved by: [TBD]*
