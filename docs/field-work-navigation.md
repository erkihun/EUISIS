# Field Work Navigation

The sidebar is defined in `resources/js/Components/AppSidebar.tsx`. Labels
come from `resources/js/i18n/{en,am}/navigation.ts`.

## Before and after

**Before:** there was no field work entry anywhere, admin or portal. The
only related string was the Daily Activity category "Field work".

**After:** two separate places, one per audience.

### Admin: one category, `Field Work Management`

| Sidebar item | Route | Page | Shown when the user holds |
|---|---|---|---|
| Dashboard | `field-work.dashboard` (`/field-work`) | `FieldWork/Dashboard` | `field_work.view_team` or `field_work.view_org` |
| Field Work Requests | `field-work.requests.index` | `FieldWork/Requests` | `field_work.view_team` or `field_work.view_org` |
| Pending Approvals | `field-work.pending` | `FieldWork/Approvals` | `field_work.approve`, `field_work.return` or `field_work.reject` |
| Team Field Work | `field-work.team.index` | `FieldWork/Team` | `field_work.view_team` |
| Team Availability | `field-work.availability.index` | `FieldWork/Availability` | `field_work.view_team` or `field_work.view_org` |
| Overdue / Unclosed | `field-work.overdue.index` | `FieldWork/Overdue` | `field_work.view_team` or `field_work.view_org` |
| Field Work Types | `field-work.types.index` | `FieldWork/Types` | `field_work.manage_types` |

The request detail page (`field-work.requests.show`) is opened from the
lists. It has no sidebar entry.

- **Label:** `nav.fieldWorkManagement`, which reads "Field Work
  Management" in English and "የመስክ ሥራ አስተዳደር" in Amharic. Child labels
  use `nav.fieldWork*`. Nothing is hard-coded in JSX.
- **Icon:** `MapPinned`, the Lucide glyph, added to the house icon set
  `Components/Icons.tsx`. No new icon library.
- **Position:** its own category, directly after the *People & organization*
  section, at the same level as People, Operations and Governance. In
  `sections` it is the standalone entry `{ keys: ['fieldWork'] }`: a section
  with no label of its own takes its heading from the group's label, so
  "Field Work Management" appears once, as the category heading, with its
  pages listed directly beneath it. The rest of the sidebar was not reordered.
- **Visibility:** each child is filtered by its permission. A category whose
  children are all hidden is not rendered, so it never appears empty and
  never links to a 403.
- **Active state:** `activeRoute()` picks the item with the longest matching
  route-name prefix. Any `field-work.*` route highlights its item:
  `field-work.requests.show` maps to Field Work Requests, and the other
  routes map to themselves. The pages are always listed, so the category is
  always expanded. Breadcrumbs (`navLocation()`) use the same rule: *Field
  Work Management → Dashboard*, *→ Pending Approvals*, and so on.
- **Collapsed sidebar and mobile drawer:** in the collapsed rail the category
  becomes one `MapPinned` icon that opens the usual flyout of its pages. The
  mobile drawer shows the expanded layout.

Inside the module, a tab row (`Components/fieldWork/ManagementNav.tsx`)
offers the same pages, built from server abilities.

### My Portal: `My Field Work`

| Item | Route | Permission |
|---|---|---|
| My Field Work | `employee.field-work.index` | `field_work.view_own` |
| New Field Work | `employee.field-work.create` | `field_work.create_own` |
| My Field Work History | `employee.field-work.history` | `field_work.view_own` |

The group is labelled `nav.groupMyFieldWork` ("Field Work" / "የመስክ ሥራ"),
so breadcrumbs read *Field Work → My Field Work*. It sits in the portal's
*Work* section, next to Daily Activity. Staff
who also have an employee record see the same three links in their
*Self-service* group, which is the same list rendered again rather than a
duplicate definition. **No employee self-service link appears in the admin
category, and no management link appears in My Portal.**

Group key `fieldWork`, label key `nav.fieldWorkManagement` and the approvals
route `field-work.pending` (`/field-work/pending-approval`) are the names the
earlier implementation on `main` already used. They were kept, so nothing
that referenced them breaks.

## Duplicate check

Every navigation source was searched: the sidebar, the portal sections, the
breadcrumbs and the module tab rows. There is exactly one `fieldWork` group,
one definition of each route, and no "Official Duty" or second Field Work
category. The test *the admin sidebar has exactly one correctly spelled Field
Work Management category…* enforces this and rejects the misspellings "Fild
Work", "Field Work Amangment", "Field Work Managment" and "Fieldwork
Management".

## Known gap

`HandleInertiaRequests::resolveIsEmployeeUser()` puts every account that has
an employee record but lacks `dashboard.view` into **portal mode**, and
portal mode renders only My Portal. Line managers whose only roles are
*Daily Activity Reviewer* or *Performance Manager* therefore never see admin
categories. This is not new: the Daily Activity review queue and EPMS have
the same gap. Those supervisors still reach Field Work approvals through the
`approval_required` notification, which links straight to the request. The
fix belongs to the navigation model as a whole: either give line-manager
roles `dashboard.view`, or add a portal-mode "My Team" section. That is
NEEDS_DECISION #10 in [field-work-management.md](field-work-management.md).
