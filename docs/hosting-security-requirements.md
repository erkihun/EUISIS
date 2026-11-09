# Hosting Security Requirements

**Project:** Addis One  
**Date:** 2026-05-27  
**Applicability:** Any hosting provider for production deployment

This document defines the minimum security requirements for the hosting environment and the hosting agreement.

---

## 1. Hosting Provider Security Capability

| Requirement | Status | Notes |
|---|---|---|
| Holds ISO 27001 certification or equivalent government security certification | ❌ Open | Verify before contract signing |
| Complies with Ethiopian government IT standards and INSA guidelines | ❌ Open | |
| Has a published security incident response capability | ❌ Open | |
| Provides dedicated (not shared) hosting for government data | Required | Multi-tenant OK only if tenant isolation is certified |
| Data sovereignty: servers physically located in Ethiopia | Preferred | Verify data residency clause in contract |

---

## 2. Network Infrastructure Security

| Requirement | Status |
|---|---|
| DDoS mitigation capability | Required |
| Network firewall with restrictive default-deny policy | Required |
| Web Application Firewall (WAF) | Recommended |
| Intrusion Detection/Prevention System (IDS/IPS) | Required |
| Network monitoring with alerting | Required |
| No unnecessary inbound ports (only 80, 443; 22 restricted by IP) | Required |
| Database port (3306/5432) not exposed to internet | Required |

---

## 3. Server OS and Hosting Platform Security

| Requirement | Notes |
|---|---|
| OS patches applied within 30 days of release (critical: 7 days) | |
| Minimal OS installation — no unnecessary packages | |
| Web server: Nginx (latest stable) | `server_tokens off;` required |
| PHP: version 8.4+ | `expose_php = Off` in php.ini |
| HTTP TRACE/TRACK disabled | `if ($request_method = TRACE) { return 405; }` |
| Directory listing disabled | `autoindex off;` in Nginx |
| `.env` and dot-files blocked | `location ~ /\. { deny all; }` |
| Separate web server user account (not root) | e.g., `www-data` |
| `storage/` and `bootstrap/cache/` not world-writable | Mode 755 max |
| Log files not accessible via HTTP | Above web root |

---

## 4. Encryption

| Requirement | Notes |
|---|---|
| TLS 1.2 minimum; TLS 1.3 preferred | Disable TLS 1.0, TLS 1.1 |
| Strong cipher suites only | Use Mozilla Modern/Intermediate configuration |
| SSL certificate from trusted CA | Auto-renew via Let's Encrypt or equivalent |
| HSTS preloaded domain preferred | `max-age=63072000; includeSubDomains; preload` |
| Encrypted backups | AES-256 at minimum |
| Encrypted data in transit between app and database | SSL/TLS on DB connection |
| Disk encryption at rest | Required for all storage volumes containing PII |

---

## 5. Business Continuity and Disaster Recovery

| Requirement | Detail |
|---|---|
| Recovery Time Objective (RTO) | ≤ 4 hours for full service restoration |
| Recovery Point Objective (RPO) | ≤ 24 hours (daily backups at minimum) |
| Backup frequency | Daily automated; weekly full |
| Backup retention | 30 days minimum |
| Offsite backup storage | Required (geographically separated) |
| Backup restore tests | Monthly |
| Failover capability | Documented runbook for manual failover |

---

## 6. Physical Security

| Requirement |
|---|
| Data centre has 24/7 physical security (guards, CCTV) |
| Access control to server rooms (badge/biometric) |
| Visitor logs maintained |
| Hardware disposal follows certified data destruction process |

---

## 7. Third-Party Security Audit Evidence

The hosting provider must provide, on request:
- Most recent penetration test report (within 12 months)
- Most recent vulnerability scan results
- Security certification (ISO 27001 or equivalent)
- Proof of DDoS mitigation capability

---

## 8. Ethiopian / On-Premises Hosting Requirement

Per Addis Ababa City Administration data governance policy, citizen data must remain within Ethiopia's jurisdiction. If a cloud provider is used, ensure:
- A data residency clause is included in the contract
- Data processing agreement (DPA) is signed
- Sub-processors are disclosed and approved

Preferred: On-premises hosting within City Administration data centre.

---

## 9. Hosting Agreement Checklist

| Clause | Required? | Status |
|---|---|---|
| Roles and responsibilities clearly defined | Yes | ❌ Open |
| Breach notification within 24 hours | Yes | ❌ Open |
| Exceptional event handling (natural disaster, power failure) | Yes | ❌ Open |
| Service level agreement (SLA) — uptime 99.9%+ | Yes | ❌ Open |
| Communication methods for incidents and maintenance | Yes | ❌ Open |
| Compliance with national standards (INSA, Ethiopian data law) | Yes | ❌ Open |
| Primary and secondary technical contact | Yes | ❌ Open |
| Data storage limits and growth projections | Yes | ❌ Open |
| Bandwidth limits and burst capacity | Yes | ❌ Open |
| Acceptable downtime windows and maintenance notice | Yes | ❌ Open |
| Domain namespace scalability | Yes | ❌ Open |
| Liability for security violations | Yes | ❌ Open |
| Termination and data return/destruction clause | Yes | ❌ Open |
| Audit rights (City Administration may audit hosting provider) | Yes | ❌ Open |

---

*Owner: Project Manager + Security Lead*  
*Must be completed before production go-live*
