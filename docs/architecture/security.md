# Security Architecture

## Authentication

- Laravel session authentication for the web portal.
- Laravel Sanctum for SPA and provider/API token authentication.
- Strong password hashing using Argon2id or bcrypt fallback.
- Optional MFA/passkey expansion reserved for later phases.

## Authorization

### RBAC

- Spatie Laravel Permission handles roles and permissions only.
- Roles include Super Admin, City Admin, Institution Admin, Sub-city Admin, Woreda Admin, HR Officer, ID Card Officer, Service Provider User, Settlement Officer, Auditor, and Report Viewer.

### Organization Scope

- Access is granted only when both permission and organization scope checks succeed.
- `user_organization_scopes` stores allowed roots and scope types such as self, subtree, service provider, or citywide.
- `OrganizationScopeService` resolves descendants through closure paths.
- Transfer and position policies both require organization-scope checks in addition to permission checks.

### Provider Scope

- Provider users are constrained by provider, service type, optional device binding, and token ability.
- Current API middleware enforces `provider:access` or `*` Sanctum abilities for non-admin provider routes.
- Provider denials are written to audit logs as `provider_api_denied`.
- APIs return minimal data for provider contexts.

## QR and Verification Privacy

- QR codes contain only a signed token, opaque card identifier, or verification URL token.
- No full name, phone, birth date, address, salary, or document data is embedded.
- Verification responses are intentionally minimal and context-aware.
- Raw verification tokens are not stored; only hashed values are retained where possible.

## Audit and Security Logging

- Sensitive actions produce immutable audit records.
- Security events such as failed authentication, denied verification, scope changes, or API-client revocation can be stored separately if operational needs differ from business audit history.
- Export actions require permission and audit logging.
- Transfer creation, submission, confirmations, approval, rejection, cancellation, completion, position changes, and assignment changes caused by transfers are all audited.

## File Storage

- Employee photos, signatures, and documents are private.
- Local development may use a private local disk; production targets S3-compatible storage.
- Access should rely on signed temporary URLs or controlled download actions.

## API Security Controls

- Sanctum token authentication
- Token abilities
- Rate limiting
- Provider/service scope middleware
- Request validation
- Safe error messages
- Device binding foundation
- Audit for success and denial
- Duplicate provider transaction references are rejected before recording a second transaction

## Data Minimization

- Provider APIs must exclude sensitive PII by default.
- Minimal display name and photo are returned only when permitted by the service context.
- Internal admin and HR pages should still follow least-privilege display rules.
- Dashboard props are aggregate-only and must not expose phone, birth date, salary, QR tokens, token hashes, document paths, or other sensitive employee fields.
- Shared Inertia authentication props expose only safe account display data such as name, email, initials, and profile photo URL. National ID remains page-scoped to the authenticated user's profile page or permission-protected user management surfaces.

## User Account Protection

- Profile settings allow users to update personal fields and profile photo, but never roles, permissions, organization scopes, status, or account lifecycle fields.
- Profile photos are stored under `users/profile-photos/{user_id}` on the public disk and exposed through safe URLs, not raw private paths.
- Self-delete and self-deactivation are blocked in both policy/action paths and are audit logged.
- The last active Super Admin cannot be deactivated and cannot have the Super Admin role removed.
- User create/edit validation covers profile photo type/size, unique national ID, phone number format, gender enum values, role existence, and blank-password preservation on edit.

## Operational Security Assumptions

- HTTPS is mandatory in production.
- Redis is the target session/cache backend.
- Backups must be encrypted and access-controlled.
- Break-glass access requires explicit reason, expiry, approval, and audit in later iterations.
- Frontend action visibility is permission-aware, but backend policies remain the source of truth for transfer and position workflows.
