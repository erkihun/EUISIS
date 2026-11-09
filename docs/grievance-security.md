# Grievance security and records

`GrievanceCaseAccessService` centralizes permission and case-relationship decisions. Staff routes require grievance permissions, while employee portal routes expose the complainant's own cases. Organization scope supports intake and oversight access; it does not automatically make every organizational employee a case handler.

## Confidentiality boundaries

Current panel seats, assigned case officers, authorized unit assigners, designated approvers and configured oversight/registry relationships govern access. Conflict/recusal records remove handling authority. Prior handlers can retain read access under the service's history rules. Unit-wide staff access is disabled by default and is a policy setting. Review external-authority access and broad administrative permissions before assigning them.

Employee presentation filters timelines and documents. Internal notes, committee deliberations and draft decisions are not employee-facing records. Notifications store only module, kind, case reference and link. Aggregate reports suppress small groups using the configurable minimum group size (default five); aggregate export access does not grant case-detail access.

## Evidence and documents

Evidence is stored on the private local disk under random filenames. The service checks allowed extension, detected MIME family, size and evidence type, records a checksum, and rechecks authorization and integrity at download time. Accepted evidence is superseded with a new version rather than replaced. Custody records capture upload, review and download actions. Issued letters likewise store private artifacts and integrity metadata.

There is no integrated malware scanner in this module: `scan_status` remains `not_scanned`. MIME validation and a SHA-256 digest do not scan for malware or prove legal authenticity. Restrict direct storage access and include files with database backups. Existing media integrations and production disk configuration need deployment review.

## Audit and retention

Workflow and configuration actions use the shared audit infrastructure. Logs and notifications should avoid grievance narratives and evidence contents. Protect audit access and define operational log retention separately from case retention.

Closure stores record state and configured retention date. Archival requires a closed record, elapsed retention date, appropriate oversight/archive authority and no legal hold. With retention years unset/zero, no retention deadline is guessed and archival is blocked. Legal holds record a reason. Archival changes record state; it is not automatic deletion or legal disposition certification.

## Verification boundary

Tests cover employee isolation, handler relationships, evidence/download permissions, content mismatch, checksum tampering, safe notifications, report suppression and workflow transitions. Passing automated tests does not replace production role review, PostgreSQL concurrency testing, storage access review, backup restoration or independent security review. Full-suite failures must be recorded rather than relabeled as a clean release.

## NEEDS_DECISION

Approve confidentiality classifications, oversight and administrator access, external-handler jurisdictions, recusal review authority, report suppression threshold, retention/disposition and legal-hold rules. Select malware scanning/quarantine, production storage protection, backup and recovery procedures, and delivery-provider controls. No claim of legal compliance or production readiness follows from the current defaults.
