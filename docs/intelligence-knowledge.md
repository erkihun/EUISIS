# Addis One Intelligence — Knowledge Assistant

Permission-aware, retrieval-grounded question answering over **approved**
knowledge documents (brief §20–22, §61; decision D-8 in
`docs/intelligence-architecture.md`). A reader asks a question; the assistant
retrieves the passages that reader may open, and either composes a cited
answer from them through the AI gateway or, when AI is off, quotes them as
they are. Nothing is ever answered from outside the approved knowledge base,
and nothing a reader may not open is ever read for them.

## Pieces

| Piece | Where | What it does |
| --- | --- | --- |
| Documents, chunks, questions | `intelligence_knowledge_documents`, `_chunks`, `_questions` (migration `2026_10_19_121000`) | A document carries title, body, `source_type` (manual / repo_doc / upload), `classification`, `organization_id` (null = city-wide), `required_permission`, `language`, `status` (draft / approved / retired), `version`, `checksum`. Chunks are its retrieval units. A question row stores the redacted question, the answer, mode, grounding and citations. |
| `Chunker` | `app/Services/Intelligence/Knowledge/Chunker.php` | Splits a body by `#`/`##`/`###` headings, then each section into ~1,200-character windows cut on paragraph boundaries, each window starting with the last ~150 characters of the previous one. Front matter is dropped; headings inside code fences are text. Deterministic. |
| `KnowledgeDocumentService` | same folder | create / update / approve / retire / delete. A body change re-chunks, bumps the version and checksum, and **returns the document to draft** (the approved words were the old ones). Every change is audited as `IntelligenceKnowledgeDocumentChanged` with title, status, classification, version — never the body. |
| `KnowledgeRetriever` | same folder | `visibleDocuments(User)` — the approved documents this viewer may read, as one query. `search(User, question, limit)` — the best-matching chunks of those documents. |
| `KnowledgeAssistant` | same folder | `ask(User, question, locale)` — redact, retrieve, synthesise or quote, store, audit. |
| `KnowledgeController` | `app/Http/Controllers/Web/Intelligence/` | `/intelligence/knowledge` (question page, own recent questions), `POST …/ask`, and `…/knowledge/documents` (list, create, update, delete) under `intelligence.governance.manage`. |
| Pages | `resources/js/Pages/Intelligence/Knowledge/{Index,Documents}.tsx` | Question form, answer card, recent questions; document list with filters, inline create form, approve / retire / delete. |
| `intelligence:knowledge-import` | `app/Console/Commands/Intelligence/IntelligenceKnowledgeImport.php` | Imports `*.md` under a repository folder as `repo_doc` drafts. |

## Security filters — in the query, before retrieval

`KnowledgeRetriever::visibleDocuments()` is the one visibility rule; `search()`
constrains the chunk query to its ids (`whereIn document_id`), so a chunk of a
document the viewer may not open is never fetched, never scored, never sent to
a model and never "hidden" client-side.

| Filter | Rule |
| --- | --- |
| Status | `status = approved`. Drafts and retired documents are invisible to every reader. |
| Classification | `public`, `internal`: every knowledge reader. `confidential`: `intelligence.governance.view` (or `.manage`), **or** the document names a `required_permission` the viewer holds. `restricted`: `intelligence.governance.manage` only. |
| Required permission | `required_permission IS NULL OR required_permission IN (held)`, where `held` is computed in PHP from the small set of distinct values present on approved documents, each checked with `$user->can()`. |
| Organization scope | `organization_id IS NULL` (city-wide) or inside the viewer's scope through `OrganizationScopeService::applyOrganizationScope()`. Unrestricted viewers (Super Admin, City Admin, no scope rows) get no organization filter. |

The gateway adds its own gate: a `knowledge_documents` request is refused
unless the setting *Data Allowed Towards the Provider* includes that class.

## Retrieval and ranking

- **PostgreSQL:** the migration adds a stored generated column
  `search_vector tsvector = to_tsvector('simple', heading || ' ' || content)`
  with a GIN index; the query filters with `@@ plainto_tsquery('simple', ?)`
  and orders by `ts_rank`. The `simple` configuration stems nothing, so Amharic
  and English are treated alike.
- **MySQL / MariaDB / SQLite:** a LIKE score — how many of the question's
  distinct terms of three or more characters (at most eight) appear in the
  chunk's content or heading — with `!` as the escape character (a backslash
  would need different quoting per driver).
- Ties break by document title and chunk position. The limit is 6 passages for
  the gateway, 3 quoted in extractive mode, 20 at most.
- Embeddings are **off**: `EmbeddingProvider` is bound to `NullEmbeddingProvider`
  and there is no vector store (`NEEDS_INFRASTRUCTURE_VECTOR_STORE`). When one
  exists, a hybrid ranker can combine `ts_rank` with vector similarity behind the
  same visibility query.

## Modes

| Mode | When | What the reader gets |
| --- | --- | --- |
| `ai` | `knowledge_enabled`, passages found, `LLMGateway::available()`, `knowledge_documents` allowed outbound, and the gateway returned valid JSON | The answer text (rendered as plain text), its confidence, and the citations resolved to document title + section. Citations outside `1..n` or repeated are dropped. `grounded: false` from the model shows "The approved knowledge base does not support an answer." |
| `extractive` | AI off, not configured, breaker open, refused, failed, invalid, or no passage matched | The top 3 passages quoted verbatim with title, section and classification, and the notice that no synthesised answer is available. `grounded` is true only when a passage matched. |
| `none` | `knowledge_enabled` is off | A notice; nothing retrieved. |

The DATA block sent to the gateway is numbered passages:
`[n] <title> (§<heading>)` followed by the content; the `knowledge_answer`
prompt (`PromptRegistry`) fences it and the system rules make anything inside
it data, never an instruction. The question itself is `Redactor::text()`-ed
and capped at 1,000 characters before storage and before it reaches the
prompt as a variable.

Every question is stored (`intelligence_knowledge_questions`) and audited as
`IntelligenceKnowledgeAsked` with question length, mode, grounding and
citation count — never the text.

## Adding a document

1. **By hand:** Intelligence › Knowledge › *Manage documents* (needs
   `intelligence.governance.manage`). Fill title, classification, language,
   optional organization id and required permission, and the body (Markdown or
   plain text). It is saved as a **draft** and chunked at once.
2. **From the repository:** `php artisan intelligence:knowledge-import
   --path=docs [--approve] [--classification=internal] [--user=<id>]`.
   The path must be an existing folder inside the repository root; files are
   read one at a time. Slug = the relative path (`docs/leave-policy.md` →
   `docs-leave-policy`), title = the first `# ` heading or the file name,
   language `en`, checksum `sha1(body)`. Unchanged checksum → skipped; changed
   → new version, re-chunked, back to draft unless `--approve`.
3. **Approve** it on the documents page. Only approved documents are retrieved.
4. **Retire** it to stop quoting it without deleting history; **delete**
   removes it and its chunks (audited).

## Limitations

- No semantic retrieval: a question must share terms with the passage (or, on
  PostgreSQL, lexemes of the `simple` configuration). Synonyms are missed.
- No cross-document reasoning: the model sees at most 6 passages; the
  extractive mode shows 3.
- Document bodies are capped at 200,000 characters by validation; the gateway
  additionally truncates input to `ai_max_input_chars`.
- `upload` is a reserved `source_type`: no file upload exists yet.
- The organization field on the create form takes an organization id, not a
  picker.
- Amharic full-text search relies on the `simple` configuration (no stemming);
  Ge'ez plural fusion means a plural form does not match its singular.
