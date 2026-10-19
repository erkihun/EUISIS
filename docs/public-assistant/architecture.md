# Addis One Help — public-site assistant architecture

"Addis One Help" (Amharic: *የAddis One እርዳታ*) is the floating help assistant of
the anonymous public site: the home page, services, announcements, support,
the ID checker and the QR feedback flows. It is a **separate capability
boundary** from the authenticated Addis One Assistant: same AI gateway, same
safe text renderer and UI primitives, **nothing else shared** — its own
context resolver, tool registry, knowledge filter, conversation policy and
rate limiter, and no access to any authenticated service.

## What the repository had (inspected 2026-10-19)

- Public pages: `Welcome` (home), `Public/Services` + `ServiceShow`
  (`PublicService`, published only), `Public/Announcements`, `Public/Support`
  (sections + `PublicFaq` from Public Site Management), `Public/Verify`,
  `Public/IdChecker` (card lookup + OTP), `Public/ServiceFeedback` (employee
  QR `/service-feedback/{token}` and organization QR `/feedback/{token}`:
  landing, feedback / tip-off ("suggestion") / complaint forms, receipt with
  reference after submission). Layouts: `PublicLayout` (site pages) and
  `FeedbackAppLayout` (QR flows with a bottom dock).
- No public tracking page exists: a submission shows its reference on the
  receipt once; there is no status lookup for citizens
  (`NEEDS_POLICY_DECISION_PUBLIC_TRACKING`).
- No public support/ticket flow: the Support page shows the configured
  `general.support_email` / `general.support_phone` and FAQs.
- Public content management exists (`PublicSiteContent`: sections, FAQs,
  services, announcements, published/draft states) — the assistant reads it
  through the same presenters, never the drafts.
- Knowledge base: Intelligence documents carry a `classification`
  (`public|internal|confidential|restricted`) and a status; a public filter
  is a classification + status + no-organization predicate in SQL.
- Public middleware: `public.site` (site switch), `qr.private` (no-store
  headers), IP-based named limiters (`service-feedback-submit`, …), CSRF via
  the XSRF cookie for guests.
- The authenticated assistant (`docs/assistant/*`): gateway, prompt registry,
  blocks contract, strict renderer.

## Structure

```
PublicLayout / FeedbackAppLayout
  └─ PublicAssistant (trigger, lazy panel)          resources/js/Components/public-ai/
        │ JSON, guest session, throttle:public-assistant
        ▼
PublicAssistantController (thin)                    app/Http/Controllers/Public/
  ├─ PublicAssistantContextResolver   page kind from the route registry, QR target from the server resolver
  ├─ PublicAssistantToolRegistry      the only tools that exist for the public boundary
  │     public_page_help · public_knowledge_search · public_service_information
  │     public_organization_information · public_feedback_help · public_tracking_help
  │     public_safe_navigation · public_status · public_support_handoff
  ├─ PublicKnowledgeService           public-classified documents + published FAQs, filtered in SQL first
  ├─ PublicAssistantConversation      last N turns in the guest session (opaque, ephemeral)
  └─ PublicAssistantAnswerComposer    blocks + optional AI narrative over public data only
```

## Decisions

| # | Decision | Why |
| --- | --- | --- |
| P-1 | Separate services under `App\Services\PublicAssistant`; the registry is a fixed PHP array of public tools; no class from `App\Services\Assistant` (tools, resolver, registry) is referenced. | §2, §57–59: defense in depth by construction. |
| P-2 | Context = route registry (`PublicAssistantPageRegistry`) + the QR token resolved by `PublicFeedbackTargetResolver` (the same resolver the pages use); chat text never selects a target. | §11–12, §60–62. |
| P-3 | Knowledge = documents with `classification = public`, `status = approved`, no organization, plus published FAQs; the predicate runs before retrieval; drafts and every other classification never enter the query. | §7–9, §82. |
| P-4 | Conversation lives in the guest session (`public_assistant.turns`, bounded to `public_history_turns`), no table, no cookie beyond the session, no localStorage history. Retention = session lifetime (`NEEDS_POLICY_DECISION_PUBLIC_CHAT_RETENTION`). | §37–39, §103–105. |
| P-5 | Named limiter `public-assistant`: per minute and per day by address, from settings; typed 429. | §40–41, §75. |
| P-6 | Public status (`public_status_enabled`, default off) maps `ComponentHealth` + active incidents to Available / Degraded / Temporarily unavailable with no evidence. | §33–34. |
| P-7 | Support handoff = Support page + configured public contacts; no public ticket creation (`NEEDS_POLICY_DECISION_PUBLIC_SUPPORT_REQUEST`). | §30–31, §88–89. |
| P-8 | Tracking help explains the receipt and reference; there is no status lookup, so the assistant says so and never answers a reference from chat. | §17–19, §72. |
| P-9 | AI narrative uses prompt `public_assistant_answer` with data class `public_content` only; when the class is not allowed outbound or the gateway is unavailable, deterministic blocks render alone. | §35, §76–77. |
| P-10 | Employee QR context carries only what the page already shows (display name, position title); the ID checker context carries nothing about the card. No employee lookup tool exists. | §13, §63–65. |

## NEEDS_* registry

| Code | Needs |
| --- | --- |
| NEEDS_POLICY_DECISION_PUBLIC_CHAT_RETENTION | whether public turns may outlive the session (today: no) |
| NEEDS_POLICY_DECISION_PUBLIC_TRACKING | a citizen-facing status lookup (today: receipt only) |
| NEEDS_POLICY_DECISION_PUBLIC_SUPPORT_REQUEST | a public ticket flow (today: contacts + Support page) |
| NEEDS_POLICY_DECISION_PUBLIC_STATUS | enabling the public service-status message |
| NEEDS_LANGUAGE_REVIEW | formal review of the Amharic help texts |
| NEEDS_DEPLOYMENT_CONFIGURATION | Redis cache store for distributed limits; `public_content` allowed outbound if AI narratives are wanted |

Related: `security.md`, `privacy.md`, `public-capabilities.md`, `knowledge.md`,
`uat.md`, `production-readiness.md`.
