# EUISIS production flow map

How one employee gets from "organization exists" to "meal paid to the caterer",
as the code implements it on 2026-09-26. Each step names the route a person
uses, the code that enforces the rules, the tables written, the permission
checked, and the automated test that walks it.

The whole path is exercised end to end, through HTTP only, by
`tests/Feature/GoldenPath/GoldenPathTest.php` (test 80). Negative paths are in
the same file (test 82). Cross-organization cafeteria use (organization A's
employee eating at organization B's cafeteria) is
`tests/Feature/Cafeteria/CrossOrganizationUsageTest.php`.

```
Organization ─► Unit ─► Position ─► Employee + current Assignment
                                         │
             Staff user + organization scope (who may see / act on whom)
                                         │
Card request ─► approval ─► print ─► issue ─► activate ─► public QR check
                                         │
Provider ─► Network ─► Main + branch cafeterias
                                         │
Organization access ─► Service assignment ─► Policy (maker ≠ checker)
                                         │
Counter scan (provider portal) ─► Transaction ─► Provider ledger
                                         │
Organization liability ─► Settlement (draft ─► finalize) ─► Exports
```

## 1. Organization

| | |
|---|---|
| Route | `POST /organizations` (`organizations.store`) |
| Code | `OrganizationController@store`; code from the active Organization code rule (`GenerateCodeAction`) |
| Tables | `organizations` (unique `code`, soft deletes, `merged_into_id`) |
| Delete | `DeleteOrganizationAction` — row lock, then `OrganizationDeletionGuard::canBeDeletedSafely`; refused while the organization is still in use; soft delete with audit |
| Hierarchy | `hierarchy_versions` (draft → published), `organization_edges`; structural changes go through `OrganizationalChangeRequest` → `ApplyApprovedOrganizationalChangeService` |
| Audit command | `php artisan structure:audit` |

## 2. Units and positions

| | |
|---|---|
| Routes | `POST /organization-units`, `POST /positions` |
| Rules | unit code unique per organization; `parent_unit_id` must stay in the same organization and must not form a cycle; position `code` and `job_position_code` unique; position code from the Position code rule |
| Capacity | `position_establishments.approved_slots` (vacancy module, `PositionCapacityService`) — see NEEDS_DECISION D-2 in the readiness assessment |
| Gaps | `positions.organization_id` has no foreign key; detected by `structure:audit` |

## 3. Staff users, roles and organization scope

| | |
|---|---|
| Routes | `POST /users` (temporary password, forced change on first sign-in), `POST /users/{user}/organization-scopes` |
| Code | Spatie roles/permissions (`Super Admin` bypasses via `Gate::before`); `OrganizationScopeService` (`isUnrestricted`, `canAccess`, `applyOrganizationScope`) |
| Tables | `users`, `model_has_roles`, `user_organization_scopes` (`scope_type`: self, subtree, service_provider, citywide) |
| Default roles | `App\Support\Rbac\DefaultRoleMatrix` |
| Guards | `web` (staff), `provider` (`provider_users`, provider portal), `cafeteria_provider` (legacy, routes now redirect) |

## 4. Employee and assignment

| | |
|---|---|
| Route | `POST /employees` (`employees.store`) |
| Code | `EmployeeController@store` → `RegisterEmployeeAction` (one DB transaction: position row lock, one current occupant, employee number from the Employee code rule, first assignment, status history, duplicate detection, feedback QR token, audit) |
| Tables | `employees` (unique `employee_number`; `national_id` encrypted + `national_id_hash`), `employee_assignments` (`is_current`), `employment_status_histories`, `employee_feedback_tokens` |
| Later moves | `CompleteTransferAction`, `CompleteVacancyTransferAction` (employee and assignment row locks) |
| Audit command | `php artisan data:audit-duplicates` |

## 5. ID card

| Step | Route | Code | Status after |
|---|---|---|---|
| Request | `POST /card-requests` | `SubmitCardRequestAction`, `CardRequestEligibility` | request `submitted` |
| Approve (second person) | `POST /card-requests/{id}/approve` | `ApproveCardRequestAction` — employee row lock, request re-read under lock, one live card per employee | card `pending_print` |
| Print | `POST /id-cards/{card}/prepare-print`, `POST /id-cards/{card}/print/{snapshot}/confirm` | `IdCardPrintSnapshotService` (`print` / `reprint` gate) | `printed` |
| Issue | `POST /id-cards/{card}/issue` | `IssueCardAction` | `issued` |
| Activate | `POST /id-cards/{card}/activate` | `ActivateCardAction` | `active` |

The printed QR carries only `{APP_URL or ID_CARD_QR_BASE_URL}/id-checker/{public_card_uuid}`
(`CardQrPayloadService`); the back carries the holder's feedback QR
`/service-feedback/{token}`. `QrPayloadSecurityValidator` refuses any other
payload shape. The card number and employee data are never in a QR.

## 6. Public verification

`GET /id-checker/{uuid}` shows whether a card exists and is checkable; the
holder's details appear only after the one-time code sent to the holder
(`POST /id-checker/{uuid}/send-otp`, `verify-otp`, throttled). The legacy
`/verify/card/{uuid}` permanently redirects here.

## 7. Scan eligibility (QR and NFC)

`EmployeeServiceEligibilityService` refuses lost, replaced, revoked, expired,
suspended, damaged and not-yet-active cards, expired cards by date, and
inactive employees, before any policy or money logic runs. NFC credentials
(`NfcCredentialService`, `NfcVerificationService`) resolve to the same card.

## 8. Provider, network, cafeterias

| | |
|---|---|
| Route | `POST /cafeteria/providers` (provider + network + main cafeteria in one step; branches join an existing network under its main cafeteria) |
| Tables | `providers`, `provider_services`, `cafeteria_service_networks` (one provider), `cafeteria_providers` (location: main / branch / service point) |
| Portal accounts | `POST /provider-users` → `provider_users` (docs/provider-portal-accounts.md) |
| Audit command | `php artisan cafeteria:audit-configuration` |

## 9. Organization access, service assignment, policy

| Record | Route | Approval |
|---|---|---|
| Organization access to a network (primary cafeteria, cross-location flag) | `POST /cafeteria/organization-access` | `…/approve` by a second person |
| Service assignment (organization ↔ provider ↔ network) | `POST /cafeteria/service-assignments` | `…/approve` |
| Policy (subsidy, contribution, price, days, limits, dates) | `POST /cafeteria/service-policies` → `submit` → `approve` | the drafter cannot approve; new terms are a new version (`policy_group_id`, `version_no`) |

`CafeteriaPolicyResolver` picks exactly one policy for (employee organization,
cafeteria, date); the employee's organization — not the cafeteria's — decides
the subsidy (docs/cafeteria-policy-architecture.md).

## 10. Counter scan → transaction

| | |
|---|---|
| Routes | provider portal `POST /provider/portal/scan` (guard `provider`); back office `POST /cafeteria/scan`; NFC API |
| Code | `ProviderScanController` → `ProcessCafeteriaQrScanAction` → `CafeteriaQrScanService` (card + employee row locks, eligibility, policy, entitlement, pricing) → `CafeteriaTransactionService::record` |
| Idempotency | unique `scan_nonce` and `scan_request_hash`; a replay returns the first result |
| Tables | `cafeteria_transactions` (billing owner `employee_organization_id`, served at `cafeteria_provider_id`, payee `provider_id`, policy id + snapshot), `cafeteria_transaction_consumed_days`, `service_transactions`, `cafeteria_provider_ledger_entries` |
| Who scanned | staff scans: `created_by`; portal operator scans: `metadata.scanned_by_provider_user_id` and the audit entry |

## 11. Liability, settlement, exports

| | |
|---|---|
| Liability | settlement preview / show page, grouped by employee organization and cafeteria |
| Settlement | `POST /cafeteria/settlements` (draft for provider + period) → `…/finalize` (links transactions) or `…/cancel`; permission `cafeteria_settlements.manage` |
| Exports | back office `GET /cafeteria/transactions/export/{pdf,xlsx,print}`; provider portal CSV / XLSX / PDF and payment claim; all throttled 10/min |

## Operational commands

| Command | Purpose |
|---|---|
| `production:readiness [--strict]` | go-live gate (runs `security:production-check`) |
| `data:audit-duplicates`, `structure:audit`, `cafeteria:audit-configuration` | read-only data audits; exit 1 on HIGH findings |
| `cafeteria:sync-policy-statuses` | daily 00:05 — approved → active → expired |
| `api:prune-logs`, `nfc:prune-challenges` | daily housekeeping |
| `daily-activities:send-reminders` | every 15 minutes |
