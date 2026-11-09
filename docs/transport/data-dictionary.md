# Transport — Data Dictionary

Generated from the migrated schema (SQLite column affinities shown; PostgreSQL types follow the migrations: uuid ids, `DECIMAL(14,2)` money, `date`/`timestamp`, `jsonb` for `terms`, `basis`, `contract_snapshot`, `eligibility_snapshot`, `detail`, `metadata`). Every table has `created_at`/`updated_at`; `deleted_at` marks soft deletes.

## `transport_contracts`

Versioned terms: `settlement_model`, `settlement_period`, `terms` (rates by key), `status` draft|active|superseded|ended|cancelled; `organization_id` null = city-wide.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `contract_reference` | varchar | no |  |
| `organization_id` | varchar | yes |  |
| `provider_id` | varchar | no |  |
| `version_no` | INTEGER | no |  |
| `supersedes_contract_id` | varchar | yes |  |
| `status` | varchar | no |  |
| `currency_code` | varchar | no |  |
| `settlement_model` | varchar | no |  |
| `settlement_period` | varchar | no |  |
| `terms` | TEXT | yes |  |
| `service_coverage` | TEXT | yes |  |
| `agreement_path` | varchar | yes |  |
| `terms_reference` | varchar | yes |  |
| `effective_from` | date | no |  |
| `effective_to` | date | yes |  |
| `approved_by` | INTEGER | yes |  |
| `approved_at` | datetime | yes |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

Unique: `tc_reference_version_unique` (contract_reference, version_no).

## `transport_devices`

Scanner: `credential_hash` sha256, `credential_prefix` for display; `status` active|suspended|revoked; `transport_vehicle_id` binds it to one bus.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `transport_vehicle_id` | varchar | yes |  |
| `device_code` | varchar | no |  |
| `name` | varchar | no |  |
| `status` | varchar | no |  |
| `credential_hash` | varchar | no |  |
| `credential_prefix` | varchar | no |  |
| `platform` | varchar | yes |  |
| `software_version` | varchar | yes |  |
| `last_seen_at` | datetime | yes |  |
| `last_sync_at` | datetime | yes |  |
| `last_ip` | varchar | yes |  |
| `registered_by` | INTEGER | yes |  |
| `revoked_at` | datetime | yes |  |
| `revoked_by` | INTEGER | yes |  |
| `revocation_reason` | varchar | yes |  |
| `metadata` | TEXT | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

Unique: `transport_devices_credential_hash_unique` (credential_hash); `td_provider_code_unique` (provider_id, device_code).

## `transport_drivers`

Provider drivers; `license_expiry_date` feeds the expired-documents check.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `full_name` | varchar | no |  |
| `phone_number` | varchar | yes |  |
| `license_number` | varchar | yes |  |
| `license_expiry_date` | date | yes |  |
| `status` | varchar | no |  |
| `assigned_vehicle_id` | varchar | yes |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

## `transport_eligibilities`

Grant: `subject_type` employee|organization with `scope_type` self|subtree; `authorization_reference` is the minute/letter.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `subject_type` | varchar | no |  |
| `employee_id` | varchar | yes |  |
| `organization_id` | varchar | yes |  |
| `scope_type` | varchar | no |  |
| `provider_id` | varchar | yes |  |
| `transport_contract_id` | varchar | yes |  |
| `status` | varchar | no |  |
| `effective_from` | date | no |  |
| `effective_to` | date | yes |  |
| `authorization_reference` | varchar | yes |  |
| `authorized_by` | INTEGER | yes |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

## `transport_exceptions`

