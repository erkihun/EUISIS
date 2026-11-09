# Database To UI Coverage

Last updated: 2026-05-13

This inventory maps migrated database tables to the current secured web UI. "Existing UI Page" reflects the current Laravel + Inertia route/page implementation in this repository.

| Database Table | Model | Existing UI Page | UI Status | Required UI Type | Permission Group | Notes |
|---|---|---|---|---|---|---|
| users | `User` | `Users/*` | Complete | Full CRUD | `users.*` | Includes role assignment and activation workflow. |
| password_reset_tokens | none | none | Not Required | API/Admin Only | n/a | Backend-only auth support. |
| sessions | none | none | Backend Only | Not Applicable | n/a | No admin session UI yet. |
| permissions | Spatie `Permission` | `Permissions/Index` | Partial | Read Only | `permissions.view*` | Listing exists; create/update UI still pending by design. |
| roles | Spatie `Role` | `Roles/*` | Complete | Full CRUD | `roles.*` | Permission assignment supported. |
| model_has_permissions | pivot | none | Backend Only | Not Applicable | n/a | Managed through users/roles flows. |
| model_has_roles | pivot | none | Backend Only | Not Applicable | n/a | Managed through users/roles flows. |
| role_has_permissions | pivot | none | Backend Only | Not Applicable | n/a | Managed through roles UI. |
| personal_access_tokens | Sanctum token model | none | Backend Only | API/Admin Only | n/a | No token management UI yet. |
| system_settings | `SystemSetting` | `SystemSettings/Index` | Complete | Settings UI | `system-settings.*` | Secrets remain masked. |
| code_rules | `CodeRule` | `CodeRules/*`, `RecycleBin/Index` | Complete | Full CRUD | `code-rules.*`, `recycle-bin.*` | Reusable code generation rules with preview, soft delete/restore, and generation logs. |
| code_generation_logs | `CodeGenerationLog` | `CodeRules/Show` | Read Only | Read Only | `code-rules.view*` | Limited log view is embedded in the code rule detail page. |
| jobs | none | none | Backend Only | Not Applicable | n/a | Queue infrastructure. |
| job_batches | none | none | Backend Only | Not Applicable | n/a | Queue infrastructure. |
| failed_jobs | none | none | Backend Only | Not Applicable | n/a | Queue infrastructure. |
| cache | none | none | Backend Only | Not Applicable | n/a | Runtime cache storage. |
| cache_locks | none | none | Backend Only | Not Applicable | n/a | Runtime cache storage. |
| organization_types | `OrganizationType` | `OrganizationTypes/*` | Complete | Full CRUD | `organization-types.*` | Active/archive/restore supported. |
| organizations | `Organization` | `Organizations/*` | Partial | Full CRUD | `organizations.*` | Core list/create/show/edit exists; parent options are now scope-aware and searchable, but draft publish flow is still required for new child nodes to appear in the published tree. |
| hierarchy_versions | `HierarchyVersion` | `HierarchyVersions/*` | Complete | Workflow Actions | `hierarchy-versions.*` | Index, create, show, edit, publish, archive, polished tree view, and modal-based draft tree editing are wired with draft-only edit rules. |
| organization_edges | `OrganizationEdge` | `HierarchyVersions/Tree` + `HierarchyVersions/EditTree` | Complete | Workflow Actions | `organization-edges.*`, `hierarchy-versions.manageTree` | Managed inside hierarchy version tree workflows with add/edit/remove relation modals, scoped organization search, and audit logging. |
| organization_name_histories | `OrganizationNameHistory` | organization detail sections | Partial | Read Only | `organizations.view*` | Read-only historical data, no dedicated index page yet. |
| organization_closure_paths | `OrganizationClosurePath` | none | Backend Only | API/Admin Only | n/a | Derived hierarchy cache table. |
| organization_change_requests | `OrganizationChangeRequest` | none | Missing | Workflow Actions | `organizations.*` | Still pending dedicated workflow UI. |
| positions | `Position` | `Positions/*`, `RecycleBin/Index` | Complete | Full CRUD | `positions.*`, `recycle-bin.*` | Job position code uniqueness enforced; delete uses Recycle Bin. |
| employees | `Employee` | `Employees/*` | Complete | List + Detail | `employees.*` | Create, show, update, scoped filters, duplicate warnings. |
| employee_assignments | `EmployeeAssignment` | employee detail | Partial | Read Only | `employees.*` | Historical assignment UI is embedded, not standalone. |
| employment_status_histories | `EmploymentStatusHistory` | employee detail | Partial | Read Only | `employees.view*` | Needs dedicated history section expansion. |
| employee_documents | `EmployeeDocument` | employee detail | Partial | List + Detail | `employees.*` | Metadata foundation exists; upload/download workflow still pending. |
| employee_duplicate_flags | `EmployeeDuplicateFlag` | employee detail warnings | Partial | Read Only | `employees.view*` | Surfaced as warnings, no standalone queue page yet. |
| user_organization_scopes | `UserOrganizationScope` | user edit/assignment flows | Partial | Settings UI | `users.*` | No dedicated organization scopes module yet. |
| api_clients | `ApiClient` | none | Missing | Full CRUD | `api-clients.*` | Priority admin module still pending. |
| device_bindings | `DeviceBinding` | none | Missing | Full CRUD | `device-bindings.*` | Priority admin module still pending. |
| security_events | `SecurityEvent` | none | Missing | Read Only | `audit-logs.*` | Security/audit summary exists on dashboard only. |
| card_requests | `CardRequest` | `CardRequests/*` | Complete | Workflow Actions | `id-cards.*` | Submit, verify, approve, reject, cancel. |
| card_templates | `CardTemplate` | none | Missing | Full CRUD | `id-cards.*` | Template admin UI pending. |
| id_cards | `IdCard` | `IdCards/*` | Complete | List + Detail | `id-cards.*` | Lifecycle actions supported. |
| card_print_batches | `CardPrintBatch` | `PrintBatches/*` | Partial | Workflow Actions | `id-cards.*` | Batch creation/detail exists; broader operations pending. |
| card_print_batch_items | `CardPrintBatchItem` | print batch detail | Partial | Read Only | `id-cards.*` | Nested within batch pages. |
| card_issuances | `CardIssuance` | id card detail | Partial | List + Detail | `id-cards.*` | Exposed through card lifecycle, not standalone index. |
| card_replacements | `CardReplacement` | id card detail | Partial | List + Detail | `id-cards.*` | Exposed through card lifecycle, not standalone index. |
| card_verifications | `CardVerification` | none | Missing | Read Only | `card-verifications.*` | API and dashboard exist; dedicated read-only UI pending. |
| service_types | `ServiceType` | `ServiceTypes/*`, `RecycleBin/Index` | Complete | Full CRUD | `service-types.*`, `recycle-bin.*` | Delete uses Recycle Bin; codes remain reserved. |
| service_providers | `ServiceProvider` | `ServiceProviders/*` | Partial | Full CRUD | `providers.*` | List/detail exists; create/edit remains pending. |
| service_provider_users | `ServiceProviderUser` | none | Missing | Settings UI | `providers.*` | Needs provider user assignment UI. |
| entitlement_rules | `EntitlementRule` | `EntitlementRules/*`, `RecycleBin/Index` | Complete | Full CRUD | `entitlement-rules.*`, `recycle-bin.*` | Delete uses Recycle Bin. |
| entitlements | `Entitlement` | `Entitlements/*` | Partial | List + Detail | `entitlements.*` | Listing/grant exists; revoke/detail workflow still expanding. |
| service_transactions | `ServiceTransaction` | provider/dashboard summaries | Partial | List + Detail | `service-transactions.*` | API exists; dedicated transaction UI still limited. |
| provider_settlements | `ProviderSettlement` | none | Missing | List + Detail | `providers.*` | Backend/report foundation only. |
| settlement_runs | `SettlementRun` | none | Missing | Workflow Actions | `providers.*` | Backend/report foundation only. |
| transport_plans | `TransportPlan` | none | Missing | Full CRUD | `transport-plans.*` | Priority 2 pending. |
| transport_routes | `TransportRoute` | none | Missing | Full CRUD | `transport-routes.*` | Priority 2 pending. |
| transport_usages | `TransportUsage` | none | Missing | List + Detail | `transport-usages.*` | Priority 2 pending. |
| transport_settlements | `TransportSettlement` | none | Missing | List + Detail | `transport-settlements.*` | Priority 2 pending. |
| meal_plans | `MealPlan` | none | Missing | Full CRUD | `meal-plans.*` | Priority 2 pending. |
| coupon_programs | `CouponProgram` | none | Missing | Full CRUD | `coupon-programs.*` | Priority 2 pending. |
| coupon_balances | `CouponBalance` | none | Missing | List + Detail | `coupon-balances.*` | Priority 2 pending. |
| cafeteria_transactions | `CafeteriaTransaction` | none | Missing | List + Detail | `cafeteria-transactions.*` | Priority 2 pending. |
| consumer_memberships | `ConsumerMembership` | none | Missing | Full CRUD | `consumer-memberships.*` | Priority 2 pending. |
| consumer_limits | `ConsumerLimit` | none | Missing | Full CRUD | `consumer-limits.*` | Priority 2 pending. |
| consumer_transactions | `ConsumerTransaction` | none | Missing | List + Detail | `consumer-transactions.*` | Priority 2 pending. |
| approval_workflows | `ApprovalWorkflow` | none | Missing | Full CRUD | `approval-workflows.*` | Priority 1 pending. |
| approval_steps | `ApprovalStep` | none | Missing | Full CRUD | `approval-steps.*` | Priority 1 pending. |
| approvals | `Approval` | none | Missing | Workflow Actions | `approvals.*` | Priority 3 pending. |
| task_assignments | `TaskAssignment` | none | Missing | Workflow Actions | `task-assignments.*` | Priority 3 pending. |
| audit_logs | `AuditLog` | `AuditLogs/Index` | Complete | Read Only | `audit-logs.*` | Immutable viewer only. |
| notifications_foundation | model pending | none | Missing | Read Only | `notifications.*` | Monitoring module pending. |
| support_tickets | `SupportTicket` | none | Missing | Workflow Actions | `support-tickets.*` | Monitoring/support module pending. |
| employee_transfers | `EmployeeTransfer` | `Transfers/*` | Complete | Workflow Actions | `transfers.*` | Separate module with confirmations and approvals. |

