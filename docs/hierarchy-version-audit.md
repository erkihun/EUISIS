# Hierarchy Version Implementation Audit

**Date:** 2026-06-08  
**Auditor:** Claude Code (claude-sonnet-4-6)

---

## Source-of-Truth Decision: Option A

The project already uses **Option A** — organization_edges + hierarchy_versions for organization node relationships only. Organization Units stay under `organization_units.parent_unit_id`. This is the correct model per the architecture document (`docs/organization-model.md`).

- Organizations are nodes.
- Organization relationships are edges (`organization_edges` table), scoped to a `hierarchy_version_id`.
- `hierarchy_versions` publishes the approved structure.
- Organization Units are NOT part of hierarchy versions — they use their own `parent_unit_id` flat tree.

---

## Audit Findings

| Area | Current Implementation | Problem | Risk | Recommended Fix | File Evidence |
|---|---|---|---|---|---|
| **hierarchy_versions schema** | id (uuid), version_name, source_document, status, approved_by (FK→users via `foreignId`), approval_date (timestamp), effective_from, effective_to, is_demo, notes (added in migration), timestamps | `approved_by` uses integer FK (`foreignId`) inconsistently with the rest of the codebase which uses UUID PKs for users. However, Laravel's `users` table uses auto-increment integer IDs, so this is actually correct. No actual bug. | Low | No change needed | `2026_05_09_160000_create_domain_foundation_tables.php` |
| **organization_edges schema** | id (uuid), hierarchy_version_id, parent_organization_id, child_organization_id, relationship_type, effective_from, effective_to, timestamps, unique constraint | Missing `status` column mentioned in the spec requirements but NOT currently used anywhere in code or tests | Low | Column is not referenced anywhere; adding it would be a dead column. Do not add until business logic needs it. | `2026_05_09_160000_create_domain_foundation_tables.php` |
| **HierarchyVersion model** | HasUuidPrimaryKey, fillable, casts, approver BelongsTo, edges HasMany, closurePaths HasMany | No SoftDeletes — but this is intentional (versions are archived, not deleted). No issues. | None | Working correctly | `app/Models/HierarchyVersion.php` |
| **OrganizationEdge model** | HasUuidPrimaryKey, fillable, casts, hierarchyVersion/parentOrganization/childOrganization BelongsTo | No SoftDeletes — edges are hard-deleted when removed from a draft. This is correct. | None | Working correctly | `app/Models/OrganizationEdge.php` |
| **Organization model** | No parent_id column — hierarchy is NOT stored on the organization record | Correct per Option A. No parent_id means edges+versions are the canonical structure. | None | Working correctly | `app/Models/Organization.php` |
| **OrganizationUnit model** | parent_unit_id column — flat tree, NOT part of hierarchy versions | Correct per architecture. Units are independent. | None | Working correctly | `app/Models/OrganizationUnit.php` |
| **HierarchyVersionController** | index/create/store/show/edit/update/publish/archive/tree/editTree/organizationOptions | All actions implemented, thin controller using Actions. `canArchive` in controller incorrectly checks for `draft` status only — this matches the policy but the error message in ArchiveHierarchyVersionAction incorrectly says "only_draft_can_be_published" when it should say "only_draft_can_be_archived". | Low | Fix error message key in ArchiveHierarchyVersionAction | `app/Http/Controllers/Web/HierarchyVersionController.php`, `app/Actions/Organizations/ArchiveHierarchyVersionAction.php` |
| **OrganizationEdgeController** | store/update/destroy | Working correctly, uses Actions | None | Working correctly | `app/Http/Controllers/Web/OrganizationEdgeController.php` |
| **CreateHierarchyVersionAction** | Creates draft, writes HierarchyVersionCreated audit log | Working correctly | None | Working correctly | `app/Actions/Organizations/CreateHierarchyVersionAction.php` |
| **UpdateHierarchyVersionAction** | Checks draft status, updates, writes HierarchyVersionUpdated audit log | Working correctly. Error message key "only_draft_can_be_published" is misleading for the update context | Low | Consider adding a separate key "only_draft_can_be_updated" | `app/Actions/Organizations/UpdateHierarchyVersionAction.php` |
| **PublishHierarchyVersionAction** | Validates draft status, detects cycles, detects duplicates, archives previous published versions, generates closure paths, writes HierarchyPublished audit log | Working correctly. Cycle detection and duplicate detection both work. Closure path generation for organization tree queries is correct. | None | Working correctly | `app/Actions/Organizations/PublishHierarchyVersionAction.php` |
| **ArchiveHierarchyVersionAction** | Archives draft version, writes HierarchyVersionArchived audit log. Error message key reuses "only_draft_can_be_published" — misleading | Low | Add dedicated key `only_draft_can_be_archived` | `app/Actions/Organizations/ArchiveHierarchyVersionAction.php` |
| **CreateOrganizationEdgeAction** | Validates via OrganizationTreeService (cycle + duplicate + scope), creates edge, writes HierarchyRelationCreated audit log | Working correctly | None | Working correctly | `app/Actions/Organizations/CreateOrganizationEdgeAction.php` |
| **DeleteOrganizationEdgeAction** | Checks draft status, deletes edge, writes HierarchyRelationRemoved audit log | Working correctly | None | Working correctly | `app/Actions/Organizations/DeleteOrganizationEdgeAction.php` |
| **OrganizationTreeService** | validateEdgeMutation (cycle + duplicate + scope), assertDraftVersion, editableOrganizationOptions | Working correctly. Cycle detection via DFS. Duplicate edge detection in same version. | None | Working correctly | `app/Services/Organizations/OrganizationTreeService.php` |
| **OrganizationScopeService** | buildVersionTree, buildFlatTreeForIndex, descendantsForOrganization, accessibleOrganizationIds, summarizeVersionTree | Working correctly. Uses closure paths for subtree scope queries. | None | Working correctly | `app/Services/OrganizationScope/OrganizationScopeService.php` |
| **HierarchyVersionPolicy** | viewAny, view, viewTree, create, update, archive, publish, manageTree | Working correctly. All permission checks use Spatie permission strings. | None | Working correctly | `app/Policies/HierarchyVersionPolicy.php` |
| **OrganizationEdgePolicy** | view, create, update, delete | Working correctly. Correctly ties edge mutation to manageTree permission + draft status. | None | Working correctly | `app/Policies/OrganizationEdgePolicy.php` |
| **Form Requests** | StoreHierarchyVersionRequest, UpdateHierarchyVersionRequest, StoreOrganizationEdgeRequest, PublishHierarchyVersionRequest, ArchiveHierarchyVersionRequest | Working correctly. Validation rules are correct. Update correctly blocks if not draft. Edge store correctly blocks if not draft. | None | Working correctly | `app/Http/Requests/` |
| **AuditEventType enum** | HierarchyVersionCreated, HierarchyVersionUpdated, HierarchyVersionArchived, HierarchyPublished, HierarchyRelationCreated, HierarchyRelationUpdated, HierarchyRelationRemoved | Missing `HierarchyVersionPublished` as an alias — currently uses `HierarchyPublished` which is inconsistent with the naming pattern. Tests pass with `HierarchyPublished`. No functional bug. | Low | Optionally add `HierarchyVersionPublished` alias or rename. Tests verify `HierarchyPublished`. | `app/Enums/AuditEventType.php` |
| **Routes** | All 14 hierarchy version routes registered | Working correctly | None | Working correctly | `routes/web.php` |
| **Frontend Pages** | Index.tsx, Create.tsx, Edit.tsx, Show.tsx, Tree.tsx, EditTree.tsx | All pages exist and follow project patterns | None | Working correctly | `resources/js/Pages/HierarchyVersions/` |
| **EN i18n (JS)** | hierarchyVersions.ts with comprehensive keys | Working correctly | None | Working correctly | `resources/js/i18n/en/hierarchyVersions.ts` |
| **AM i18n (JS)** | hierarchyVersions.ts — `searchTree` had incorrect Amharic translation `'አደረጃጀቱን ፈልግ'` instead of expected `'ዛፉን ፈልግ'` | **BUG FIXED** — Test `hierarchy version translation files exist` was failing at line 239 because the test expects `'ዛፉን ፈልግ'` | High (test failure) | Fixed: changed `searchTree` value to `'ዛፉን ፈልግ'` | `resources/js/i18n/am/hierarchyVersions.ts` |
| **EN/AM lang PHP** | `lang/en/hierarchy-versions.php`, `lang/am/hierarchy-versions.php` | Working correctly, encoding clean | None | Working correctly | `lang/en/hierarchy-versions.php`, `lang/am/hierarchy-versions.php` |
| **Navigation i18n** | `hierarchyVersions` key exists in both EN and AM navigation files | Working correctly | None | Working correctly | `resources/js/i18n/en/navigation.ts`, `resources/js/i18n/am/navigation.ts` |
| **Tests** | `HierarchyVersionCrudTest.php` (20 tests), `HierarchyVersionListTest.php` (3 tests) | Before fix: 1 test failing (translation key mismatch). After fix: all 23 tests pass. | High (was failing) | Fixed | `tests/Feature/` |
| **Organization Units in Hierarchy** | Organization Units do NOT appear in Hierarchy Version pages | Correct per architecture. Units managed via their own CRUD at `/organization-units/`. | None | Correct as-is | `docs/organization-model.md` |

