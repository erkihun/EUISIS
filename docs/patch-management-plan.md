# Patch Management Plan

**Project:** Addis One  
**Date:** 2026-05-27  
**Review cycle:** Annually or when a major platform version changes

---

## 1. Purpose

This plan defines how security patches, dependency updates, and OS/infrastructure updates are identified, assessed, tested, and applied to the Addis One system to minimise exposure to known vulnerabilities.

---

## 2. Scope

| Component | Technology | Managed by |
|---|---|---|
| Application framework | Laravel (PHP) | Dev team via Composer |
| Frontend dependencies | Node.js / npm | Dev team via npm |
| PHP runtime | PHP 8.4+ | DevOps / hosting provider |
| Web server | Nginx (latest stable) | DevOps / hosting provider |
| Operating system | Ubuntu (hosting provider managed) | Hosting provider |
| Database | MySQL / MariaDB | Hosting provider |
| SSL certificate | Let's Encrypt / CA | DevOps (auto-renew) |

---

## 3. Patch Classification

| Class | Definition | Example | Max Time to Deploy |
|---|---|---|---|
| **Critical** | CVSS 9.0+, or active exploitation reported, or PII/auth bypass | Remote code execution in Laravel or Symfony component | **7 days** |
| **High** | CVSS 7.0–8.9, no active exploitation | SQL injection in a dependency | **30 days** |
| **Medium** | CVSS 4.0–6.9 | XSS in a non-core dependency | **90 days** (next quarterly cycle) |
| **Low** | CVSS < 4.0 | Informational disclosure | **Next release** |
| **OS/Infra Critical** | Critical OS/server CVE | Nginx remote exploit | **7 days** |
| **OS/Infra Routine** | Regular OS patching | Monthly security updates | **30 days** |

---

## 4. Identification

### 4.1 Application Dependencies (Composer / npm)

Run before every production deployment and during quarterly vulnerability assessments:

```bash
composer audit --no-dev
npm audit --audit-level=moderate
```

Subscribe to:
- [Packagist / Composer security advisories](https://packagist.org/advisories)
- [npm security advisories](https://www.npmjs.com/advisories)
- [Laravel security release announcements](https://laravel.com/docs/releases)

### 4.2 PHP Runtime and Web Server

- DevOps monitors hosting provider security bulletins
- Ubuntu/Nginx CVEs tracked via hosting provider notifications
- PHP security releases tracked at [php.net/releases](https://www.php.net/releases/)

### 4.3 INSA Advisories

- Security Lead subscribes to INSA communications
- Any INSA-notified vulnerability affecting Addis One components triggers immediate assessment

---

## 5. Assessment

For each identified vulnerability:

1. Confirm whether Addis One uses the affected component version
2. Determine exploitability in the Addis One deployment context (is the vulnerable code path reachable?)
3. Classify severity using CVSS score and contextual risk
4. Assign an owner (Dev for application; DevOps for infrastructure)
5. Document in the vulnerability register (see Section 8)

---

## 6. Testing and Deployment

### 6.1 Standard Application Patch (High/Critical)

1. Update dependency in `composer.json` or `package.json`
2. Run `composer install` / `npm install`
3. Run full test suite: `php artisan test`, `npm run typecheck`, `npm run build`
4. Deploy following `docs/change-management-policy.md` (Emergency Change process for Critical)
5. Post-deployment smoke test

### 6.2 Laravel Major/Minor Version Upgrade

1. Read the upgrade guide and identify breaking changes
2. Create a feature branch
3. Apply upgrade, fix all deprecations and breaking changes
4. Run full test suite; fix failures
5. Peer review required (Dev Lead + Security Lead for major)
6. Deploy to staging first if staging environment exists
7. Deploy to production with 24-hour monitoring period

### 6.3 PHP Runtime / Nginx / OS Patch (via hosting provider)

1. DevOps coordinates maintenance window with hosting provider
2. Change request raised per `docs/change-management-policy.md`
3. Post-patch verification:
   - Application responds to health check
   - Run smoke test (login, dashboard, ID card view)
   - Check `php --version`, `nginx -v` confirm updated version
   - Check no new PHP deprecation notices in `storage/logs/laravel.log`

---

## 7. Quarterly Vulnerability Assessment

On the first Monday of February, May, August, and November:

- [ ] Run `composer audit --no-dev` and document findings
- [ ] Run `npm audit` and document findings
- [ ] Review PHP and Nginx versions against latest stable releases
- [ ] Review Ubuntu/OS patch status with hosting provider
- [ ] Update vulnerability register (Section 8) with current status of all open items
- [ ] Security Lead signs off on quarterly report

---

## 8. Vulnerability Register

| ID | Component | CVE / Advisory | CVSS | Status | Owner | Target Date | Resolved Date |
|---|---|---|---|---|---|---|---|
| VR-001 | — | — | — | Open | — | — | — |

The vulnerability register is maintained by the Security Lead and updated after each quarterly assessment and after any ad-hoc patch.

---

## 9. Emergency Patch Procedure

When a Critical vulnerability is identified outside the quarterly cycle:

1. Security Lead is notified immediately (Telegram / direct call)
2. Security Lead assesses exploitability within 4 hours
3. If exploitable: initiate Emergency Change (see `docs/change-management-policy.md` §4.5)
4. Patch deployed within 7 days (target: 24–48 hours for actively exploited CVEs)
5. Post-patch report added to vulnerability register and incident log

---

## 10. Responsibilities

| Role | Responsibility |
|---|---|
| Security Lead | Monitor advisories, triage findings, sign off quarterly report |
| Dev Lead | Application dependency patching, test suite, code review |
| DevOps | Infrastructure/OS patching, co-ordinate with hosting provider, deployment |
| Hosting Provider | OS and server-level patches within contracted SLA |

---

*Owner: Security Lead + DevOps*  
*Approved by: [TBD]*
