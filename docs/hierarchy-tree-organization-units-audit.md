# Hierarchy Tree — Organization Units Audit

**Date:** 2026-06-08
**Scope:** Adding Organization Units as tree children under their Organizations in the Hierarchy Tree

---

## Pre-Change State

| Area | Current Behavior | Problem |
|---|---|---|
| `OrganizationScopeService::buildVersionTree()` | Builds tree from `organization_edges` only | Organization Units never appear in tree output |
| `HierarchyVersionController::tree()` | Calls `buildVersionTree()`, passes result as `tree` prop | No org units; no `include_units` filter |
| `HierarchyVersionController::show()` | Same as above | Same |
| `HierarchyTreeNodeResource` | Only exposes legacy org-node fields | No `type`, `id`, `meta`, `node_type_label` fields |
| `HierarchyTreeNode.tsx` | Renders organization nodes only; uses `organization_id` as node key | Cannot render org unit nodes; keys break if unit added |
| `HierarchyTree.tsx` | Filters on `name_en`, `name_am`, `organization_type` only | `label` field of unit nodes would not be searched |
| `treeUtils.ts` | Uses `node.organization_id` for all expand/collapse keys | Unit UUIDs stored in `organization_id` field; works but semantically wrong |
| `types.ts` | No `type` discriminator, no `meta` field, no `HierarchyTreeNodeType` | TypeScript cannot express unit node shape |
| i18n (EN/AM) | No "Organization Unit" badge labels, no position/employee count labels | Missing keys cause runtime warnings |

---

## Source-of-Truth Decision (Confirmed)

- **Organizations** → `organization_edges` + `hierarchy_versions` (canonical, versioned)
- **Organization Units** → `organization_units.organization_id` (which org) + `organization_units.parent_unit_id` (parent unit)
- **Functional relationships** → `organization_unit_relationships` — NOT structural tree children

This matches the existing architecture in `docs/organization-model.md`.

---

## Changes Made

### New File: `app/Services/Hierarchy/HierarchyTreeService.php`

- Replaces the org-only `OrganizationScopeService::buildVersionTree()` for the tree/show pages
- Loads all orgs in **1 query**, all units for those orgs in **1 query**, all unit types in **1 query**, position/employee counts in **4 bulk GROUP BY queries** — zero N+1
- Accepts `include_units` (default `true`), `include_inactive` (default `false`) filters
- Org unit nodes are appended to each org node's `children` array after child org nodes
- Functional reporting is NOT added as structural children (correct per architecture)
- User scope is enforced via `OrganizationScopeService::accessibleOrganizationIds()`
- Both node types emit a unified shape (`id`, `type`, `label`, `node_type_label`, `meta`) **plus** legacy fields (`organization_id`, `name_en`, etc.) for zero-breakage backwards compatibility

### Updated: `app/Http/Resources/HierarchyTreeNodeResource.php`

- Added new unified fields: `id`, `type`, `label`, `node_type_label`, `node_type_label_am`, `meta`
- Legacy fields retained unchanged

### Updated: `app/Http/Controllers/Web/HierarchyVersionController.php`

- `show()`: now uses `HierarchyTreeService::buildFullTree()` instead of `OrganizationScopeService::buildVersionTree()`
- `tree()`: same, plus accepts `include_units` and `include_inactive` query params, passes `filters` to Inertia
- `editTree()`: same service swap

### Updated: `resources/js/Components/hierarchy/types.ts`

- Added `HierarchyTreeNodeType = 'organization' | 'organization_unit'`
- Added `HierarchyTreeNodeMeta` type
- Extended `HierarchyTreeNodeData` with `id`, `type`, `label`, `node_type_label`, `node_type_label_am`, `meta`
- Legacy fields retained

### Updated: `resources/js/Components/hierarchy/HierarchyTreeNode.tsx`

- `OrganizationAvatar` → `NodeAvatar`: emits green avatar for units, blue for orgs
- Toggle/expand key uses `node.id ?? node.organization_id` (works for both types)
- `organizationName()` → `nodeName()`: falls back to `node.label`
- `organizationTypeName()` → `nodeTypeName()`: prefers new unified `node_type_label` fields
- Added "Organization" (blue) and "Unit" (green) type badges per node
- Added position count (violet) and employee count (teal) badges from `node.meta`
- Org unit nodes: show green "Details" link to `organization-units.show`; hide org edge management buttons (Add/Edit/Remove relation)
- Org nodes: unchanged behavior

