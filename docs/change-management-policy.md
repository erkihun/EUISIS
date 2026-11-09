# Change Management Policy

**Project:** Addis One  
**Date:** 2026-05-27  
**Review cycle:** Annually or after any major incident

---

## 1. Purpose

This policy ensures that all changes to the Addis One system — code, configuration, infrastructure, and content — are planned, reviewed, tested, and approved before deployment, minimising risk to system availability, security, and data integrity.

---

## 2. Scope

This policy applies to:
- Application code changes (features, bug fixes, security patches)
- Database schema migrations
- Configuration changes (`.env`, `config/`, system settings)
- Infrastructure changes (server configuration, Nginx, PHP, SSL)
- Content changes (system messages, localization strings, logo)
- Third-party dependency updates (Composer, npm)

---

## 3. Change Categories

| Category | Description | Examples | Approval Required |
|---|---|---|---|
| **Standard** | Pre-approved, low-risk, routine changes | Dependency patch version bump, minor content update | Dev Lead sign-off |
| **Normal** | Planned changes requiring review | New feature, schema migration, config change | Dev Lead + Security Lead |
| **Emergency** | Urgent fix to restore service or mitigate active security incident | Critical CVE patch, active exploit mitigation | Security Lead oral approval; written approval within 24 hours |
| **Major** | Significant architectural or data model change | New module, role restructure, encryption key rotation | All leads + Project Manager |

---

## 4. Change Request Process

### 4.1 Initiation

1. Developer creates a change request by opening a pull request (PR) or issue with:
   - Description of the change and motivation
   - Risk assessment (impact if it fails, rollback plan)
   - Test plan
   - Migration steps (if schema change)
   - Security impact (if applicable)

### 4.2 Review

2. Peer code review by at least one other developer
3. Security Lead reviews any change affecting:
   - Authentication, authorisation, session management
   - Encryption, key management
   - File upload, external integrations
   - Security headers, rate limiting, middleware
4. All tests must pass (`php artisan test`, `npm run typecheck`, `npm run build`)
5. Dependency changes require `composer audit` and `npm audit` to be run and findings documented

### 4.3 Approval

6. Dev Lead approves Normal changes
7. Security Lead counter-signs security-relevant changes
8. Project Manager approves Major changes

### 4.4 Deployment

9. Deployment follows the runbook at `docs/runbook/deployment.md`
10. Migrations run via `php artisan migrate --force` in production maintenance window
11. Post-deployment smoke test performed by Dev Lead
12. Deployment recorded in the deployment log (see Section 6)

### 4.5 Emergency Changes

For active security incidents:
1. Security Lead authorises deployment verbally
2. Minimal targeted patch only — no feature additions
3. Full documentation and written approval completed within 24 hours
4. Incident recorded in `docs/incident-response-plan.md` post-incident review

---

## 5. Content Changes

Content managed via the Addis One admin UI (logo, system messages, localization):
- Amharic text must be reviewed by an approved Amharic language reviewer before publishing
- No direct database edits of content fields
- Screenshot/log of the change retained by System Administrator

---

## 6. Deployment Log

Each production deployment must be logged:

| Field | Value |
|---|---|
| Date/time | |
| Deployed by | |
| Change description | |
| PR / issue reference | |
| Migration applied | Yes / No |
| Tests passed | Yes / No |
| Rollback tested | Yes / No |
| Approver | |

The log is maintained by DevOps in the team's internal records system.

---

## 7. Rollback Plan

Every change must have a documented rollback plan before deployment:
- Code: revert commit and redeploy previous tag
- Database migration: only if migration includes a `down()` method — run `php artisan migrate:rollback`; otherwise require a forward-fix migration
- Configuration: restore previous `.env` from secure backup
- Infrastructure: restore previous Nginx/PHP config from version control

---

## 8. Change Freeze

| Period | Restriction |
|---|---|
| 48 hours before a major release | No non-critical changes |
| Active security incident | Emergency changes only |
| Public holiday / reduced staffing | Standard + Emergency only; Normal/Major deferred |

---

## 9. Responsibilities

| Role | Responsibility |
|---|---|
| Developer | Raise change request, write tests, document rollback |
| Dev Lead | Peer review, approve Normal changes, coordinate release |
| Security Lead | Security review, approve security-affecting changes, authorise emergency changes |
| DevOps | Execute deployment, maintain deployment log |
| Project Manager | Approve Major changes, vendor/hosting change management |
| System Administrator | Content changes, user provisioning changes |

---

*Owner: Dev Lead*  
*Approved by: [TBD]*
