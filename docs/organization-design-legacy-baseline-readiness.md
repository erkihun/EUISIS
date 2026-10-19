# Legacy Organization Baseline — production readiness

Brief received 2026-10-19 (sections 0–154; truncated after §154). Built in
five phases on top of the existing Organization Design & Workforce Planning
module, which already covered the design chain (see
[organization-design-readiness.md](organization-design-readiness.md)).

## What was added

| Phase | Commit | Content |
|---|---|---|
| A | `cc4a44f6` | Legal source register: city-level and institution sources, private documents behind a checksum-verified download, polymorphic citations, supersede/repeal instead of delete, mandate field, report |
| B | `9889adb5` | Legacy baseline: 22-step wizard detected from existing records, hand-set steps with notes, validation gate (structure rules + baseline rules), preparer ≠ approver, transactional publish with frozen snapshot, supersession, revisions |
| C | `842d6dc3` | Staged Excel/CSV imports for mandates, functions, services, processes, activities, tasks, work standards: queued chunked parsing, row errors with suggestions, code and legacy-code matching, manual mapping, commit through domain services, bilingual templates |
| D | `1a9bdfbd` | Module overview with permission-gated cards and scoped figures, "as of date X", sidebar clusters, organogram from snapshot with print, baseline comparison |
| E | `2c23893d` | Document suggestions (AI suggests, person applies), headcount override evidence, Situation Room "Structure readiness" card |

## Decisions (ADRs in the plan)

ADR-LB-001 legal sources + polymorphic citations · ADR-LB-002 baseline as
container + snapshot (no copies of the chain) · ADR-LB-003 reuse the
module's status enum; Imported / Mapping required / Under validation /
Returned are fields, not new statuses · ADR-LB-004 imports stage then
commit through services · ADR-LB-005 sidebar clusters + landing page ·
ADR-LB-006 document assistance advisory and schema-bound · ADR-LB-007
override evidence additive, FTE never overwritten.

## Verification

- Organization design suite (all phases, including the route-wide IDOR
  sweep over every new route): see the final run in the handoff.
- Guards: admin UI consistency, sidebar navigation, translation audit
  (strict, no blocking finding), Ziggy routes file, AI prompt catalog.
- Migrations are additive and resumable (`SchemaResume`), no drop, no
  rewrite; run on the local PostgreSQL database.

## Security posture

- Every new record is organization-scoped through `OrganizationScopeService`;
  city-level legal sources are read-only outside the catalog grant.
- Status, approver, publisher, organization and snapshot fields are never
  mass-assigned: services set them with `forceFill` after their checks.
- Files: private disk, UUID names, MIME + extension + size checks,
  SHA-256 verified on download, `no-store` and `nosniff` headers, path never
  serialised. Spreadsheets are read as values; macros never run.
- The AI never writes a record; its output is schema-validated and applied
  only by a person.

## Open items

| Marker | Item |
|---|---|
| OFFICIAL_DATASET_REQUIRED | ISCO-08, ISIC Rev.4, CPC — importers ready, catalogs empty (unchanged) |
| NEEDS_POLICY_DECISION_RESPONSIBILITY_MODEL | Responsibility types stay a catalog; no RACI asserted as law |
| NEEDS_POLICY_DECISION_FTE_METHOD | Methodologies configurable; none seeded as policy |
| NEEDS_POLICY_DECISION_COMPETENCY_SCALE | Competency levels catalog; no scale imposed |
| NEEDS_POLICY_DECISION | Which roles beyond the three design roles receive baseline, import and suggestion grants |
| NEEDS_DEPLOYMENT_CONFIGURATION | Queue worker must run for imports (`QUEUE_CONNECTION=database` locally); AI gateway credits and `knowledge_documents` outbound class for suggestions |
| NEEDS_DATA | No PDF/OCR text extraction is installed; suggestions read a pasted passage. Adding OCR is a dependency decision |
| NEEDS_LANGUAGE_REVIEW | New Amharic labels (baseline steps, import columns, overview categories) |
| Not built | Electronic signature (none exists in the repository); approval evidence is approver, date, reference and attached source document |

## Verdict

**CONDITIONAL READY** — the capture, gate, publication, import and
history paths are complete and tested; the conditions are the policy
decisions, official datasets and deployment configuration above.