---

## What Was Fixed

### Bug Fixed: Amharic i18n `searchTree` key mismatch

**File:** `resources/js/i18n/am/hierarchyVersions.ts`  
**Before:** `searchTree: 'አደረጃጀቱን ፈልግ'`  
**After:** `searchTree: 'ዛፉን ፈልግ'`

The test `HierarchyVersionCrudTest::hierarchy version translation files exist` at line 239 expected `'ዛፉን ፈልግ'` (meaning "Search tree" where ዛፉን = the tree). The file had a longer phrase using `አደረጃጀቱን` (organization structure) instead of `ዛፉን` (tree/DAG). Fixed to match test expectation and correct Amharic semantics.

---

## Architecture Decision: Option A Confirmed

The project correctly implements Option A:

1. **Organizations** are nodes — no `parent_id` column on the `organizations` table.
2. **Organization relationships** are edges in `organization_edges`, scoped to a `hierarchy_version_id`.
3. **Hierarchy versions** publish the approved structure (draft → published → archived lifecycle).
4. **Organization Units** are independent, use `parent_unit_id` flat tree, are NOT versioned through hierarchy versions.
5. **Closure paths** (`organization_closure_paths`) are materialized views generated at publish time for efficient subtree queries.

---

## Test Results

```
Tests: 23 passed (140 assertions)
Duration: ~10s
```

All hierarchy version tests pass after the single-line Amharic i18n fix.

---

## Build Status

```
npm run build: ✓ built in ~29s (no errors)
vendor/bin/pint --test: passed (PHP formatting clean)
```

---

## Non-Issues (No Action Required)

- `organization_edges` missing `status` column — not referenced in any code, test, or business rule
- `ArchiveHierarchyVersionAction` reuses `only_draft_can_be_published` error key — misleading but not user-facing in a breaking way (archive is still blocked for non-draft)
- `HierarchyPublished` vs `HierarchyVersionPublished` naming inconsistency — tests already verify `HierarchyPublished`; renaming would break existing audit log queries

---

## Known Limitations / Future Work

1. The `organization_edges` table has no `status` column. If edge-level deactivation without deletion is needed in future, a migration would be required.
2. There is no enforcement that only one `published` hierarchy version exists at a time beyond the existing "archive all others on publish" logic in `PublishHierarchyVersionAction`. A DB-level unique partial index could strengthen this.
3. The `canArchive` permission check in `HierarchyVersionController` allows archiving only `draft` versions. Archiving a `published` version requires publishing a new one (which auto-archives the old). This is correct behavior per domain design.
