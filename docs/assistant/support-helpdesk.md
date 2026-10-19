# Addis One Assistant — support help desk

Architecture decision A-7 (`architecture.md`): a minimal support domain so a
person who is stuck can ask for help without leaving Addis One, and the
people who answer have the facts they need and nothing they must not see.
There is no ITSM integration; this is the whole help desk.

## What exists

| Piece | Where |
| --- | --- |
| Tables | `support_tickets`, `support_ticket_events` (`2026_10_20_100000_create_support_tickets_table.php`) |
| Grants | `support-tickets.view-all`, `support-tickets.manage` (catalog group `support`, category Dashboard & Reports; both given to Super Admin and System Admin by `2026_10_20_101000_register_support_permissions.php`) |
| Models | `App\Models\SupportTicket` (CATEGORIES, PRIORITIES, STATUSES, ACTIVE_STATUSES; `reporter()`, `assignee()`, `events()`, `incident()`), `App\Models\SupportTicketEvent` |
| Policy | `App\Policies\SupportTicketPolicy` (auto-discovered): `view` = reporter or either grant; `update` = manage |
| Service | `App\Services\Support\SupportTicketService` — `create()`, `transition()`, `assign()`, `note()`, `teamFor()`, `sanitizeContext()` |
| Routing config | `config/support.php` (`default_team`, `routing` category → team) |
| Pages | `/support/tickets` (`support.tickets.index`, `support.tickets.store`), `/support/tickets/{ticket}` (`support.tickets.show`, `support.tickets.update`) — `routes/support.php`, middleware `auth, verified, force.password, admin.access`; writes throttled 30/min |
| UI | `resources/js/Pages/Support/Tickets/{Index,Show}.tsx`, sidebar item "Support Tickets" in the Self-service group (every signed-in admin-side user) |
| Strings | `resources/js/i18n/{en,am}/support.ts` (pages), `lang/{en,am}/support.php` (flash, labels) |
| Audit | `AuditEventType::SupportTicketCreated` on create, `SupportTicketUpdated` on every status, assignment or note change |

The assistant's own ticket endpoint (`POST /assistant/tickets`,
`context-and-tools.md`) calls the same `SupportTicketService::create()` with
the page context it resolved server-side and, when it has one, the
`error_reference`, the `incident_id` and the `conversation_id`.

## A ticket

| Field | Meaning |
| --- | --- |
| `reference` | `TKT-YYMM-NNNN`, sequential within the calendar month (same scheme as incidents `INC-…`) |
| `reporter_user_id`, `organization_id` | the signed-in reporter and their first assigned organization (`OrganizationScopeService::assignedOrganizationIds`), null for unrestricted accounts |
| `module`, `route_name`, `path` | where the reporter was; from the page form these are the store request's own route and path |
| `category` | user-chosen: `access`, `error`, `data`, `how_to`, `performance`, `other` |
| `priority` | user-chosen `low` / `normal` / `high`. The **only** automatic rule: a linked incident whose severity is `critical` or `high` makes the ticket `high`. Nothing else (keywords, category, role) changes it |
| `title` | optional; otherwise the first 120 characters of the description |
| `description` | the reporter's own words, ≤ 4000 characters, passed through `Redactor::text` |
| `context` | the safe diagnostic context (below) |
| `error_reference` | kept only when it matches `ERR-[A-Z0-9]{8,16}`; anything else is dropped |
| `incident_id`, `conversation_id` | links; an unknown incident id is dropped, the conversation id must be a UUID |
| `status` | `open` → `in_progress` → `resolved` → `closed` (any order is allowed; reopening clears `resolved_at` / `closed_at`) |
| `assigned_team`, `assigned_user_id` | team by category routing at creation; a manager may change both |

Every change writes one `support_ticket_events` row (`created`,
`status_changed`, `assigned`, `note`) with the actor and an optional note,
shown as the timeline on the ticket page. Notes are visible to the reporter.

## Safe context — what is never stored

`SupportTicketService::sanitizeContext()` is an allow-list:

`route`, `path`, `module`, `locale`, `browser`, `os`, `device`,
`error_reference`, `incident_reference`, `timestamp`, `viewport`.

- Any other key is dropped — cookies, `authorization`, tokens, headers, HTML,
  form payloads, record contents cannot get in because no key carries them.
- Every value is a string, tags stripped, passed through
  `App\Services\Intelligence\AI\Redactor::text` (bearer tokens, keys,
  e-mail, phone, ids, `password=` pairs) and cut at 200 characters.
- `browser`, `os`, `device` always come from `App\Support\UserAgent::summarize`
  on the request's User-Agent, never from the caller.
- Nothing is read from the page beyond what `context-and-tools.md` allows
  (`route`, `path`, declared record type/id); the support ticket keeps only
  the route and path, never the record.

## Routing — NEEDS_POLICY_DECISION_SUPPORT_ROUTING

Which team owns which category has not been decided. Until it is,
`config/support.php` routes every category to `default_team`
(`Service Desk`, env `SUPPORT_DEFAULT_TEAM`); per-category overrides are
`SUPPORT_TEAM_ACCESS`, `SUPPORT_TEAM_ERROR`, `SUPPORT_TEAM_DATA`,
`SUPPORT_TEAM_HOW_TO`, `SUPPORT_TEAM_PERFORMANCE`, `SUPPORT_TEAM_OTHER`. A
team is a label on the ticket, not an account; people are assigned by the
manage grant on the ticket page.

## Visibility

| Who | Sees | May change |
| --- | --- | --- |
| Any signed-in admin-side user | their own tickets (list, detail, timeline) and may file one | nothing after filing |
| `support-tickets.view-all` | every ticket, with the reporter's name | nothing |
| `support-tickets.manage` | every ticket | status, assignee (people who hold the manage grant), team, notes |

Another reporter's ticket answers 403 on GET and PATCH. The list shows
active tickets by default (`status=all` or a single status to widen it) and
filters by category.

## Tests

`tests/Feature/Support/SupportTicketsTest.php`: reference format and
monthly sequence, category routing, `created` event and audit, context
allow-list and redaction, incident-raised priority (and only that),
reporter isolation (IDOR), the two grants, events and audit on transitions,
validation (422) for categories, statuses and descriptions.
