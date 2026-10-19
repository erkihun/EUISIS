# Addis One Assistant — privacy and data minimization

## What the browser sends

`route` (Ziggy route name), `path`, `locale` (from the session, server
side), and — only when a page renders `<AssistantPageContext type id />` —
a record type and id. Never form values, DOM, props, cookies, headers,
screenshots, keystrokes.

## What is stored

| Table | Content | Not stored |
| --- | --- | --- |
| `assistant_conversations` | user id, module, route name, a short title from the first question (redacted, digit runs ≥4 masked), timestamps | organization ids from messages, record ids |
| `assistant_messages` | role, the text shown (redacted, ≤ 8,000 chars), allow-listed blocks, mode, tool keys, status, `ai_used`, `ai_run_id`, duration, feedback | model reasoning, raw tool output, provider payloads |
| `support_tickets.context` | allow-listed keys only (`route, path, module, locale, browser, os, device, error_reference, incident_reference, timestamp, viewport`), strings ≤ 200 chars, redacted | cookies, tokens, headers, HTML, form payloads |
| `intelligence_ai_runs` | feature, prompt + version, provider, model, status, user, input **summary** (sizes), output, timing | the input text |

`Redactor::text()` runs on every stored text and on every AI input: API-key
and token patterns, bearer headers, e-mails, phone numbers and long digit
identifiers are masked.

## What reaches the AI provider (only when AI is on and the class is allowed)

- Business questions: `aggregates` — KPI labels/values and insight
  titles/summaries of the asker's scope.
- Diagnostics: `structured_signals` — component states, incident titles,
  severities, statuses, components, timestamps; never frames or logs.
- Knowledge: `knowledge_documents` — only passages the asker may read
  (filtered in SQL before retrieval).
- The last `history_window` turns (text, redacted, 400 chars each) and the
  question (1,000 chars).

Never: record fields beyond label/status/position/organization, national
IDs, contact details, DOB, salary, disciplinary or grievance narrative,
medical data, secrets. The data classes a request may carry are checked
against System Settings › Intelligence › "Data allowed towards the provider";
a request outside them is refused before anything is built.

## Browser storage

`localStorage` holds only `assistant.open`. Conversation state lives in
memory for the tab; reloads re-fetch from the server.

## Retention

`assistant:prune` (daily 03:50) deletes conversations whose
`last_activity_at` is older than System Settings › Assistant › retention
(default **30 days** — `NEEDS_POLICY_DECISION_ASSISTANT_RETENTION`). Tickets
and their events are kept (support history). AI runs follow the
Intelligence retention. Deleting a user cascades their conversations.

## Feedback

"Helpful / Not helpful" is a column on the message; it is reviewed on the
Governance page and never used to retrain or alter prompts automatically.
