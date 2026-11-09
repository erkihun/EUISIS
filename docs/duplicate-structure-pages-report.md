# Duplicate Structure Pages Report

**Date:** 2026-06-02

---

## Summary

After inspecting all pages, routes, and controllers, **no true duplicate pages or conflicting routes** were found. The following is a detailed breakdown of findings.

---

## Route Analysis

### Organization-related routes

| Route Name | URL | Controller Method | Notes |
|---|---|---|---|
| `organizations.index` | GET /organizations | OrganizationController@index | Tree view using published HierarchyVersion |
| `organizations.create` | GET /organizations/create | OrganizationController@create | Single create form |
| `organizations.store` | POST /organizations | OrganizationController@store | — |
| `organizations.show` | GET /organizations/{id} | OrganizationController@show | Shows institution offices inline |
| `organizations.edit` | GET /organizations/{id}/edit | OrganizationController@edit | — |
| `organizations.update` | PATCH /organizations/{id} | OrganizationController@update | — |
| `organizations.archive` | DELETE /organizations/{id} | OrganizationController@archive | — |
| `organizations.parent-options` | GET /organizations/parent-options | OrganizationController@parentOptions | JSON endpoint |
| `organizations.units.options` | GET /organizations/{id}/units/options | OrganizationUnitController@options | JSON, scoped to org |
| `organizations.units.tree` | GET /organizations/{id}/units/tree | OrganizationUnitController@tree | JSON, scoped to org |
| `institutions.offices.index` | GET /institutions/{id}/offices | InstitutionOfficeController@index | Alias — same controller as institution-offices.index |
| `institutions.offices.tree` | GET /institutions/{id}/offices/tree | InstitutionOfficeController@tree | JSON tree |
| `institutions.offices.parent-options` | GET /institutions/{id}/offices/parent-options | InstitutionOfficeController@parentOptions | JSON |

**Finding:** `institutions.offices.index` is an alias route to the same controller method as `institution-offices.index` filtered by institution. This is intentional for scoped access, not a true duplicate.

---

## Page Analysis

### Organizations

| Page | Route | Notes |
|---|---|---|
| `Organizations/Index.tsx` | organizations.index | Tree-based index with hierarchy version selector |
| `Organizations/Create.tsx` | organizations.create | Create form |
| `Organizations/Edit.tsx` | organizations.edit | Edit form |
| `Organizations/Show.tsx` | organizations.show | Detail with inline institution offices list |
| `Organizations/VersionShow.tsx` | hierarchy-versions.tree | Org view within a hierarchy version context |

**Finding:** `VersionShow` is used inside the hierarchy version tree edit flow, not a standalone org page. Not a duplicate.

### Institution Offices

| Page | Route | Notes |
|---|---|---|
| `InstitutionOffices/Index.tsx` | institution-offices.index | Filterable list with pagination |
| `InstitutionOffices/Create.tsx` | institution-offices.create | Create form |
| `InstitutionOffices/Edit.tsx` | institution-offices.edit | Edit form |
| `InstitutionOffices/Show.tsx` | institution-offices.show | Detail with child offices |
| `InstitutionOffices/Tree.tsx` | (JSON tree, no standalone page route) | Used within Show page |

**Finding:** No duplicate pages. `Tree.tsx` is a component-like page rendered from the tree endpoint.

### Organization Units

| Page | Route | Notes |
|---|---|---|
| `OrganizationUnits/Index.tsx` | organization-units.index | Dual-panel: org tree on left, units on right |
| `OrganizationUnits/Create.tsx` | organization-units.create | Create form |
| `OrganizationUnits/Edit.tsx` | organization-units.edit | Edit form |
| `OrganizationUnits/Show.tsx` | organization-units.show | Detail with children |

**Finding:** No duplicate pages.

### Hierarchy Versions

| Page | Route | Notes |
|---|---|---|
| `HierarchyVersions/Index.tsx` | hierarchy-versions.index | List |
| `HierarchyVersions/Create.tsx` | hierarchy-versions.create | Create form |
| `HierarchyVersions/Edit.tsx` | hierarchy-versions.edit | Edit metadata |
| `HierarchyVersions/Show.tsx` | hierarchy-versions.show | Version detail |
| `HierarchyVersions/Tree.tsx` | hierarchy-versions.tree | Read-only tree visualization |
| `HierarchyVersions/EditTree.tsx` | hierarchy-versions.tree.edit | Drag-and-drop tree editor |

**Finding:** `Tree.tsx` (read-only) and `EditTree.tsx` (editable) serve distinct purposes. Not duplicates.

---

## Tree Views Comparison

| Tree | Source of truth | Scope | Purpose |
|---|---|---|---|
| Organizations/Index tree | OrganizationEdge + HierarchyVersion | All accessible orgs in published version | Show org reporting hierarchy |
| HierarchyVersions/Tree | OrganizationEdge for a specific version | That version's edges | Read-only visualization of any version |
| HierarchyVersions/EditTree | OrganizationEdge for draft version | Draft version only | Manage parent-child relationships |
| InstitutionOffices/Tree | institution_offices.parent_office_id | One institution | Office branch hierarchy |
| OrganizationUnits (inline in Index) | organization_units.parent_unit_id | One organization | Unit hierarchy within org |

**Finding:** All five tree views are clearly distinct in scope and purpose. No consolidation needed.

---

## Recommendations

1. **No pages to delete or consolidate.** All pages serve distinct purposes.
2. **Sidebar reorder** (cosmetic): Move Institution Offices above Organization Units for clarity. Current order has them reversed relative to the recommended domain hierarchy.
3. **Organizations/Show**: Already shows Institution Offices inline — good. Consider linking to unit tree from there too.
4. **The `institutions.offices.index` alias route** could be documented more clearly but is not a duplicate risk.

---

## Conclusion

The codebase is free of duplicate pages or conflicting routes for the organization structure modules. The main improvements are cosmetic (sidebar order) and educational (help text i18n keys).
