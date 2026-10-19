# Addis One Help — public knowledge

## Sources (deterministic public visibility)

| Source | Public when | Read through |
| --- | --- | --- |
| Intelligence knowledge documents | `classification = public`, `status = approved`, no organization, no required permission | `PublicKnowledgeService::searchDocuments()` (FTS on PostgreSQL, LIKE elsewhere) |
| FAQs (Public Site Management) | `is_published = true` | `searchFaqs()` — term match on question/answer in the site locale |
| Services (Public Site Management) | `status = published` | `searchServices()` — name/summary match; requirements and steps as plain text |
| Page guidance | always | `lang/{en,am}/public_assistant.php` → `pages.*`, `feedback.*`, `tracking.*` |
| Institution public facts | active organization QR code | the page's own resolver: public name, `public_contact_phone`, published service list |

Nothing else is a source. Internal documents are not "filtered out of the
answer"; they are not selected.

## Publishing control

Documents become public by setting classification `public` and approving
them in Intelligence › Knowledge › Documents (`intelligence.governance.manage`).
Importing a folder (`intelligence:knowledge-import --classification=public --approve`)
publishes it wholesale, so keep public guides in their own folder. FAQs and
services publish through Public Site Management; drafts stay invisible.

## Versioning and freshness

A document's body change creates a new draft version that must be
re-approved, so the public assistant always reads the latest approved
version. Sources are shown by title ("Source: …"); FAQ answers link to the
Support page. Effective dates are not modelled on documents
(`NEEDS_DATA` if public policies need them).

## Languages

Retrieval uses the visitor's site locale for FAQs and services (falling
back to English fields) and matches Ethiopic terms as written; documents are
retrieved in the language they were written in. No translation is invented:
when only one language is published, that text is shown.

## When nothing matches

"I could not find an approved public instruction covering that question"
plus the Support page link. The model, when used, is told the same.
