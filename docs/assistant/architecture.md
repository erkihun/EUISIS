# Addis One Assistant — architecture

The Addis One Assistant (Amharic: *የAddis One ረዳት*) is the conversational
front door of the platform: one floating trigger at the bottom-right of every
authenticated page, one panel, one backend. It explains pages, answers from
the approved knowledge base, reads business and system intelligence for the
people allowed to see it, troubleshoots, and opens support tickets — always
through the same authorization and scope the rest of Addis One enforces.

It is **not** a second AI stack. Everything intelligent sits in Addis One
Intelligence (`docs/intelligence-architecture.md`): the AI gateway, the
prompt registry, the knowledge retriever, the business analyses, component
health and incidents. The assistant adds routing, context, conversation
persistence, the support-ticket domain and the UI.

## What the repository had (inspected 2026-10-19)

- Layouts: `AuthenticatedLayout` (admin **and** MyPortal — the employee portal
  at `/my-portal` renders inside it with its own sidebar groups),
  `GuestLayout` (auth pages), `PublicLayout` / `FeedbackAppLayout` (anonymous
  public site and QR feedback), provider portals.
- Shared Inertia props (`HandleInertiaRequests`): `auth`, `locale`,
  `settings` (appearance), `branding`, `features`, `flash`.
- Addis One Intelligence: `LLMGateway` → `AiGateway` (redaction,
  classification, validation, circuit breaker, daily cap, `intelligence_ai_runs`),
  `PromptRegistry` (versioned), `KnowledgeRetriever` (permission /
  classification / scope filters **in SQL before retrieval**),
  `KnowledgeAssistant::ask()` (AI or extractive, cited), business
  `IntelligenceService::area()` payloads (KPIs + insights within
  `SituationScope`), `ComponentHealth`, `IntelligenceIncident`, `RouteGroups`,
  `RunbookCatalog`, `Redactor`, `UserAgent::summarize()`.
- Error pages carry a safe `error_id` (`ERR-…`); `ErrorLoggingService`
  fingerprints exceptions for incidents.
- No support/help-desk/ticket domain existed. No frontend unit-test runner
  (`typecheck` + `build` + `i18n:audit` only); browser checks are done with
  the Playwright MCP / Chrome DevTools against the local server.
- Rate limiting: named `RateLimiter::for()` limiters in `AppServiceProvider`
  on the configured cache store (Redis in production when configured).

## Structure

```
Browser (AuthenticatedLayout)
  └─ GlobalAIAssistant (trigger, lazy-loaded panel)      resources/js/Components/ai/
        │  JSON over the web session (CSRF, auth, admin.access, throttle:assistant)
        ▼
AssistantController (thin)                               app/Http/Controllers/Web/Assistant/
  ├─ AssistantContextResolver   user, grants, scope, page registry, capabilities, status
  ├─ AssistantConversations     own conversations, window, titles, retention
  ├─ AssistantIntentRouter      quick action or deterministic intent → mode
  ├─ AssistantToolRegistry      allow-listed tools with permission + schema
  │     page_help · knowledge_search · business_metric_query · system_health
  │     incident_lookup · support_ticket_draft · safe_navigation
  ├─ AssistantAnswerComposer    tool results → blocks (+ optional AI narrative via LLMGateway)
  └─ SupportTicketService       explicit ticket creation (docs/assistant/support-helpdesk.md)
```

Modes (internal, inferred, never chosen by the user): `HELP`, `KNOWLEDGE`,
`BUSINESS_ANALYSIS`, `SYSTEM_DIAGNOSTICS`, `SUPPORT`.

## Canonical rules

- **Permission = WHAT, scope = WHERE.** Every tool declares the grant it
  needs; the resolver computes capabilities server-side from the user's
  grants and `OrganizationScopeService`; the browser only renders what the
  server returned. Text in a message never changes capabilities.
- **AI is advisory and optional.** Deterministic tools produce every figure,
  status, incident and citation. When the gateway is available the composer
  may ask it for a short narrative over the tool results (prompt
  `assistant_answer`); when it is not, the same blocks render with
  deterministic text. The application never depends on the provider.
- **No scraping.** The browser sends route name, path, module key, locale and
  — when a page declares one — a record type and id. Never the DOM, form
  values, hidden props or cookies.
