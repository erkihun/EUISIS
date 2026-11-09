# Incident Response Plan

**Project:** Addis One — Addis Ababa City Administration Addis Ababa Integrated Employee Management & Service System  
**Date:** 2026-05-27  
**Review cycle:** Annually; rehearsal drill annually

---

## 1. Purpose

This plan defines the procedures for detecting, containing, eradicating, and recovering from security incidents affecting the Addis One system, including data breaches, service disruptions, and unauthorized access.

---

## 2. Incident Response Team

| Role | Responsibility | Contact Method |
|---|---|---|
| Security Lead | Incident commander; triage, escalation decisions | Telegram emergency group + direct call |
| System Administrator | Containment actions, account management, log analysis | Telegram emergency group |
| DevOps Engineer | Infrastructure actions, deployment, backup restore | Telegram emergency group |
| Project Manager | External communications, management escalation, INSA notification | Direct call |
| IT Director | Authorises public/official communications | Direct call |

**Emergency channel:** Telegram group `EUISIS-Security-Emergency`

---

## 3. Incident Severity Classification

| Level | Description | Examples | Response SLA |
|---|---|---|---|
| **P1 — Critical** | Active breach, data exfiltration, full service outage, ransomware | Employee PII accessed by attacker, database dumped, system unavailable | Respond within 1 hour; contain within 4 hours |
| **P2 — High** | Confirmed vulnerability being exploited, partial service disruption, unauthorised admin access | Brute-force succeeding, privilege escalation detected | Respond within 2 hours; contain within 8 hours |
| **P3 — Medium** | Suspected intrusion, anomalous behaviour, minor service degradation | Unusual audit log patterns, unexpected 500 spike | Respond within 4 hours; investigate within 24 hours |
| **P4 — Low** | Policy violation, failed attack attempt, non-critical configuration gap | Single failed brute-force attempt, self-signed cert warning | Document and address in next sprint |

---

## 4. Incident Response Phases

### Phase 1 — Identification (0–1 hour)

**Triggers:**
- Alert from web monitoring (audit log anomaly, auth failure spike, 500 spike)
- Report from a user or administrator
- Alert from hosting provider or INSA
- Automated monitoring alarm

**Actions:**
1. Security Lead or System Admin receives alert
2. Classify incident severity (P1–P4)
3. Record initial findings: timestamp, what was observed, affected system/user
4. Notify full incident response team via Telegram emergency group
5. Do **not** immediately shut down the system unless data is actively being exfiltrated — preserve evidence

---

### Phase 2 — Containment (1–4 hours for P1)

**Short-term containment (preserve evidence first):**
1. Take a read-only snapshot of relevant logs before any changes:
   - `audit_logs` table (SQL dump of relevant rows)
   - Web server access logs
   - Laravel `storage/logs/laravel.log`
2. If an account is compromised: deactivate the account in Addis One (`/users/{user}/deactivate`) and revoke all Sanctum tokens
3. If IP-based attack: co-ordinate with hosting provider to block IP at firewall level
4. If active session abuse: run `php artisan session:flush` (all sessions) or target the specific session ID in the `sessions` table

**Long-term containment:**
5. If a code vulnerability is confirmed: take the application into maintenance mode (`php artisan down`)
6. Apply emergency patch via the emergency change process (see `docs/change-management-policy.md` §4.5)
7. Re-enable application once patch is verified

---

### Phase 3 — Eradication

1. Identify root cause (code vulnerability, misconfiguration, credential compromise, social engineering)
2. Remove any persistence mechanisms (malware, backdoor accounts, injected code)
3. Rotate compromised credentials:
   - `APP_KEY` if encryption compromise suspected (requires re-encryption of all encrypted PII fields)
   - `DB_PASSWORD`
   - Sanctum tokens for affected users
   - SMTP credentials if mail was abused
4. Apply all available security patches
5. Run `composer audit` and `npm audit` — update any vulnerable dependencies
6. Verify no unauthorised admin accounts exist

---

### Phase 4 — Recovery

