# Organization scope for partners

## Registered applications

`external_applications.organization_access`:

| Value | Meaning |
| --- | --- |
| `city` | Every organization. Approve only for city-level systems. |
| `scoped` (default for new applications) | Only organizations in `external_application_organization_scopes`. |
| `unreviewed` | Set by migration on applications that existed before bounds. Reads **no** organization-bound data until an administrator chooses `city` or `scoped`. |

Grant types:

- `self` — that organization only.
- `subtree` — the organization and every descendant in the **latest published**
  hierarchy version (`organization_closure_paths`). A new version changes the reach
  of a subtree grant from its publication onward.

## People

The person's own `OrganizationScopeService` scope (self / subtree / city-wide),
exactly as on the web screens, plus the permission named in
[partner-scopes.md](partner-scopes.md).

## How it is applied

- An employee's organization is always resolved server-side from the **current
  assignment**. Nothing in the request can move an employee into scope.
- Lists are filtered; single records outside the bound are **404 Not Found**, the
  same answer as for a record that does not exist (no enumeration).
- Card verification outside the bound is reported as `card_not_found`.
- Implemented once in `App\Services\Integration\IntegrationAccessService`.

## Review

`php artisan api:access-review` (or the API Management list) flags
`UNREVIEWED`, `UNSCOPED` (scoped, no grants), `OVERPRIVILEGED` (city-wide with
personal-data scopes, or a `*` token), `LEGACY_CREDENTIAL`, `PROVIDER_UNBOUND`.