## Current Priority Gaps

1. `approval_workflows`
2. `approval_steps`
3. `api_clients`
4. `device_bindings`
5. `user_organization_scopes` dedicated admin UI
6. `card_verifications` read-only monitoring page
7. transport, cafeteria, and consumer association modules

## Code Rule Integration Status (per entity)

| Entity | Backend Generate | Frontend CodeRuleField | Edit Read-Only | Notes |
| --- | --- | --- | --- | --- |
| organization | Complete | Complete (Create.tsx) | Complete (Edit.tsx) | Context-aware: re-fetches on org-type change |
| organization_type | Complete | Complete (Create.tsx) | Complete (Edit.tsx) | Global rule |
| employee | Complete | Complete (Create.tsx) | Not applicable (employee_number hidden on edit) | Context-aware: org_id |
| position | Complete | Complete (Create.tsx) | Complete (Edit.tsx) | Context-aware: org_id |
| id_card | Complete (via ApproveCardRequestAction) | No form field — system-assigned on approval | Not applicable | Card number not user-visible pre-approval |
| service_type | Complete (2026-05-14) | Complete (Form.tsx) | Complete (Edit.tsx via Form.tsx) | Global rule |
| service_provider | Partial (codeExists check only) | Not integrated | — | No create form yet |
| entitlement_rule | Not integrated | Not integrated | — | No code column in EntitlementRule model confirmed |
| card_request | Not integrated | Not integrated | — | Seeded rule exists but no create-form field |

## Current Slice Completed In This Update (2026-05-14)

- `CodeRuleField` reusable frontend component (`resources/js/Components/code-rules/CodeRuleField.tsx`)
- `POST /code-rules/preview-code` endpoint for entity create forms (does not increment sequence)
- ServiceType code auto-generation wired into `CreateServiceTypeAction`
- `CodeRuleField` integrated into: Organizations Create/Edit, OrganizationTypes Create/Edit, Positions Create/Edit, Employees Create, ServiceTypes Form
- 26 i18n keys added (13 en + 13 am) for form field integration
- 15 new tests in `tests/Feature/CodeRulePreviewCodeTest.php`
- Architecture docs updated

## Previous Slice Completed

- `service_types` full CRUD UI
- `entitlement_rules` full CRUD UI
- `code_rules` full CRUD UI with code preview and generation logs
- navigation + localization wiring for both modules
- database seed cleanup for demo user foreign-key references