Operational facts: `type` (TransportExceptionType), `severity` info|warning|critical, `status` open|reviewed|dismissed, `detail` context.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `transport_trip_id` | varchar | yes |  |
| `transport_transaction_id` | varchar | yes |  |
| `employee_id` | varchar | yes |  |
| `transport_vehicle_id` | varchar | yes |  |
| `transport_device_id` | varchar | yes |  |
| `type` | varchar | no |  |
| `severity` | varchar | no |  |
| `status` | varchar | no |  |
| `detail` | TEXT | yes |  |
| `occurred_at` | datetime | no |  |
| `reviewed_by` | INTEGER | yes |  |
| `reviewed_at` | datetime | yes |  |
| `review_notes` | TEXT | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_invoices`

Provider claim: `status` draft|submitted|under_review|returned|disputed|approved|withdrawn; `claimed_*` are the provider's figures.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `invoice_number` | varchar | no |  |
| `provider_id` | varchar | no |  |
| `transport_contract_id` | varchar | yes |  |
| `period_start` | date | no |  |
| `period_end` | date | no |  |
| `claimed_boardings` | INTEGER | yes |  |
| `claimed_trips` | INTEGER | yes |  |
| `claimed_employee_days` | INTEGER | yes |  |
| `claimed_amount` | numeric | no |  |
| `currency_code` | varchar | no |  |
| `status` | varchar | no |  |
| `attachment_path` | varchar | yes |  |
| `notes` | TEXT | yes |  |
| `submitted_by_provider_user_id` | varchar | yes |  |
| `submitted_at` | datetime | yes |  |
| `returned_reason` | TEXT | yes |  |
| `reviewed_by` | INTEGER | yes |  |
| `reviewed_at` | datetime | yes |  |
| `transport_settlement_id` | varchar | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

Unique: `tinv_provider_number_unique` (provider_id, invoice_number).

## `transport_passes`

Route assignment: employee → provider route with `boarding_stop_id`/`evening_stop_id`; `status` active|suspended|expired|cancelled; `suspended_reason` set by the re-evaluator.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `employee_id` | varchar | no |  |
| `provider_id` | varchar | no |  |
| `transport_route_id` | varchar | yes |  |
| `valid_from` | date | no |  |
| `valid_until` | date | yes |  |
| `status` | varchar | no |  |
| `issued_by` | INTEGER | yes |  |
| `issued_at` | datetime | yes |  |
| `notes` | TEXT | yes |  |
| `metadata` | TEXT | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `boarding_stop_id` | varchar | yes |  |
| `evening_stop_id` | varchar | yes |  |
| `transport_eligibility_id` | varchar | yes |  |
| `suspended_reason` | varchar | yes |  |
| `suspended_at` | datetime | yes |  |

## `transport_plans`

Legacy foundation table (unused by the verified-usage module; kept, not removed).

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `service_provider_id` | varchar | no |  |
| `name` | varchar | no |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_provider_profiles`

Transport-specific provider profile (licence, dispatch phone, service area).

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `license_number` | varchar | yes |  |
| `registration_number` | varchar | yes |  |
| `contact_person` | varchar | yes |  |
| `dispatch_phone` | varchar | yes |  |
| `service_area_description_en` | TEXT | yes |  |
| `service_area_description_am` | TEXT | yes |  |
| `operating_days` | TEXT | yes |  |
| `operating_hours` | TEXT | yes |  |
| `status` | varchar | no |  |
| `metadata` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

Unique: `transport_provider_profiles_provider_id_unique` (provider_id).

## `transport_route_stops`

Ordered stops; soft-deleted when removed so history keeps them.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `transport_route_id` | varchar | no |  |
| `stop_code` | varchar | no |  |
| `name_en` | varchar | no |  |
| `name_am` | varchar | yes |  |
| `latitude` | numeric | yes |  |
| `longitude` | numeric | yes |  |
| `sequence` | INTEGER | no |  |
| `pickup_allowed` | tinyint(1) | no |  |
| `dropoff_allowed` | tinyint(1) | no |  |
| `is_active` | tinyint(1) | no |  |
| `effective_from` | date | yes |  |
| `effective_to` | date | yes |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

Unique: `trs_route_stop_code_unique` (transport_route_id, stop_code).

## `transport_routes`

`assigned_scope_type` self|subtree|citywide with `assigned_organization_id`.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `transport_plan_id` | varchar | yes |  |
| `name` | varchar | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |
| `provider_id` | varchar | yes |  |
| `route_code` | varchar | yes |  |
| `name_en` | varchar | yes |  |
| `name_am` | varchar | yes |  |
| `origin_en` | varchar | yes |  |
| `origin_am` | varchar | yes |  |
| `destination_en` | varchar | yes |  |
| `destination_am` | varchar | yes |  |
| `distance_km` | numeric | yes |  |
| `estimated_duration_minutes` | INTEGER | yes |  |
| `assigned_organization_id` | varchar | yes |  |
| `assigned_scope_type` | varchar | no |  |
| `is_active` | tinyint(1) | no |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |

Unique: `transport_routes_provider_id_route_code_unique` (provider_id, route_code).

## `transport_schedules`

Recurring departures; `service_days` JSON list 1–7 (Mon–Sun).

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `transport_route_id` | varchar | no |  |
| `direction` | varchar | no |  |
| `service_days` | TEXT | no |  |
| `planned_departure` | time | no |  |
| `planned_arrival` | time | yes |  |
| `skips_public_holidays` | tinyint(1) | no |  |
| `default_vehicle_id` | varchar | yes |  |
| `default_driver_id` | varchar | yes |  |
| `effective_from` | date | no |  |
| `effective_to` | date | yes |  |
| `is_active` | tinyint(1) | no |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |

## `transport_service_calendar_days`

`kind` operating|suspended; `provider_id`/`transport_route_id` null = city-wide.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | yes |  |
| `transport_route_id` | varchar | yes |  |
| `calendar_date` | date | no |  |
| `kind` | varchar | no |  |
| `name_en` | varchar | yes |  |
| `name_am` | varchar | yes |  |
| `created_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_settlement_adjustments`

