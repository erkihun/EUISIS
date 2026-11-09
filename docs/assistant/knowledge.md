# Addis One Assistant — knowledge and page help

## Sources

The assistant reads the Intelligence knowledge base
(`docs/intelligence-knowledge.md`): approved, classified documents chunked
for retrieval — today the repository's `docs/` folder imported with
`intelligence:knowledge-import --path=docs --approve` (228 documents on the
local installation), plus documents added through Intelligence › Knowledge
› Documents. Manuals, workflows, policies, job descriptions and runbooks
belong there; nothing is hard-coded in React.

## Two layers

1. **Page guidance** (`page_help`): `lang/{en,am}/assistant.php` → `pages.<module>`
   with purpose, usual steps, needed grants and common mistakes for the
   main modules (employees, organizations, positions, transfers,
   performance, daily activity, field work, grievances, discipline, public
   feedback, ID cards, Situation Room, Intelligence, system settings, users,
   My Portal, support, profile). Modules without an entry answer from the
   general entry with a notice (`NEEDS_DATA_HELP_COVERAGE`). When the
   knowledge capability is on, the three best passages for "module label +
   question" are appended with their document titles as sources.
2. **Knowledge answers** (`knowledge_search`): `KnowledgeAssistant::ask()` —
   AI answer with citations when the gateway is on and `knowledge_documents`
   may go outbound; otherwise the top quoted passages (extractive). Either
   way the answer cites document titles, never storage paths.

## Security before retrieval

`KnowledgeRetriever::visibleDocuments()` filters in SQL by status
(approved), classification (confidential needs `intelligence.governance.view`
or the document's `required_permission`; restricted never for plain
readers), `required_permission` held, and organization scope. The model
sees only what survived the filter. "Unauthorized confidential document
must not appear" is asserted in `tests/Feature/Intelligence/KnowledgePagesTest.php`.

## When there is nothing

If no passage matches or the knowledge capability is off, the assistant
says the approved knowledge base does not contain enough information and
points to "Explain this page" or "Report a problem". It never invents a
policy.

## Language

Answers follow the application locale (Amharic UI → Amharic prompt
language; the dictionaries are mirrored). Documents are retrieved in the
language they were written in.
