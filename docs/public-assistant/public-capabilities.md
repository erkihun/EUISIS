# Addis One Help — capabilities, tools and the browser contract

## Capability matrix (actual system capabilities)

| Capability | Public visitor | Authenticated employee (My Portal) | Admin | Technical admin |
| --- | --- | --- | --- | --- |
| Public FAQ / published guides | YES | YES (via public site) | YES | YES |
| Public feedback / tip-off / complaint help | YES | YES | YES | YES |
| Published service information | YES | YES | YES | YES |
| Organization public name, public phone, published services (from a scanned QR) | YES (scanned target only) | n/a | internal pages | internal pages |
| Employee public verification help | YES (process only) | — | — | — |
| Own submission tracking | RECEIPT ONLY (no status lookup exists) | — | internal handling | — |
| Public service status message | ONLY IF `public_status_enabled` | — | — | detailed status |
| Page help for authenticated modules | NO | YES | YES | YES |
| Employee private data | NO | own record | per grant + scope | NO |
| Employee directory / search | NO | NO | per grant + scope | NO |
| Workforce / business analytics | NO | NO | per Intelligence grant + scope | NO |
| Situation Room | NO | NO | per grant | NO |
| Internal complaint lists, handlers, notes | NO | NO | per grant + scope | NO |
| System diagnostics, incidents, logs, queues | NO | NO | NO | per technical grant |
| Support tickets (internal) | NO | own tickets | per grant | per grant |
| AI run / audit logs | NO | NO | governance grant | governance grant |

## Browser → server

Prefix `/public-assistant`, names `public-assistant.*`, middleware `web`,
`public.site`, `throttle:public-assistant`. No authentication; CSRF through
the XSRF cookie like every public form.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/public-assistant/bootstrap?route=&path=&token=` | capabilities, page context, quick actions, welcome, the session's turns |
| POST | `/public-assistant/messages` | `{message, action?, page:{route,path,token?}}` → `{message, reply, status}` |
| POST | `/public-assistant/reset` | forget the session's turns |

`token` is the QR token from the current URL (`/service-feedback/{token}` or
`/feedback/{token}`); the server resolves it with the same resolver the page
uses and ignores it anywhere else. Nothing else is sent: no DOM, no form
values, no card numbers, no OTP.

Errors: `{error: 'assistant_disabled'|'rate_limited'|'validation'|'unavailable', message}` with 403/429/422/503.

### Bootstrap

```json
{
  "enabled": true,
  "status": "ready" | "limited",
  "capabilities": {"knowledge": true, "services": true, "support": true, "status": false},
  "page": {"kind": "organization_qr", "label": "Organization feedback", "target": {"type": "organization", "name": "Bole Sub-city"} | null, "form_type": null | "feedback"|"suggestion"|"complaint"},
  "quick_actions": [{"key": "how_to_use", "label": "How to use this page"}, ...],
  "welcome": "Hello, I can help you use Addis One public services.",
  "disclaimer": "…",
  "limits": {"max_message_chars": 1000},
  "messages": [Message]
}
```

Quick action keys: `how_to_use`, `submit_feedback`, `submit_suggestion`,
`submit_complaint`, `track_submission`, `verify_employee`,
`organization_services`, `contact`, `public_help`. Only keys valid for the
page kind and capabilities are returned.

Page kinds: `home`, `services`, `service`, `announcements`, `support`,
`verify`, `id_checker`, `employee_qr`, `organization_qr`, `feedback_form`,
`unknown`.

### Message

```json
{"id": "…", "role": "user"|"assistant", "text": "…", "blocks": [Block], "created_at": "ISO", "meta": {"mode": "HELP", "ai": false}}
```

Blocks (the panel ignores unknown types; `href` must start with `/`, `tel:`
or `mailto:` and comes only from the server):

| type | fields |
| --- | --- |
| `text` | `text` (strict subset: paragraphs, `- ` bullets, `**bold**`) |
| `bullets` / `steps` | `items[]` |
| `sources` | `items[{title, href?}]` — public titles only |
| `actions` | `items[{key, label, href}]` |
| `contact` | `items[{kind: phone\|email\|link, label, href}]` |
| `status` | `state: available\|degraded\|unavailable, text` |
| `notice` | `tone: info\|warning, text` |
| `disclaimer` | `text` |

## Tool registry (`PublicAssistantToolRegistry`)

| Tool | public | input | output | rate class | page contexts |
| --- | --- | --- | --- | --- | --- |
| `public_page_help` | yes | page kind | purpose, steps, notes from `lang/*/public_assistant.php` | normal | all |
| `public_knowledge_search` | yes | question ≤ 1000 | public document passages + FAQ matches with titles | normal | all |
| `public_service_information` | yes | question | published services matching the question (name, short description, requirements, steps, link) | normal | all |
| `public_organization_information` | yes | resolved QR token | public name, public phone, published services of the scanned organization | normal | organization_qr, feedback_form |
| `public_feedback_help` | yes | page kind + form type | what feedback / tip-off / complaint are, the boundary with discipline/grievance, links to the forms of the scanned target | normal | all |
| `public_tracking_help` | yes | — | how the receipt and reference work; no lookup exists | normal | all |
| `public_safe_navigation` | yes | — | allow-listed public routes | normal | all |
| `public_status` | only with `public_status_enabled` | — | Available / Degraded / Unavailable | normal | all |
| `public_support_handoff` | yes | — | Support page + configured public contacts | normal | all |

There is no employee lookup, no submission lookup, no analytics tool, no
diagnostics tool, no ticket tool. The model never selects tools; a
deterministic router does. An unknown `action` key is a validation error.