1. Restore from backup if data integrity is in doubt (see `docs/backup-and-disaster-recovery.md`)
2. Bring application back online from maintenance mode: `php artisan up`
3. Monitor closely for 24–72 hours after recovery:
   - Watch `audit_logs` for repeat anomalies
   - Watch authentication failure rates
   - Watch 500 error rates
4. Confirm all affected user accounts have had passwords reset
5. Confirm MFA is re-enrolled for all privileged users if tokens were compromised

---

### Phase 5 — Post-Incident Review

Within 5 business days of incident resolution:

1. **Timeline reconstruction:** Document the full incident timeline from first sign to resolution
2. **Root cause analysis:** Document the technical and process root cause
3. **Impact assessment:** What data was accessed? Which users were affected? Duration of outage?
4. **Lessons learned:** What detection failed? What response was slow?
5. **Action items:** Specific, assigned remediation tasks with due dates
6. **Update documentation:** Update this plan, security hardening plan, and INSA audit as needed

Post-incident report written by Security Lead and signed by Project Manager.

---

## 5. Communication Protocol

| Audience | Who communicates | Timing | Method |
|---|---|---|---|
| Internal team | Security Lead | Immediately | Telegram emergency group |
| Project Manager + IT Director | Security Lead | Within 1 hour of P1/P2 | Direct call + Telegram |
| Addis Ababa City Administration IT Leadership | Project Manager | 4+ hours if unresolved | Official memo/call |
| INSA | Project Manager | Within 24 hours of confirmed breach | Official channels |
| Affected employees/users | IT Director only | After scope is confirmed | Official City Administration announcement |

**No unauthorised communications:** Only IT Director may issue public or city administration statements about an incident.

---

## 6. Evidence Preservation Checklist

When a P1 or P2 incident is confirmed:

- [ ] Export affected rows from `audit_logs` to a secure file
- [ ] Export web server access logs for the incident window
- [ ] Export `storage/logs/laravel.log` for the incident window
- [ ] Export `sessions` table snapshot
- [ ] Screenshot any anomalous admin UI behaviour
- [ ] Note all IP addresses and user accounts involved
- [ ] Do not delete or alter any logs until investigation is complete
- [ ] Store evidence copies in a location inaccessible to the potentially compromised system

---

## 7. Key Commands Reference

```bash
# Take application offline
php artisan down --message="Maintenance in progress. Please try again shortly."

# Bring application back online
php artisan up

# Flush all sessions (emergency)
# DELETE FROM sessions; (run via DB admin, not artisan — sessions table)

# Deactivate a specific user (via Addis One admin UI or Tinker)
# php artisan tinker --execute="App\Models\User::find(ID)->update(['status' => 'inactive'])"

# Revoke all Sanctum tokens for a user (Tinker)
# php artisan tinker --execute="App\Models\User::find(ID)->tokens()->delete()"

# View recent audit log entries (Tinker)
# php artisan tinker --execute="App\Models\AuditLog::latest()->take(50)->get(['event_type','actor_user_id','created_at','details'])"

# Rotate application key (CAUTION — invalidates all encrypted data)
# php artisan key:generate  # only after decrypting all PII first
```

---

## 8. Contact Directory

| Role | Name | Contact |
|---|---|---|
| Security Lead | [TBD] | [TBD] |
| System Administrator | [TBD] | [TBD] |
| DevOps Engineer | [TBD] | [TBD] |
| Project Manager | [TBD] | [TBD] |
| IT Director | [TBD] | [TBD] |
| Hosting Provider Emergency | [TBD] | [TBD] |
| INSA Contact | [TBD] | [TBD] |

---

## 9. Annual Drill

The incident response team will conduct an annual tabletop exercise to rehearse:
- A simulated P1 data breach scenario
- Communication chain activation
- Evidence preservation steps
- Decision point: notify INSA vs. contain internally

The drill is scheduled by the Security Lead and results documented in the quarterly assessment report.

---

*Owner: Security Lead*  
*Approved by: [TBD]*
