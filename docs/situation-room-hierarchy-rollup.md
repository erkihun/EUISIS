# Situation Room — hierarchy roll-up

## Rule

A submission is counted:

- at the institution recorded on it when it was submitted
  (`employee_service_feedback.organization_id`), and
- at every ancestor of that institution in the **current published hierarchy
  version**.

The same `organization_closure_paths` rows decide access in
`OrganizationScopeService`, so "what I may see" and "what rolls up to me"
cannot disagree.

Totals are filters over distinct rows. Nothing is summed upward, so a
submission is never counted twice at one level.

## The scope filter

`SituationRoomScope::apply()` adds two conditions to every query:

```
organization_id IN (<the user's accessible organizations>)        -- skipped for unrestricted users
AND (organization_id = :node
     OR organization_id IN (SELECT descendant_organization_id
                            FROM organization_closure_paths
                            WHERE hierarchy_version_id = :published
                              AND ancestor_organization_id = :node))
```

The second condition applies only when an institution is selected. It is a
subquery, not a list of ids built in PHP.

A selected institution must itself be in the user's scope, or the request is
refused with a 403 (`SituationRoomScope::for` throws `AuthorizationException`).

## Comparison bars

The institutions compared are the **direct children** of the institution in
view (closure depth 1), limited to the user's scope:

- With no institution selected:
  - An unrestricted user gets the top-level institutions: those with no
    ancestor in the published version.
  - A scoped user gets their topmost authorized institutions.
- Each bar counts everything under that child, in one grouped join on the
  closure table.
- Submissions recorded at the institution in view itself are counted
  separately ("Recorded at X itself"). They are found with `NOT EXISTS` under
  any compared child.
- As a result, **the sum of the bars plus the own row equals the total.** A
  test checks this.

## Breadcrumb

The breadcrumb shows the ancestors of the institution in view, top first,
but **only those the user may open**. A woreda head sees no city or bureau
link (tested).

## Known limits

- **Multi-parent nodes.** The closure is built from every edge of the version.
  An institution reachable through two parents rolls up to both. Each
  parent's figure is still correct, but sibling bars may then overlap. Dev and
  scale data have no multi-parent node (`reports_to` edges only).
- **History.** Placement uses the current published version. A restructure
  moves past submissions under the new parent, while each submission keeps its
  own institution. Showing a past period in the hierarchy as it stood then is
  NEEDS_DECISION D7.
- **No published version.** Scope and totals still work. The institution
  comparison is empty, and the page says so.
- **Institutions outside the published hierarchy.**
  - They are counted at themselves.
  - In the city-wide view they appear as "recorded outside the published
    hierarchy".
  - See NEEDS_DATA N2.