`origin` provider_dispute|organization_adjustment; `kind` usage|financial; signed `amount`; `status` pending|approved|rejected.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `transport_settlement_id` | varchar | no |  |
| `origin` | varchar | no |  |
| `kind` | varchar | no |  |
| `dispute_type` | varchar | yes |  |
| `amount` | numeric | no |  |
| `quantity` | INTEGER | yes |  |
| `reason` | TEXT | no |  |
| `evidence_path` | varchar | yes |  |
| `status` | varchar | no |  |
| `requested_by_provider_user_id` | varchar | yes |  |
| `requested_by_user_id` | INTEGER | yes |  |
| `decided_by` | INTEGER | yes |  |
| `decided_at` | datetime | yes |  |
| `decision_notes` | TEXT | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_settlements`

Statement: counts from evidence, `contract_snapshot`, `basis` (lines, by_route, data_quality), `calculated_amount`/`claimed_amount`/`adjustment_total`/`approved_amount` kept apart, `policy_gaps` codes.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_settlement_id` | varchar | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `settlement_number` | varchar | yes |  |
| `provider_id` | varchar | yes |  |
| `transport_contract_id` | varchar | yes |  |
| `transport_invoice_id` | varchar | yes |  |
| `organization_id` | varchar | yes |  |
| `period_start` | date | yes |  |
| `period_end` | date | yes |  |
| `status` | varchar | no |  |
| `contract_snapshot` | TEXT | yes |  |
| `basis` | TEXT | yes |  |
| `verified_boardings` | INTEGER | no |  |
| `unique_riders` | INTEGER | no |  |
| `employee_days` | INTEGER | no |  |
| `completed_trips` | INTEGER | no |  |
| `cancelled_trips` | INTEGER | no |  |
| `currency_code` | varchar | no |  |
| `calculated_amount` | numeric | yes |  |
| `claimed_amount` | numeric | yes |  |
| `approved_amount` | numeric | yes |  |
| `adjustment_total` | numeric | no |  |
| `computed_by` | INTEGER | yes |  |
| `reviewed_by` | INTEGER | yes |  |
| `approved_by` | INTEGER | yes |  |
| `payment_marked_by` | INTEGER | yes |  |
| `computed_at` | datetime | yes |  |
| `reviewed_at` | datetime | yes |  |
| `approved_at` | datetime | yes |  |
| `payment_marked_at` | datetime | yes |  |
| `rejected_reason` | TEXT | yes |  |
| `payment_reference` | varchar | yes |  |
| `notes` | TEXT | yes |  |
| `policy_gaps` | TEXT | yes |  |

Unique: `transport_settlements_settlement_number_unique` (settlement_number).

## `transport_transactions`

One row per scan, accepted or refused; append-only. `status` accepted|rejected; `result_code` the engine's reason; `verification_method` qr|nfc|manual_exception; `direction` inbound|outbound; `boarding_leg` date+direction key for the per-day duplicate policy; `eligibility_snapshot` basis and card/employee state at the scan; `sync_state` online|offline_pending|offline_reconciled|offline_rejected; `voided_at`/`void_reason`/`voided_by_user_id` for voids; `transport_settlement_id` once counted on a statement; `qr_reference_hash` never the QR value.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `employee_id` | varchar | no |  |
| `id_card_id` | varchar | yes |  |
| `transport_pass_id` | varchar | yes |  |
| `transport_route_id` | varchar | yes |  |
| `transport_trip_id` | varchar | yes |  |
| `scanned_at` | datetime | no |  |
| `transaction_date` | date | no |  |
| `status` | varchar | no |  |
| `result_code` | varchar | yes |  |
| `rejection_reason` | varchar | yes |  |
| `scan_nonce` | varchar | yes |  |
| `qr_reference_hash` | varchar | yes |  |
| `scanned_by_provider_user_id` | varchar | yes |  |
| `metadata` | TEXT | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `transport_vehicle_id` | varchar | yes |  |
| `transport_device_id` | varchar | yes |  |
| `transport_route_stop_id` | varchar | yes |  |
| `direction` | varchar | yes |  |
| `verification_method` | varchar | no |  |
| `boarding_leg` | varchar | yes |  |
| `eligibility_snapshot` | TEXT | yes |  |
| `sync_state` | varchar | no |  |
| `offline_recorded_at` | datetime | yes |  |
| `voided_at` | datetime | yes |  |
| `voided_by_user_id` | INTEGER | yes |  |
| `void_reason` | TEXT | yes |  |
| `transport_settlement_id` | varchar | yes |  |
| `manual_reason` | TEXT | yes |  |
| `recorded_by_user_id` | INTEGER | yes |  |

