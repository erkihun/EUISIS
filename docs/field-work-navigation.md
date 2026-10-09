# Field Work navigation and route matrix

## Implemented navigation

Employee self-service is intentionally separate from administration:

```text
My Portal
├── My Field Work                 employee.field-work.index       field_work.view_own
└── New Field Work                employee.field-work.create      field_work.create

Field Work Management
└── Pending Field Work Approval   field-work.pending               field_work.approve / return / reject
```

`Field Work Management` is the single administrative group. Its English and
Amharic labels are `Field Work Management` and `የመስክ ሥራ አስተዳደር`. The sidebar
filters child links before rendering a group, so a user with none of the three
review permissions does not see an empty group. The existing group open/active
state uses the current route and therefore keeps the category active for the
implemented pending-approval route in expanded, collapsed, and mobile layouts.

## Route and authorization matrix

| Surface | Route | Page | Server-side authorization |
| --- | --- | --- | --- |
| My Field Work | `employee.field-work.index` | `Employee/FieldWork/Index` | `field_work.view_own`; requester query is derived from the authenticated employee |
| New Field Work | `employee.field-work.create` | `Employee/FieldWork/Create` | `field_work.create`; employee and assignment are derived server-side |
| Submit | `employee.field-work.submit` | action only | request policy: owner + editable state + `field_work.submit` |
| Check in / out | `employee.field-work.check-in`, `employee.field-work.check-out` | action only | participant ownership and approved-state checks in the location service |
| Complete | `employee.field-work.complete` | action only | `field_work.complete`; owner or configured supervisor; actual return cannot be in the future |
| Pending Field Work Approval | `field-work.pending` | `FieldWork/Pending` | any review action permission, then configured-supervisor resolution per request; buttons are independently filtered |
| Approve / return / reject | `field-work.approve`, `field-work.return`, `field-work.reject` | action only | distinct action permission, request policy, and transactional supervisor re-check |

## Not implemented — no navigation links

The following requested management surfaces have no route/page in the current
application and deliberately have no sidebar item: Dashboard, Field Work
Requests, Team Field Work, Team Availability, Calendar, Overdue / Unclosed,
Reports, and Field Work Types. Adding them as links would create dead routes or
403 destinations. Their eventual delivery must add a scoped route, a
permission-aware child item, localized page title/breadcrumb, bounded queries,
and feature coverage together.
