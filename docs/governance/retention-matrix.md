# Retention matrix

Gate G6 ([../go-live/go-live-gates.md](../go-live/go-live-gates.md)). The
retention period of each data class is a policy decision for **HR, Legal,
Records Management, Audit and Data Protection together**, not for ICT alone,
and there is no single period for everything. Until a class is approved its
state is **NEEDS_POLICY_DECISION_RETENTION** and its records are kept: the
application deletes nothing from it.

Every class can be approved as one of:

- **retain**: keep (no automatic removal);
- **archive**: export expired rows to the archive disk (gzip NDJSON with a SHA-256 per file in the class manifest), then remove them from the live table;
- **purge_when_authorized**: remove expired rows.

Archive and purge need both the approved period and the approval reference.
The application can archive or purge only the log classes; for the other
classes an approved removal is `APPROVED_NOT_AUTOMATED` and needs its own
reviewed procedure.

| Class | Records | Tables | Can be processed automatically | State |
| --- | --- | --- | --- | --- |
| `employee_master` | employee master and employment status history | employees, employment_status_histories, employee_correction_requests | no | NEEDS_POLICY_DECISION_RETENTION |
| `assignment_history` | assignment, occupancy and transfer history | employee_assignments, position_occupancies, employee_transfers | no | NEEDS_POLICY_DECISION_RETENTION |
| `id_card_history` | ID cards, requests, print and replacement history | id_cards, card_requests, card_replacements, card_issuances, card_print_batches, card_print_batch_items, id_card_print_snapshots | no | NEEDS_POLICY_DECISION_RETENTION |
| `grievance_cases` | grievance cases | grievances and their histories, responses, decision letters | no | NEEDS_POLICY_DECISION_RETENTION |
| `discipline_cases` | discipline cases | disciplinary cases, charges, decisions, evidence | no | NEEDS_POLICY_DECISION_RETENTION |
| `appeal_and_tribunal_cases` | appeals and administrative tribunal cases | disciplinary, performance and assessment appeals; tribunal cases | no | NEEDS_POLICY_DECISION_RETENTION |
| `performance_records` | performance and assessment records | performance plans, reviews, results; assessment records and result versions | no | NEEDS_POLICY_DECISION_RETENTION |
| `provider_transactions` | service and provider transactions, settlements | service, cafeteria and transport transactions; settlements | no | NEEDS_POLICY_DECISION_RETENTION |
| `uploaded_documents` | uploaded documents and case attachments | employee_documents and stored files | no | NEEDS_POLICY_DECISION_RETENTION |
| `audit_logs` | audit log and security events | audit_logs, security_events | yes (audit rows of an open or held grievance stay; seals of removed days are dropped) | NEEDS_POLICY_DECISION_RETENTION |
| `verification_logs` | card and NFC verification logs | card_verifications, nfc_verification_logs | yes | NEEDS_POLICY_DECISION_RETENTION |
| `api_logs` | partner API request logs | api_request_logs | yes | NEEDS_POLICY_DECISION_RETENTION |

Operational housekeeping that is not a record keeps its automatic windows:
read notifications 90 days, all notifications 365 days, daily-activity
reminders 90 days. Integration events and webhook deliveries are purged only
when `INTEGRATION_EVENT_RETENTION_DAYS` / `INTEGRATION_DELIVERY_RETENTION_DAYS`
are set (unset: kept); they belong to the data-sharing review. Module-specific
housekeeping (communication, Intelligence, assistant) is listed for the same
review in [technical-debt.md](technical-debt.md).

## Recording an approval

1. The approvers sign the schedule for a class (action, period, legal basis).
2. The Database/Infrastructure Administrator sets in production `RETENTION_<CLASS>_ACTION`, `RETENTION_<CLASS>_DAYS` (audit logs may instead use Security › Audit Retention Days) and `RETENTION_<CLASS>_APPROVAL=<reference>`, then `config:cache`.
3. `php artisan data:retention-report` shows the class as AUTHORIZED (or APPROVED_RETAIN); `php artisan data:prune-retention --dry-run` shows what the next run will archive or purge.
4. Every run that changes data writes an audit row (`RETENTION_CLEANUP`) with the class, action, period, cutoff, counts and the approval reference.

Before this decision (ADR 0004) audit, verification and API logs were
deleted automatically after 730, 365 and 90 days. Those deletions stopped:
the logs are now kept until their class is approved.
