# Security Requirements

**Project:** Addis One — Addis Ababa City Administration Addis Ababa Integrated Employee Management & Service System  
**Date:** 2026-05-27  
**Status:** Draft — Pending Business Owner Approval

---

## 1. Security Requirements Matrix

| ID | Category | Requirement | Priority | Status | Owner |
|---|---|---|---|---|---|
| SR-01 | Authentication | Users must authenticate with email + password before accessing any protected resource | Critical | Implemented | Dev |
| SR-02 | Authentication | Passwords must meet minimum complexity (8+ chars, mixed case, digit or symbol) via `Rules\Password::defaults()` | Critical | Implemented | Dev |
| SR-03 | Authentication | Login must be rate-limited (5 attempts per email+IP per minute) | High | Implemented | Dev |
| SR-04 | MFA | Super Admin and City Admin roles must complete TOTP MFA enrollment before accessing any route | Critical | Implemented | Dev |
| SR-05 | MFA | MFA challenge must be rate-limited (5 attempts per minute) | High | Implemented | Dev |
| SR-06 | Authorization | All state-changing operations must be authorised by a Laravel Policy | Critical | Implemented | Dev |
| SR-07 | Authorization | RBAC must use Spatie Permission v6; permissions must be granular (viewAny, view, create, update, delete, restore) | High | Implemented | Dev |
| SR-08 | Authorization | Organization scope must restrict data access — a user assigned to Organisation A cannot read/write data for Organisation B | Critical | Implemented | Dev |
| SR-09 | PII Protection | Employee national_id must be encrypted at rest using Laravel `encrypted` cast | Critical | Implemented | Dev |
| SR-10 | PII Protection | Employee phone_number must be encrypted at rest | Critical | Implemented | Dev |
| SR-11 | PII Protection | User national_id and phone_number must be encrypted at rest | Critical | Implemented | Dev |
| SR-12 | PII Protection | national_id must only be visible to users with `employees.viewPii` permission | High | Implemented | Dev |
| SR-13 | PII Protection | national_id must never appear in API responses, URLs, log files, or error messages | Critical | Implemented | Dev |
| SR-14 | ID Card QR | QR token must not contain employee name, national ID, phone, or email | Critical | Implemented | Dev |
| SR-15 | ID Card QR | QR token must be signed with an HMAC or stored as a one-way hash (SHA-256) | Critical | Implemented | Dev |
| SR-16 | ID Card QR | Token hash must never be exposed in API responses | Critical | Implemented | Dev |
| SR-17 | Cafeteria | Cafeteria access must be blocked only at the service level — not by revoking the ID card | Critical | Implemented | Dev |
| SR-18 | Cafeteria | Cafeteria scan endpoint must verify the QR token server-side | Critical | Implemented | Dev |
| SR-19 | Cafeteria | Cafeteria scan must be rate-limited (60/min per provider terminal) | High | Implemented | Dev |
| SR-20 | Audit Logs | Every sensitive action (card issue, card revoke, employee create/update, user role change) must write an audit log entry | High | Implemented | Dev |
| SR-21 | Audit Logs | Audit log entries must not be deletable by application users | High | Partial | Dev/DB |
| SR-22 | Session | Sessions must use the database driver; not file or cookie | High | Implemented | Dev |
| SR-23 | Session | Session cookie must have `HttpOnly=true` | High | Implemented | Dev |
| SR-24 | Session | Session cookie must have `Secure=true` in production | Critical | Documentation | DevOps |
| SR-25 | Session | Session cookie must have `SameSite=Lax` | High | Implemented | Dev |
| SR-26 | Session | Session must be regenerated on successful login | Critical | Implemented | Dev |
| SR-27 | Session | Session must be invalidated on logout | Critical | Implemented | Dev |
| SR-28 | Transport | All production traffic must be served over HTTPS/TLS 1.2+ | Critical | Documentation | DevOps |
| SR-29 | Transport | HSTS header must be sent in production | High | Implemented (code) | DevOps |
| SR-30 | Security Headers | All HTTP responses must include X-Frame-Options, X-Content-Type-Options, CSP, Referrer-Policy | High | Implemented | Dev |
| SR-31 | File Upload | File uploads must validate MIME type and extension | High | Partial | Dev |
| SR-32 | File Upload | Uploaded files must not be stored in the public document root | High | Partial | Dev |
| SR-33 | File Upload | Maximum file size must be enforced server-side | High | Partial | Dev |
| SR-34 | Backups | Database backups must be taken daily and tested monthly | High | Documentation | DevOps |
| SR-35 | Hosting | The hosting provider must hold a relevant security certification or comply with INSA standards | High | Open | PM |
| SR-36 | Incident Response | A documented incident response plan must exist and be rehearsed annually | High | Implemented (doc) | Security Lead |
| SR-37 | Change Management | All code changes must go through peer review and testing before production deployment | High | Documentation | Dev Lead |
| SR-38 | Dependency Security | Composer and npm dependencies must be audited before each production release | High | Documentation | Dev |
| SR-39 | Registration | Public self-service registration must be disabled by default (`REGISTRATION_ENABLED=false`) | High | Implemented | Dev |
| SR-40 | Legal / Compliance | The system must comply with Ethiopian data protection guidelines and INSA standards | Critical | Documentation | PM/Legal |

---

## 2. Business Owner Approval Checklist

| Item | Approved? | Approver | Date |
|---|---|---|---|
| Security requirements matrix (SR-01 through SR-40) | ☐ | Business Owner | |
| Data classification: national_id = Confidential | ☐ | Business Owner | |
| MFA mandatory for Super Admin and City Admin | ☐ | Business Owner | |
| Employee photo private disk migration (sprint +1) | ☐ | Business Owner | |
| Hosting security agreement (before go-live) | ☐ | Business Owner | |

---

## 3. Security Owner Approval Checklist

| Item | Approved? | Approver | Date |
|---|---|---|---|
| INSA audit findings accepted/mitigated | ☐ | Security Lead | |
| Open risks (CR-4 through CR-6) accepted for go-live | ☐ | Security Lead | |
| Penetration test scheduled (before go-live) | ☐ | Security Lead | |
| Incident response plan reviewed | ☐ | Security Lead | |

---

## 4. Intellectual Property / Source Code Protection

- Source code repository must be private (no public GitHub visibility)
- Repository access restricted to authorised team members (need-to-know basis)
- Commit history must not contain secrets, credentials, or `.env` files
- CI/CD secrets managed via secure environment variables (not committed to repo)
- Contractor access revoked upon project handoff/termination

---

## 5. Post-Implementation Security Needs

| Activity | Frequency | Owner |
|---|---|---|
| Quarterly vulnerability/risk assessment | Quarterly | Security Lead |
| Dependency audit (`composer audit`, `npm audit`) | Before each production deploy | Dev |
| Penetration test | Annually | External Party |
| SSL certificate renewal monitoring | Automated alert 30 days before expiry | DevOps |
| Access review (user accounts and roles) | Quarterly | IT Admin |
| Backup restore test | Monthly | DevOps |
| Incident response drill | Annually | All |
| INSA compliance re-audit | Annually | Security Lead |
