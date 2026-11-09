# Organization Structure Audit

**Date:** 2026-06-02  
**Auditor:** Claude (claude-sonnet-4-6)  
**Scope:** Organizations, Organization Types, Organization Units, Organization Unit Types, Institution Offices, Hierarchy Versions

---

## Module Summary Table

| Module | Current Purpose | Correct Purpose | Duplicated With | Problem | Required Fix | Risk | Status |
|---|---|---|---|---|---|---|---|
| Organization | Legal/geographic entity (Bureau, Sub-city, Woreda) | Same — legal/geographic entity | None | Missing explicit `parent()` / `children()` BelongsTo/HasMany via edges | Add `organizationType()` alias; keep `type()` | Low | Needs alias |
| OrganizationType | Classifies organizations | Same — classifies organizations (Bureau, Sub-city) | None | `type()` relation exists on Organization; no `organizationType()` alias | Add alias if callers need it | Low | OK |
| InstitutionOffice | Branch/office of an institution at a geographic level | Same | None | Fully correct. `institution_id` and `geographic_organization_id` are distinct | None | Low | OK |
| OrganizationUnit | Internal team/department inside an org | Should belong to InstitutionOffice (migration exists, model OK) | None | `organization_id` still present for legacy; `institution_office_id` nullable (correct migration 2026_06_02_040000 exists) | Document dual-path; add validation warning | Low | OK |
| OrganizationUnitType | Classifies units (Directorate, Team) | Same | None | None | None | Low | OK |
| HierarchyVersion | Versioned snapshot of inter-organization edges | Same | None | No conflict with parent_id — organizations have no parent_id column; hierarchy is entirely via edges | None | Low | OK |
| OrganizationEdge | Inter-organization parent-child link within a version | Same | None | None | None | Low | OK |

---

## Current Table Relationships

```
organizations
  ├── organization_type_id → organization_types.id
  ├── merged_into_id → organizations.id (self-referential, nullable)
  └── (no parent_id — hierarchy via organization_edges)

organization_edges
  ├── hierarchy_version_id → hierarchy_versions.id
  ├── parent_organization_id → organizations.id
  └── child_organization_id → organizations.id

organization_units
  ├── organization_id → organizations.id
  ├── parent_unit_id → organization_units.id (self-referential, nullable)
  ├── organization_unit_type_id → organization_unit_types.id (nullable)
  └── institution_office_id → institution_offices.id (nullable) [added 2026-06-02]

institution_offices
  ├── institution_id → organizations.id
  ├── geographic_organization_id → organizations.id (nullable)
  └── parent_office_id → institution_offices.id (self-referential, nullable)

employee_assignments
  ├── employee_id → employees.id
  ├── organization_id → organizations.id
  └── organization_unit_id → organization_units.id (nullable)
```

---

## Recommended Final Relationships

```
Organization
  → organizationType(): BelongsTo OrganizationType         [EXISTS as type()]
  → mergedInto(): BelongsTo Organization                   [EXISTS]
  → institutionOffices(): HasMany InstitutionOffice         [EXISTS]
  → geographicInstitutionOffices(): HasMany InstitutionOffice [EXISTS]
  → organizationUnits(): HasMany OrganizationUnit           [EXISTS]
  → positions(): HasMany Position                           [EXISTS]
  → assignments(): HasMany EmployeeAssignment               [EXISTS]
  → parentEdges(): HasMany OrganizationEdge                 [EXISTS]
  → childEdges(): HasMany OrganizationEdge                  [EXISTS]

InstitutionOffice
  → institution(): BelongsTo Organization                   [EXISTS]
  → geographicOrganization(): BelongsTo Organization        [EXISTS]
  → parentOffice(): BelongsTo InstitutionOffice             [EXISTS]
  → childOffices(): HasMany InstitutionOffice               [EXISTS]
  → organizationUnits(): HasMany OrganizationUnit           [EXISTS]

OrganizationUnit
  → organization(): BelongsTo Organization                  [EXISTS - legacy path]
  → institutionOffice(): BelongsTo InstitutionOffice        [EXISTS - new path]
  → unitType(): BelongsTo OrganizationUnitType              [EXISTS]
  → parent(): BelongsTo OrganizationUnit                    [EXISTS]
  → children(): HasMany OrganizationUnit                    [EXISTS]
  → assignments(): HasMany EmployeeAssignment               [EXISTS]

HierarchyVersion
  → edges(): HasMany OrganizationEdge                       [EXISTS]
  → closurePaths(): HasMany OrganizationClosurePath         [EXISTS]
  → approver(): BelongsTo User                              [EXISTS]
```

**All required relationships are already correctly implemented.**

---

## Duplicate Fields / Pages / Routes Found

### No structural duplicates found. Key findings:

1. **`Organization.type()` vs `organizationType()`**: The relation is named `type()` in the model; the domain model calls it `organizationType()`. Not a bug — just a naming discrepancy. Callers use `->load('type')` correctly.

2. **`OrganizationUnit.unit_type` string column + `organization_unit_type_id` FK**: Both exist. The `StoreOrganizationUnitRequest::validated()` syncs them. This is intentional dual-path for backward compat.

3. **InstitutionOffices/Index.tsx + organizations/{id}/offices route**: The `InstitutionOfficeController@index` handles both `/institution-offices` and `/institutions/{org}/offices`. Same controller, appropriate.

4. **Tree pages**: `HierarchyVersions/Tree`, `InstitutionOffices/Tree`, `OrganizationUnits` (tree inside index) — each is distinct and correct.

5. **Sidebar order**: Current order is Organizations → Organization Types → Organization Units → Institution Offices → Organization Unit Types → Hierarchy Versions. Recommended order per task: Organizations → Organization Types → Institution Offices → Organization Units → Organization Unit Types → Hierarchy Versions. **Minor reorder needed.**

---

## Safe Migration Plan

No schema migrations required. All tables and columns are correct.

**Recommended non-breaking improvements (no downtime):**

1. Add `organizationType()` alias to `Organization` model (maps to `type()` — zero risk).
2. Reorder sidebar nav items (frontend-only change, zero risk).
3. Add help text i18n keys (additive only).
4. Add detection console commands (read-only, zero risk).
5. Add feature tests for audit coverage.

---

## Final Cleanup Checklist

- [x] `Organization` model has all required relations
- [x] `InstitutionOffice` model has all required relations
- [x] `OrganizationUnit` model has all required relations (both legacy and new path)
- [x] `HierarchyVersion` model has edges and closure paths
- [x] `StoreOrganizationUnitRequest` validates `parent_unit_id` must belong to same organization
- [x] `StoreInstitutionOfficeRequest` validates `parent_office_id` must belong to same institution
- [x] `OrganizationStoreRequest` validates `organization_type_id` exists
- [x] Backend i18n files exist for EN and AM
- [x] Frontend i18n files exist for EN and AM
- [ ] Sidebar items in recommended order (cosmetic)
- [ ] Help text i18n keys added
- [ ] Detection console commands created
- [ ] Audit structure feature tests created
- [ ] `organizationType()` alias added to `Organization` model