Unique: `ttx_one_accepted_boarding_per_trip` (transport_trip_id, employee_id); `transport_transactions_scan_nonce_unique` (scan_nonce).

Partial unique (PostgreSQL/SQLite): `ttx_one_accepted_boarding_per_trip` on (`transport_trip_id`, `employee_id`) where `status = accepted AND voided_at IS NULL AND transport_trip_id IS NOT NULL`.

## `transport_trip_positions`

Vehicle positions behind the gps flag; purged after `gps_retention_days`.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `transport_trip_id` | varchar | no |  |
| `transport_device_id` | varchar | yes |  |
| `latitude` | numeric | no |  |
| `longitude` | numeric | no |  |
| `speed_kmh` | numeric | yes |  |
| `heading` | INTEGER | yes |  |
| `recorded_at` | datetime | no |  |
| `created_at` | datetime | yes |  |

## `transport_trip_vehicle_changes`

Mid-trip substitutions with reason and actor.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `transport_trip_id` | varchar | no |  |
| `from_vehicle_id` | varchar | yes |  |
| `to_vehicle_id` | varchar | yes |  |
| `from_driver_id` | varchar | yes |  |
| `to_driver_id` | varchar | yes |  |
| `reason` | TEXT | no |  |
| `changed_at` | datetime | no |  |
| `changed_by_provider_user_id` | varchar | yes |  |
| `changed_by_user_id` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_trips`

One run of a route in one direction on one date. `status` planned|assigned|boarding|in_progress|completed|cancelled|failed; `capacity` overrides the vehicle's approved capacity; `boarded_count` is maintained by the engine; `is_extra`/`approved_by` for trips outside the schedule.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `transport_route_id` | varchar | no |  |
| `transport_vehicle_id` | varchar | yes |  |
| `transport_driver_id` | varchar | yes |  |
| `trip_number` | varchar | no |  |
| `trip_date` | date | no |  |
| `departure_time` | time | yes |  |
| `arrival_time` | time | yes |  |
| `status` | varchar | no |  |
| `capacity` | INTEGER | yes |  |
| `boarded_count` | INTEGER | no |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `direction` | varchar | yes |  |
| `transport_schedule_id` | varchar | yes |  |
| `planned_departure_at` | datetime | yes |  |
| `planned_arrival_at` | datetime | yes |  |
| `actual_started_at` | datetime | yes |  |
| `actual_ended_at` | datetime | yes |  |
| `cancelled_at` | datetime | yes |  |
| `started_by_provider_user_id` | varchar | yes |  |
| `completed_by_provider_user_id` | varchar | yes |  |
| `cancelled_by_provider_user_id` | varchar | yes |  |
| `cancelled_by_user_id` | INTEGER | yes |  |
| `cancellation_reason` | TEXT | yes |  |
| `is_extra` | tinyint(1) | no |  |
| `approved_by` | INTEGER | yes |  |
| `expected_riders` | INTEGER | yes |  |
| `distance_km` | numeric | yes |  |
| `completion_notes` | TEXT | yes |  |

Unique: `tt_one_trip_per_schedule_date` (transport_schedule_id, trip_date); `transport_trips_trip_number_unique` (trip_number).

Partial unique: `tt_one_trip_per_schedule_date` on (`transport_schedule_id`, `trip_date`) where `transport_schedule_id IS NOT NULL AND status <> cancelled`.

## `transport_usages`



| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `service_transaction_id` | varchar | no |  |
| `transport_route_id` | varchar | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |

## `transport_vehicles`

`capacity` is the approved capacity; `status` active|maintenance|inactive|suspended.

| Column | Type | Null | Key |
| --- | --- | --- | --- |
| `id` | varchar | no | PK |
| `provider_id` | varchar | no |  |
| `vehicle_code` | varchar | no |  |
| `plate_number` | varchar | no |  |
| `vehicle_type` | varchar | yes |  |
| `capacity` | INTEGER | yes |  |
| `status` | varchar | no |  |
| `assigned_route_id` | varchar | yes |  |
| `model` | varchar | yes |  |
| `year` | INTEGER | yes |  |
| `color` | varchar | yes |  |
| `notes` | TEXT | yes |  |
| `created_by` | INTEGER | yes |  |
| `updated_by` | INTEGER | yes |  |
| `created_at` | datetime | yes |  |
| `updated_at` | datetime | yes |  |
| `deleted_at` | datetime | yes |  |
| `standing_capacity` | INTEGER | yes |  |
| `inspection_expires_at` | date | yes |  |
| `insurance_expires_at` | date | yes |  |
| `permit_expires_at` | date | yes |  |
| `maintenance_until` | date | yes |  |

Unique: `transport_vehicles_plate_number_unique` (plate_number); `transport_vehicles_provider_id_vehicle_code_unique` (provider_id, vehicle_code).

