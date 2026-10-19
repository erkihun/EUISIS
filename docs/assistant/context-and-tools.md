# Addis One Assistant — context, tools and the browser contract

## Browser → server

All endpoints: `auth`, `force.password`, `admin.access`, CSRF (Inertia/axios
session), JSON in and out, `throttle:assistant` on message endpoints.
Prefix `/assistant`, route names `assistant.*`.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/assistant/bootstrap?route=&path=&record_type=&record_id=` | capabilities, status, page context, quick actions, welcome, latest active conversation |
| POST | `/assistant/messages` | `{conversation_id?, message, action?, page:{route,path,record_type?,record_id?}}` → user + assistant message |
| POST | `/assistant/conversations` | start a new conversation (archives the active one) |
| GET | `/assistant/conversations` | own conversations `[{id,title,last_activity_at}]` |
| GET | `/assistant/conversations/{id}` | own conversation with messages (404 for anyone else) |
| POST | `/assistant/messages/{id}/feedback` | `{value: helpful\|not_helpful}` on an assistant message of an own conversation |
| POST | `/assistant/tickets` | explicit support ticket: `{conversation_id?, description, category, priority?, page, error_reference?}` |

Page context sent by the browser (`page`): `route` (Ziggy current route
name), `path`, optional `record_type` + `record_id` **only when the page
component declares them** through `<AssistantPageContext type id />`.
Nothing else is read from the page.

Errors are typed: `{error: 'assistant_disabled'|'forbidden'|'rate_limited'|'ai_unavailable'|'validation'|'support_disabled'|'not_found', message}` with
the matching HTTP status (403, 429, 422, 404, 503). The panel shows a
specific sentence per code; 401/419 reload the page through Inertia's
normal handling.

## Bootstrap response

```json
{
  "enabled": true,
  "status": "ready" | "limited" | "unavailable",
  "ai": {"available": true},
  "capabilities": {"help": true, "knowledge": true, "analytics": false, "diagnostics": false, "incidents": false, "support": true},
  "page": {"route": "employees.index", "module": "employees", "module_label": "Employees", "help_available": true, "record": null},
  "quick_actions": [{"key": "explain_page", "label": "Explain this page"}, ...],
  "welcome": "Hello. How can I help you with Addis One?",
  "disclaimer": "AI-generated guidance may require verification.",
  "limits": {"max_message_chars": 2000},
  "conversation": {"id": "…", "title": "…", "messages": [Message]} | null
}
```

`status`: `ready` = assistant on and the AI gateway available; `limited` =
assistant on, AI off/unavailable (deterministic tools only); `unavailable`
= assistant off (the trigger is not rendered at all).

Quick action keys (only those the capabilities allow are returned):
`explain_page`, `how_to`, `find`, `ask_module`, `report_problem`,
`system_status`, `create_ticket`.

## Message

```json
{
  "id": "…", "role": "user" | "assistant", "text": "…", "blocks": [Block],
  "created_at": "ISO", "feedback": null | "helpful" | "not_helpful",
  "meta": {"mode": "HELP", "ai": false, "confidence": null | "high"|"medium"|"low", "tools": ["page_help"]}
}
```

Blocks are the only structured content; the panel ignores unknown types.

| type | fields | rendering |
| --- | --- | --- |
| `text` | `text` | paragraphs; `**bold**` and `- ` bullets only (strict subset renderer, no HTML) |
| `bullets` | `items[]` | list |
| `steps` | `items[]` | ordered list |
| `sources` | `items[{title}]` | "Source: …" line(s) — titles only, never paths |
| `evidence` | `title, rows[{label,value}], source?, updated_at?` | evidence card with "Last updated" |
| `status` | `state, components[{key,label,state}], updated_at?, reason?` | system status card |
| `incident` | `reference, title, severity, status, impact?, updated_at, href?` | incident card, "View incident" only when `href` present |
| `actions` | `items[{key,label,href}]` | buttons/links; `href` is server-generated and relative to the app |
| `ticket_offer` | `category, description, incident_reference?` | "Create support ticket" button — nothing is created until clicked |
| `ticket` | `reference, status, created_at, href` | confirmation card |
| `notice` | `tone: info\|warning\|error, text` | inline notice |
| `disclaimer` | `text` | muted footnote |
| `labels` | `kind: observed\|calculated\|hypothesis\|recommendation` + `text` | tagged line (§139) |

## Server-side context (`AssistantContextResolver`)

Resolved on every request, never trusted from the browser:

- user, locale, unrestricted flag, allowed organization ids (through
  `OrganizationScopeService`),
- capabilities: `help` (always), `knowledge` (`intelligence.knowledge.view`
  and knowledge enabled), `analytics` (any business Intelligence grant and
  analytics enabled), `diagnostics` (`intelligence.system-health.view` and
  diagnostics enabled), `incidents` (`intelligence.incidents.view`),
  `support` (support enabled),
- page: the registry entry for the route (module, help keys, document slugs,
  record types), the route group (`RouteGroups::of`), and — when the page
  declared a record — the record resolved **server-side** through the
  module's own authorization (an employee the user may not access resolves
  to nothing).

## Tool registry (`AssistantToolRegistry`)

| Tool | Needs | Input (validated) | Output (minimized) |
| --- | --- | --- | --- |
| `page_help` | — | module | purpose, steps, permissions needed, common mistakes (registry text), knowledge passages for the module's documents |
| `knowledge_search` | knowledge capability | question (≤1000 chars) | `KnowledgeAssistant::ask()` result: answer or passages, citations (titles) |
| `business_metric_query` | analytics capability + the area's grant | area, metric keys (allow-listed), period | KPI values, insights ≥ low, deep link to the area |
| `system_health` | diagnostics capability | — | component states, counts, active incident summary |
| `incident_lookup` | diagnostics capability; details need incidents capability | route group or reference | active incidents on the group / the referenced incident |
| `support_ticket_draft` | support capability | description, category | a `ticket_offer` block (no side effect) |
| `safe_navigation` | — | target key | allow-listed `{label, href}` from `route()` |

An intent that names a tool the user cannot use returns a `forbidden`
notice, never a degraded answer from a different scope. Unknown tool names
do not exist: the registry is a fixed PHP array, and the model never selects
tools — the router does.

## Intent router

1. A quick-action key sets the mode directly.
2. Otherwise deterministic matching on the message (English and Amharic
   keyword sets): status/health → `SYSTEM_DIAGNOSTICS`; "not working / error /
   failed / cannot / ERR-…" → `SUPPORT`; counts, trends, vacancies,
   complaints, staffing, attention, "what changed" → `BUSINESS_ANALYSIS`;
   "how do I / where / explain / what is" → `HELP`; else `KNOWLEDGE`.
3. A mode the capabilities do not allow degrades to `HELP`/`KNOWLEDGE` with a
   notice saying what is not available to this account — the question is
   never answered with data from another scope.

The model is consulted only after the tools ran, with their compact output
as DATA, to phrase a narrative; its JSON is validated (`answer`,
`confidence`) and shown as text. It cannot call tools, change the mode or
produce links.