### Updated: `resources/js/Components/hierarchy/HierarchyTree.tsx`

- Filter includes `node.label` in the searchable fields
- Node key uses `node.id ?? node.organization_id`

### Updated: `resources/js/Components/hierarchy/treeUtils.ts`

- `collectExpandableIds` and `collectExpandedIdsToDepth` use `node.id ?? node.organization_id`

### Updated: i18n files (EN + AM)

- `resources/js/i18n/en/hierarchyVersions.ts`: added `organizationNode`, `organizationUnitNode`, `fullHierarchyTree`, `includeOrganizationUnits`, `showFunctionalReporting`, `hideFunctionalReporting`, `positionCount`, `employeeCount`, `unitCount`, `treeIncludesOrganizationsAndUnits`, `functionalReportingSecondary`, `noHierarchyData`
- `resources/js/i18n/am/hierarchyVersions.ts`: same keys in Amharic
- `lang/en/hierarchy-versions.php`: same keys in snake_case
- `lang/am/hierarchy-versions.php`: same keys in Amharic

### New Test File: `tests/Feature/HierarchyTreeTest.php`

13 Pest tests covering:
1. `hierarchy tree includes organization nodes` — org nodes appear with correct type
2. `hierarchy tree includes organization units under correct organization` — units appear under their org
3. `child organization units appear recursively` — nested `parent_unit_id` tree is built correctly
4. `organization units do not appear when include_units is false` — filter works
5. `functional reporting is NOT rendered as structural child by default` — functional rels ignored
6. `inactive units are hidden by default` — only `active` status shown
7. `include_inactive=true shows inactive units` — filter works
8. `tree response returns correct unit_count on org node meta` — meta counts correct
9. `tree HTTP endpoint includes organization units when include_units is true` — HTTP layer works
10. `tree node for organization_unit has correct type field` — `type`, `node_type_label`, `can` fields correct
11. `EN and AM translation files contain new organization unit tree keys` — i18n completeness
12. `hierarchy tree view tree page receives tree prop` — controller passes `filters` prop
13. `show page tree includes organization units in the tree prop` — show page uses new service

---

## What Was NOT Changed

- `OrganizationScopeService::buildVersionTree()` — kept intact (used by nothing after this PR, but not removed)
- All existing Organization, Org Unit, Employee, Position, Assignment, RBAC, audit log, dashboard code — untouched
- `organization_edges`, `hierarchy_versions`, `organization_units` tables — no migrations added
- Functional reporting: visible only via `meta.functional_reports_to` array (currently empty; populated in a future iteration when `include_functional_relationships=true` is wired up)

---

## Functional Reporting — Display Behavior

Functional relationships (`organization_unit_relationships`) are **not** rendered as tree children. The `meta.functional_reports_to` array on unit nodes is reserved for future use (currently empty). This is correct per the architecture rules.

---

## Build & Test Status

```
vendor/bin/pint --test: passed
php artisan test --filter="HierarchyTree|HierarchyVersion": 36 passed (219 assertions)
npm run build: ✓ built in ~24s (no TypeScript errors, no Vite errors)
```

Pre-existing failing tests (51): UniqueConstraintViolation due to shared `makeOrg()` helper across parallel test files — these fail before AND after this PR. Not caused by this change.

---

## Known Limitations / Next Steps

1. **`include_functional_relationships` filter** — accepted by service but `meta.functional_reports_to` is always `[]`. A follow-up can query `organization_unit_relationships` and populate it.
2. **`search` filter** — accepted by service signature but frontend search filtering happens client-side in `HierarchyTree.tsx`. Server-side search can be added to `buildFullTree()`.
3. **`HierarchyVersionController::tree()` — `filters` Inertia prop** — the Tree.tsx page receives this but does not yet render a "Show Units" toggle checkbox. A UI toggle can be wired to an Inertia `visit()` with `?include_units=false`.
4. **`summarizeVersionTree()`** still counts org unit nodes as `total_organizations` (it walks all children). A future iteration should differentiate the summary counts.