- **No autonomous action.** Tools read, explain, recommend and navigate.
  Tickets need an explicit click. No HR/legal record changes, no shell, no
  SQL, no restarts.
- **Untrusted text everywhere.** User messages, documents, tool output and
  model output are data. The UI renders text nodes and allow-listed blocks;
  links come only from server-generated `href`s.

## Decisions taken (defaults, documented so they can be changed)

| # | Decision | Why |
| --- | --- | --- |
| A-1 | One mount point: `AuthenticatedLayout`. Guest, public and provider layouts do not render the assistant (`public_enabled` exists, default off, and no public tool set is built). | §58–59: nothing internal on anonymous or auth pages. |
| A-2 | Using the assistant needs no new grant beyond an authenticated `admin.access` session; every capability maps to existing grants (knowledge: `intelligence.knowledge.view`; analytics: a business Intelligence grant; diagnostics: `intelligence.system-health.view`; incidents: `intelligence.incidents.view`). | One assistant, different authorized tools (§16). Role grants stay a policy matter. |
| A-3 | Page context comes from `AssistantPageRegistry`: route-name prefixes → module key, help text keys (`lang/*/assistant.php`), knowledge document slugs, record types. Unknown routes fall back to general help. | §126–128: no route strings scattered, no crash on unknown pages. |
| A-4 | Business questions map to **approved Intelligence areas and KPI keys**; the tool runs `IntelligenceService::area()` inside the user's `SituationScope` and returns a compact Top-N of KPIs and insights with a deep link. No model-generated SQL exists anywhere. | §30–31, §120–125. |
| A-5 | System status and incidents are read from `ComponentHealth` and `intelligence_incidents`; absent telemetry is UNKNOWN; a page correlation needs an active incident on the page's route group, never the name alone. | §28–29, §78–80, §110. |
| A-6 | Conversations persist server-side (`assistant_conversations`, `assistant_messages`) per user; the AI sees at most the last `history_window` turns, redacted and truncated; retention defaults to 30 days (`NEEDS_POLICY_DECISION_ASSISTANT_RETENTION`). Nothing is kept in `localStorage` except the open/closed state. | §20–23, §53–54, §72. |
| A-7 | A minimal support domain is created (`support_tickets`): reference `TKT-YYMM-NNNN`, reporter, organization, module, route, category, priority (user-chosen; raised to high only by a linked critical/high incident), safe context, status, assigned team (configurable category routing), audit. Reporters see their own tickets; `support-tickets.view-all` / `support-tickets.manage` see and work all. | §33–37, §88–91: nothing existed; no ITSM. |
| A-8 | Rate limit: named limiter `assistant` (per user, per minute from settings) plus a daily cap; 429 returns a typed error the panel explains. | §52, §107. |
| A-9 | Settings group `assistant` under System Settings › Intelligence card family, same `system-settings.manageIntelligence` grant. | §92–93, §156–157. |
| A-10 | No streaming, no attachments, no screenshots in phase 1: the gateway is request/response and no secure assistant upload path is justified yet. Responses are bounded (Top-N, summaries, links). | §39–41, §133. |
| A-11 | Operational analytics derive from `assistant_messages` columns (mode, status, ai_used, duration_ms) and are shown on the Intelligence Governance page; message text is not analysed. | §75. |

## NEEDS_* registry

| Code | Where | Needs |
| --- | --- | --- |
| NEEDS_POLICY_DECISION_ASSISTANT_RETENTION | conversations | approved retention (default 30 days until decided) |
| NEEDS_POLICY_DECISION_SUPPORT_ROUTING | tickets | which team owns which category (default: category → team name in settings) |
| NEEDS_POLICY_DECISION_PUBLIC_ASSISTANT | public pages | whether a safe public help subset should exist (off) |
| NEEDS_DATA_HELP_COVERAGE | page help | modules without approved guidance in the knowledge base answer from the registry's short purpose/steps text only |
| NEEDS_DEPLOYMENT_CONFIGURATION | rate limits | Redis cache store in production for distributed limits; AI provider env for narratives |

Related: `security.md`, `privacy.md`, `context-and-tools.md`, `knowledge.md`,
`diagnostics.md`, `support-helpdesk.md`, `uat.md`, `production-readiness.md`.
