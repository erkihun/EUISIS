# Unified public feedback brief — digest

Amharic: የሕዝብ አስተያየት፣ ጥቆማና ቅሬታ — የመመሪያው ማጠቃለያ

One line per section of the Codex brief received on 2026-10-12 ("UNIFIED PUBLIC FEEDBACK, SUGGESTION & COMPLAINT MANAGEMENT SYSTEM"). The brief arrived truncated inside section 122 (test 49); nothing after it was received. Plan: [superpowers/plans/2026-10-12-public-feedback-unified.md](superpowers/plans/2026-10-12-public-feedback-unified.md).

| § | Requirement (condensed) | Plan phase |
|---|---|---|
| 0 | Employee QR and Organization QR each lead to Feedback / Suggestion / Complaint attached to the scanned target; the visitor never picks the target | 1, 3 |
| 1 | Public Complaint ≠ Employee Grievance ≠ Discipline ≠ Appeal; never auto-route into grievance | all |
| 2 | Transport Management out of scope | — |
| 3 | Inspect the repository first; reuse, do not build a second architecture | 0 |
| 4 | Do not generate a new Employee QR; keep the stable token; never rotate for feature/HR changes | 1 |
| 5 | No employee PII in the QR payload | 1 |
| 6 | Do not weaken OTP-gated ID verification | 3 |
| 7 | Every Organization gets its own stable public feedback QR with an opaque identifier | 2 |
| 8 | Organization Unit QR out of scope | 2 |
| 9 | Organization QR model: organization_id, public token, status, enabled/disabled/revoked audit columns | 2 |
| 10 | One active stable Organization QR; name/logo/brand/admin/structure/service/category changes never rotate it | 2 |
| 11 | Deliberate rotation/revocation only with explicit permission; revoke old, create new, keep history, audit | 2 |
| 12 | A revoked identifier is never reassigned to another organization | 2 |
| 13 | Inactive/archived organization → safe unavailable state; successor redirection NEEDS_DECISION | 2, 3 |
| 14 | One `PublicFeedbackTargetResolver` returning a safe TargetContext; never resolve from browser IDs | 1 |
| 15 | Target types exactly EMPLOYEE / ORGANIZATION | 1 |
| 16 | Source types EMPLOYEE_CARD_QR / ORGANIZATION_FEEDBACK_QR, distinct from target type | 1 |
| 17 | No authoritative hidden target inputs; re-resolve server-side | 3 |
| 18 | One mobile-first landing with safe target confirmation and exactly three options | 3 |
| 19 | No employee/organization/unit search or selector in the QR flow | 3 |
| 20 | Safe public confirmation DTO (employee per ID policy; organization name/logo/short name) | 3 |
| 21 | Submission type enum FEEDBACK / SUGGESTION / COMPLAINT | 0 |
| 22 | Unified submissions table with target/source/context/snapshot/category/identity/status/timestamps | 1 |
| 23 | Database-level target integrity: exactly one primary target | 1 |
| 24 | Source/target consistency validated | 1 |
| 25 | Employee historical context preserved across transfers | 1 |
| 26 | Organization identity snapshot survives renames | 1 |
| 27 | Feedback form fields; no automatic appraisal effect | 3 |
| 28 | Suggestion form fields; not a complaint | 3 |
| 29 | Complaint form fields; never asks for penalties | 3 |
| 30 | Rating policy configurable; not mandatory unless approved; never converted to EPMS | 6 |
| 31 | Categories applicable by type and by target type | 4 |
| 32 | City-wide categories; institution-specific as scoped extensions; governance NEEDS_DECISION | 4 |
| 33 | Never delete referenced categories; active/inactive | 4 |
| 34 | Identity modes ANONYMOUS / IDENTIFIED / OPTIONALLY_IDENTIFIED per policy | 6 |
| 35 | Validate and protect contact data; encryption pattern | 6 |
| 36 | Separate permission to view submitter identity | 6 |
| 37 | Anonymous still rate-limited, bot-protected, idempotent, size-limited | 3, 8 |
| 38 | Rate limiting across nodes on shared infrastructure; thresholds NEEDS_DECISION | 8 |
| 39 | Duplicate submission protection via one-time token | 3 |
| 40 | Complaint reference through CodeRuleService; others configurable | 3 |
| 41 | Public tracking only with secure verification; do not invent one | 6 (documented) |
| 42 | Success receipt: type, target, reference, submitted at, tracking instructions | 3 |
| 43 | Employee-targeted submissions route to handlers, never to the target employee | 5 |
| 44 | Organization-targeted submissions route to that organization's handlers | 5 |
| 45 | Versioned/effective routing rules: organization × target × type × category → handler | 5 |
| 46 | No rule → UNROUTED state and administrator alert | 5 |
| 47 | Routing rule scope security; city rules need city permission | 5 |
| 48 | Feedback lightweight workflow | 5 |
| 49 | Suggestion workflow; outcome vocabulary NEEDS_DECISION | 5 |
| 50 | Complaint workflow SUBMITTED → INTAKE → ASSIGNED → UNDER_REVIEW → RESOLVED → CLOSED | 5 |
| 51 | Type-specific semantics on shared infrastructure | 5 |
| 52 | Immutable status history | 5 |
| 53 | Assignment history | 5 |
| 54 | Target employee cannot adjudicate own complaint (server-side) | 5 |
| 55 | Target employee visibility is policy; undefined → NEEDS_DECISION | 6 |
| 56 | Organization visibility = permission + scope + workflow role | 6 |
| 57 | City oversight aggregate ≠ detail access | 6, 7 |
| 58 | Complaint is not guilt; never automatic sanctions | 9 |
| 59 | Controlled referral creates a separate Discipline record linked back | 9 |
| 60 | Referral authority; store a referral request when the module is not ready | 9 |
| 61 | Organization complaint may name subject employees in a separate relation | 9 |
| 62 | Never retarget after submission | 1 |
| 63 | Never create an Employee Grievance from a public submission | 9, 10 |
| 64 | Never create an Appeal from public feedback | 9, 10 |
| 65 | Private attachments with validation; scanning if available | 0, 8 |
| 66 | Attachment access only for authorized users; audited | 6 |
| 67 | Upload abuse protections | 8 |
| 68 | Plain text; no stored XSS | 3, 10 |
| 69 | Organization QR management UI | 2 |
| 70 | Printable institutional QR artifact, bilingual | 2 |
| 71 | Reuse QR formats PNG/SVG/PDF | 2 |
| 72 | Organization QR audit without logging the token | 2 |
| 73 | Employee QR integration without card redesign | 3 |
| 74 | Follow the active-card policy for employee QR services | 3 (documented) |
| 75 | Organization QR requires active organization + active QR | 2 |
| 76 | Mobile-first, accessible, no login unless policy | 3 |
| 77 | Full Amharic/English; core labels | all |
| 78 | Gregorian canonical dates; Ethiopian display | all |
| 79 | Admin navigation "Public Feedback Management" | 7 |
| 80 | Keep it independent of grievance/discipline/appeal menus | 7 |
| 81 | Dashboard with real scoped metrics | 7 |
| 82 | No automatic employee ranking from complaints | 7 |
| 83 | Report filters | 7 |
| 84 | Employee-level reports need explicit permission | 7 |
| 85 | Organization-targeted reports separate | 7 |
| 86 | Aggregate vs detail permissions | 6, 7 |
| 87 | Exports queued, scoped, private, audited, formula-safe | 7 |
| 88 | Internal notifications without confidential content in SMS/email | 8 |
| 89 | Asynchronous acknowledgement; never fail the submission | 8 |
| 90 | Follow-up items modelled separately only if supported | — (NEEDS_DECISION) |
| 91 | SLA values NEEDS_DECISION; configurable architecture | 5 (documented) |
| 92 | SLA snapshot at submission/assignment | 5 (documented) |
| 93 | Public feedback settings | 6 |
| 94 | Organization-specific settings; authority NEEDS_DECISION | 6 |
| 95 | Granular permissions | 2, 5, 6, 7, 9 |
| 96 | Permission + scope + workflow role on every query | all |
| 97 | Opaque QR identifiers, indexed, never reused, not logged | 2 |
| 98 | Direct indexed lookups for QR resolution | 1, 2 |
| 99 | Minimal queries on the public page | 3 |
| 100 | Indexes | 1, 2, 4, 5 |
| 101 | Partition-ready by submitted_at; no premature partitioning | 1 |
| 102 | 3,000,000-employee scale: no per-scan work proportional to employees | 1, 3 |
| 103 | Stateless nodes; no sticky sessions | 8 |
| 104 | Redis for distributed limits/cache/idempotency where configured | 8 |
| 105 | Queues for notifications, exports, heavy processing | 7, 8 |
| 106 | Transactional submission sequence; notifications after commit | 3 |
| 107 | Concurrency protections | 2, 3, 5 |
| 108 | Audit significant internal actions | 2, 5, 6, 9 |
| 109 | Privacy logging rules | 10 |
| 110 | Retention NEEDS_DECISION | 10 |
| 111 | No casual hard delete after review; controlled states only with policy | 5 |
| 112 | Withdrawal NEEDS_DECISION | 10 |
| 113–116 | Data-preserving migration of existing employee rows; no destructive migration | 1 |
| 117–122 | Required tests (employee QR, organization QR, constraints, types, security, domain boundaries) | per phase |
