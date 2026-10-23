# Data ownership

Who owns each record, what identifies it, and whether anyone else may write it.
Maintained alongside the code; the dated evidence behind it is in the
[data ownership matrix](data-ownership-matrix.md) of the 19 October audit.

## Backbone masters

| Domain | Owner | Master id | Others may write? | Integration method |
| --- | --- | --- | --- | --- |
| Organization | Backbone | `organizations.id` | No | `/api/v1/organizations`, `organization.*` events |
| Organization type | Backbone | `organization_types.id` | No | read through organizations |
| Hierarchy version, edges, closure paths | Backbone | `hierarchy_versions.id` | No (approved change requests and publish only) | `hierarchy_version.published`, `/organizations/{id}/structure` |
| Organizational unit | Backbone | `organization_units.id` | No | `/api/v1/organization-units`, `organization_unit.*` events |
| Unit type | Backbone | `organization_unit_types.id` | No | read through units |
| Position | Backbone | `positions.id` | No | `/api/v1/positions`, `position.*` events |
| Position occupancy and establishment | Backbone (code currently in `Services/Vacancy`) | `position_occupancies.id` | No; recruitment and transfer use the capacity service | internal |
| Position profile, job description | Backbone | `position_profiles.id`, `job_descriptions.id` | No | internal |
| Grade, occupation, profession | Backbone | table `id` | No | internal |
| Employee | Backbone | `employees.id` (employee UUID) | No | `/api/v1/employees`, `employee.*` events, `EmployeeDirectory` |
| Employee status | Backbone | on `employees` | No | `employee.status_changed` |
| Assignment | Backbone | `employee_assignments.id` | No; transfer and vacancy call `TransferAssignment` | `/employees/{id}/assignment`, `assignment.*`, `employee.transferred` |
| ID card | Backbone | `id_cards.id`, public `public_card_uuid` | No | `/api/v1/id-cards/verify/{card_uuid}`, `id_card.*` events, `CardCredentialStatus` |
| Card verification log | Backbone | `card_verifications.id` | Written by verification only | internal |
| Code rules for these records | Backbone | `code_rules.id` | No | internal |
| Organization scope grants | Backbone (auth domain) | `user_organization_scopes.id` | No | internal |

## Service module records

| Domain | Owner | Master id | Others may write? | Integration method |
| --- | --- | --- | --- | --- |
| Cafeteria transactions, ledgers, settlements | Cafeteria | `cafeteria_transactions.id` | Cafeteria only | provider API, offline sync, settlements API |
| Transport trips, boardings, invoices, settlements | Transport | `transport_transactions.id` | Transport only | `/api/v1/transport/*` |
| Service transactions and entitlements | Service delivery | `service_transactions.id` | Owning service only | `/api/v1/services/{type}/*` |
| Recruitment (announcements, applications) | Recruitment | `vacancy_applications.id` | Recruitment only | internal |
| Transfer applications and records | Transfer | `transfer_applications.id`, `employee_transfers.id` | Transfer only | `employee.transferred` |
| Performance (EPMS) | EPMS | `performance_*` ids | EPMS only | internal |
| Daily work, field work | Daily work, field work | own ids | Owning module only | internal |
| Public feedback, suggestions, complaints | Public feedback | `employee_service_feedback.id` and public feedback tables | Public feedback only | public site |
| Grievance, discipline, appeal | Case modules | case ids | Case module only | internal |
| Situation Room, Intelligence, assistant | Analytics | own alert, forecast and audit rows | Own rows only | internal; reads aggregates |
| Provider staff, drivers | Provider domain | provider user and driver ids | Provider administration | provider portals |

Service records reference backbone records by UUID. Where historical, legal or
financial evidence needs it they also keep a minimal immutable snapshot
(`*_snapshot` columns); a snapshot is evidence and never replaces the reference.

## Known duplicates to retire (Stage 7)

| Copy | Class | Plan |
| --- | --- | --- |
| `users.national_id`, `users.national_id_hash` | duplicate master | read from the linked employee after a sign-in and recovery review |
| `institution_offices` | legacy organization master, routes already redirect | retire after confirming no row is referenced |
