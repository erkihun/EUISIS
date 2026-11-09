# Database ownership

Which application owns each table once Core and the services are separated.
Today both run in one application and one database; ownership already decides
who may write (see [core-authority.md](core-authority.md)).

## Core-owned (authoritative)

| Area | Tables |
| --- | --- |
| Organization | `organization_types`, `organizations`, `organization_name_histories`, `hierarchy_versions`, `organization_edges`, `organization_closure_paths`, `organizational_change_requests`, `organizational_change_request_items`, `organizational_change_request_reviews`, `organizational_change_request_history`, `organizational_change_request_attachments`, `organization_change_requests` (legacy requests) |
| Units | `organization_unit_types`, `organization_units`, `organization_unit_relationships` |
| Positions | `grade_levels`, `occupations`, `isco_groups`, `professions`, `specializations`, `positions`, `position_establishments`, `position_occupancies`, `position_movements`, `position_profiles`, `job_descriptions`, `job_description_sections`, `isic_activities` |
| Employees | `employees`, `employment_status_histories`, `employee_documents`, `employee_duplicate_flags`, `employee_import_batches`, `employee_import_batch_rows`, `employee_correction_requests`, `employee_contact_verifications`, `employee_registration_otps` |
| Assignments | `employee_assignments` |
| Credentials | `id_cards`, `card_requests`, `card_print_batches`, `card_print_batch_items`, `card_replacements`, `card_issuances`, `card_verifications`, `id_card_print_snapshots`, `id_card_templates`, `card_templates`, `nfc_credentials` |
| Access | `users`, Spatie `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `user_organization_scopes` |
| Integration | `external_applications`, `external_application_endpoints`, `api_endpoint_definitions`, `personal_access_tokens`, `api_clients` (legacy), `api_request_logs`, `api_idempotency_keys`, `integration_events`, `integration_aggregate_versions`, `integration_feed_state`, `integration_feed_consumers`, `integration_replays`, `webhook_subscriptions`, `webhook_deliveries`, `webhook_delivery_attempts` |
| Governance | `audit_logs`, `code_rules`, `code_rule_sequences`, `code_generation_logs`, `system_settings` (Core groups: general, localization, security, appearance, id_cards) |
| Legacy, to retire | `institution_offices`, `institution_office_relationships` (evidence: `backbone:duplicate-masters`) |

## Service-owned

Every other table belongs to the module that writes it: cafeteria (`cafeteria_*`, `employee_cafeteria_exclusions`, `organization_cafeteria_*`), transport (`transport_*`), providers, transfers and vacancies (`employee_transfers`, `transfer_*`, `vacancy_*`), performance and assessment (`employee_performance_*`, `employee_competency_assessments`, `assessment_*`, `performance_*`), feedback (`employee_service_feedback*`, `employee_feedback_tokens`, `organization_feedback_qr_codes`, `public_*`), grievance, discipline, court cases, daily and field work, organization design (`position_competencies`, `position_service*`, design tables), communication, help desk, Situation Room, intelligence.

A service table may hold a Golden Path UUID as a reference and `*_snapshot` columns as evidence. It never holds a copy that others treat as the master.

## Foreign keys across the boundary

While one database serves both, foreign keys from service tables to Core tables stay: they protect integrity. When the databases separate, each one becomes a stable UUID reference validated through the API and kept fresh by events (and, where a service must work while Core is unreachable, a read-only local projection with `source_updated_at`). No synchronous cross-database writes.

## Validation

`php artisan core:snapshot` fingerprints the Core tables (counts and checksums of identifying columns in id order); comparing a snapshot taken before and after a copy proves nothing was lost, added or changed.
