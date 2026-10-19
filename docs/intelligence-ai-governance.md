# Addis One Intelligence — AI gateway, root-cause assistant and governance

AI in this module is **advisory**. Deterministic code produces every signal,
figure, incident and alert first; a language model may then explain,
summarise or suggest. Its output is untrusted text with provenance, shown to
a person who decides. Nothing it returns is executed.

## Gateway (`App\Services\Intelligence\AI\AiGateway`, bound to `LLMGateway`)

Pipeline for every call, in order:

1. **Authorize** — `ai_enabled` on, a provider selected and its credentials
   present in the environment (`INTELLIGENCE_AI_*`, `INTELLIGENCE_ANTHROPIC_*`
   in `config/intelligence.php`). Secrets never reach the database, the
   settings page, Inertia props or logs.
2. **Classify** — the request names its data classes
   (`aggregates`, `structured_signals`, `redacted_logs`,
   `knowledge_documents`). A class the settings do not allow outbound, or the
   prompt template does not declare, is **refused** before anything is built.
3. **Minimize + redact** — `Redactor::text()` masks keys, bearer tokens,
   `sk_…`-style secrets, e-mails, phone numbers and long digit identifiers;
   `Redactor::truncate()` caps at `ai_max_input_chars`.
4. **Send** — `PromptRegistry::render()` substitutes variables and fences the
   input between `<<<DATA … END DATA>>>` with the instruction that it is data,
   not instructions. Providers: OpenAI-compatible `/v1/chat/completions`,
   Anthropic `/v1/messages`; timeout from settings.
5. **Validate** — `AiResponseValidator` extracts JSON and checks it against the
   template schema (types, enums, lengths, list sizes); anything else is
   `INVALID` and discarded. Free text is cleaned of markup.
6. **Record** — every attempt, successful or not, becomes an
   `intelligence_ai_runs` row (feature, prompt key + version, provider, model,
   status, user, subject, input summary — never the input —, output, error,
   sizes, duration). A successful run is also audited
   (`IntelligenceAiRunRecorded`).

Controls: circuit breaker (`breaker_failures` consecutive failures open it for
`breaker_open_seconds`), daily request cap (`daily_request_cap`), max output
tokens per template and globally. `status()` exposes all of this without
secrets; `available()` is what pages use to show or hide AI actions.

## Prompt registry (`PromptRegistry`)

| Key | Version | Feature | Data classes |
| --- | --- | --- | --- |
| `incident_root_cause` | v1 | root_cause_assistant | structured_signals, redacted_logs |
| `insight_narrative` | v1 | insight_narrative | aggregates |
| `knowledge_answer` | v1 | knowledge_assistant | knowledge_documents |

A changed prompt gets a new version; runs, incident assessments and
recommendations carry the version they were produced with, so an old answer
is never mistaken for a new prompt's. The system rules forbid naming or
blaming people, inferring guilt from counts, inventing figures and giving
commands.

## Root-cause assistant (`RootCauseAssistant`, `ExplainIncident` job)

Trigger: **Ask AI for a root-cause assessment** on an incident, for users with
`intelligence.diagnostics.view`, only while the gateway is available
(`POST intelligence/incidents/{incident}/explain`, throttled). The request
records an `ai_requested` event and queues `ExplainIncident` on the `long`
queue; the page shows the result on the next load.

Evidence bundle sent (`bundle()`, testable): the incident's rule, component,
endpoint, severity, occurrences, timestamps and measured evidence; up to five
related error signatures (class, normalized message, route group, route name,
occurrences, **file and line of the first application frame**); the deployment
within the window (release, minutes before detection); the component states.
Never: stack frames, request payloads, source code, user identifiers.

On success the incident stores `probable_cause` (symptom + top cause),
`cause_confidence`, the structured assessment (`recommendation.ai_assessment`
with model and prompt version), `ai_run_id`; each recommended next check
becomes an `intelligence_recommendations` row with `generated_by = ai`,
category `verification`, risk `safe_check`, `review_status = pending`; an
`ai_explained` event and an `IntelligenceIncidentExplained` audit entry are
written. On failure an `ai_explain_failed` event records the reason; the
incident is unchanged. The page renders the assessment only for the
diagnostics grant, marked as suggestions, with "suggested areas to inspect"
never "responsible".

## Knowledge assistant

See `docs/intelligence-knowledge.md`: permission, classification and scope
filters run in SQL **before** retrieval; the model sees only passages the
asker may read; answers cite passages; extractive fallback when AI is off.

## Governance page (`intelligence/governance`, `intelligence.governance.view`)

Read-only: gateway status (enabled, provider, model name, credentials
present, breaker, calls today / cap, outbound classes — no secrets), feature
switches and retention, the prompt catalog, the data inventory (samples,
latest sample, active incidents and alerts, pending recommendations, AI runs,
knowledge documents and questions), reviewer verdicts per generator (rule vs
AI) — the feedback loop of brief §51 —, forecast quality (MAE / MAPE per
metric) and the paginated AI run log. Switches live in System Settings ›
Intelligence (`system-settings.manageIntelligence`).

## Feedback loop and self-improvement boundary

Reviewers rate each recommendation (`useful`, `not_useful`, `accepted`,
`rejected`, `incorrect_diagnosis`). The counts per generator are visible on
Governance and are the input for a human to change a rule, a threshold or a
prompt. Nothing retrains, rewrites prompts or changes thresholds on its own
(brief §104).

## Costs and limits

Per-call input cap, per-template output cap, daily cap, breaker, 1 try per
job, timeout. All in `config/intelligence.php` and System Settings; none in
code constants that need a deploy to change.

## Tests

`tests/Unit/Intelligence/AiCoreTest.php` (redaction, prompt rendering,
validation), `tests/Feature/Intelligence/AiGatewayTest.php` (off by default,
class refusal, validation, breaker, cap, run records),
`tests/Feature/Intelligence/RootCauseTest.php` (grant and availability gates,
bundle contents and redaction, stored assessment, AI recommendations, events,
audit, page visibility), `tests/Feature/Intelligence/KnowledgePagesTest.php`,
`tests/Feature/Intelligence/GovernanceAndForecastTest.php`.
