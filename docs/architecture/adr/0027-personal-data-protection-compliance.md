# ADR 0027: Personal data protection under Proclamation No. 1321/2024

- Status: accepted (2026-11-04), decided by the System Owner (compliance mapping
  delegated to the implementer and recorded here; items that need a legal
  reading are listed under "Needs management or legal approval").
- Context: Agelgay holds the personal data of every civil servant of the city
  (identity, photo, employment history, disciplinary and grievance records,
  court cases, contact details) and of members of the public who give
  feedback or file complaints. Ethiopia's Personal Data Protection
  Proclamation No. 1321/2024 (የግል መረጃ ጥበቃ አዋጅ ቁጥር 1321/2016) applies to the
  City Administration as a data controller. The controls the proclamation
  asks for already exist in pieces (ADR 0004 retention, the retention matrix,
  data stewardship, the AI outbound data classes, audit seals, the privacy
  section of the public pages), but nothing maps them to the obligations, and
  two obligations had no implementation at all: answering a data subject's
  request, and recording a breach notification. Before the pilot the
  institutions need one page that says what the system does for each duty.

## Decisions taken now

1. **One obligations map.** The table below is the authoritative mapping and is
   reviewed with every ADR that touches personal data. A control marked
   "exists" names the code or document that implements it.

   | Obligation (Proclamation 1321/2024) | Control | State |
   |---|---|---|
   | Lawful basis and purpose limitation | Processing rests on the civil service proclamations and Regulation 201/2018; each module's purpose is stated in its domain document (docs/*-security.md, docs/*-workflow.md). Data collected for feedback is used only for that case (`FeedbackVisibility`). | exists |
   | Data minimisation | Forms collect only the fields the workflow uses; identity modes let the public submit anonymously (`submitter_identity_mode`); the digital ID prints no date of birth (2026-11-01). | exists |
   | Transparency | Public pages carry the privacy notice; employees see their own record in My Portal › My employment. | exists |
   | Security of processing | ADR 0003 scope fail-closed, MFA for privileged roles, encrypted sensitive columns (`security:encrypted-values`), audit seals, backup encryption (ADR 0004, docs/security-requirements.md). | exists |
   | Retention and deletion | config/retention.php schedules, docs/governance/retention-matrix.md, recycle bin with forced deletion audited. Financial, employee-history and case records are kept by law and never pruned. | exists |
   | Data subject rights: access, rectification, erasure, objection | My Portal gives access and the correction request (`employee corrections`); erasure and objection had no path. **New:** docs/runbooks/data-subject-requests.md, the operator path with the 30-day clock and the refusal grounds (records kept by law). | new |
   | Breach notification (to the authority and to affected persons) | Incident runbook existed for operations only. **New:** the breach section of the same runbook; the Intelligence › Incidents record is the register of record. | new |
   | Processors and third parties | SMS and AI providers are the only processors. SMS carries one-time codes only (`BudgetedSmsGateway`); the AI gateway sends only the data classes the governance page allows, redacted (`Redactor`), and is off by default. | exists |
   | Cross-border transfer | None by design: self-hosting is a requirement (docs/hosting-security-requirements.md). The AI gateway may only be pointed at an in-country or on-premises endpoint; a foreign endpoint needs the approval under "Needs management or legal approval". | exists |
   | Data protection officer and records of processing | The roles register (docs/governance/roles-register.md) names the steward per domain; the records of processing are the ownership tables in docs/governance/data-ownership.md. | exists |

2. **The AI gateway stays off until approved.** `INTELLIGENCE_AI_*` settings
   remain empty in production and the governance page's "AI enabled" switch
   stays off until the System Owner records the endpoint, its hosting location
   and the allowed data classes in this ADR's successor. The outbound data
   classes default to the non-personal ones.

3. **Erasure is refusal-by-rule for employment records.** A data subject's
   erasure request is granted for data the law does not require the
   administration to keep (public feedback contact details, uploaded evidence
   after the case closes, portal preferences) and refused with the legal ground
   for employment history, disciplinary, grievance and court records. The
   runbook lists both.

4. **Breach register.** Every suspected breach is recorded as an Intelligence
   incident whose title begins with "Data breach:" within one working day of
   discovery, whether or not it is later confirmed. Notification decisions and
   their timestamps are recorded in that incident's notes, so the register is
   the incident list filtered by that prefix.

## Needs management or legal approval

- The notification deadlines and the authority's contact channel once the
  implementing directive under the proclamation is issued.
- Whether a foreign-hosted AI endpoint can ever be used, and under what
  transfer safeguard.
- The retention period for public feedback contact details after a case
  closes (the matrix proposes 12 months).

## Consequences

- One page to show an auditor or an institution head; the pilot concept note
  points to it.
- The DSAR runbook adds operator work (a 30-day clock); the Super Admin role is
  not needed for it, the data steward role is.
- Nothing changes for employees or the public today; the controls were
  already in force. The two new runbook sections are process, not code.
