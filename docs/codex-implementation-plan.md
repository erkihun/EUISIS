# Codex Implementation Plan

Implemented scope:

- Add relationship enums and relationship statuses.
- Add `structural_organization_id` to institution offices.
- Add office and organization-unit relationship tables.
- Add models, resources, policies, controllers, requests, services, routes, localization, docs, UI panels, and focused tests.
- Keep the main hierarchy tree single-parent.
- Keep functional reporting permission-controlled and separate from structural management.

Follow-up improvements:

- Add a dedicated reporting-lines Inertia page.
- Add typeahead target search endpoints for large organization datasets.
- Expand coverage for cafeteria and transfer workflows with fixture-backed integration tests.
