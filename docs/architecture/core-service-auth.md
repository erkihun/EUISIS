# Core service authentication

How services and partners authenticate to Core today, and what applies when
Core runs as its own application. Partner-facing detail is in
[../integration/partner-authentication.md](../integration/partner-authentication.md),
[../integration/partner-scopes.md](../integration/partner-scopes.md) and
[../integration/organization-scope.md](../integration/organization-scope.md).

## Today

| Caller | Mechanism | Limits |
| --- | --- | --- |
| People in the browser | Session sign-in (`/login`, `/core/login`), MFA where configured, idle timeout | Permission (what) + organization scope (where), checked on every request |
| Service modules inside the application | In-process contracts (`app/Backbone/Contracts`) and Core commands (`app/Backbone/Commands`) | The caller's own permission and scope checks; no machine identity needed |
| Registered applications (partners, terminals, other systems) | `ExternalApplication` with Sanctum tokens | Exact scope on the token **and** still in the application's allowed scopes (`EnsureApiScope`); organization bounds (`organization_access`, `ExternalApplicationOrganizationScope`); per-application rate limits; IP allow-list where configured; request logs; token expiry |
| Webhook receivers | HMAC-signed deliveries with timestamp tolerance and secret rotation overlap | HTTPS only unless `INTEGRATION_WEBHOOK_ALLOW_HTTP`; internal hosts must be listed |

Machine principals may read and verify, never write Golden Path master data
(ADR 0002, `MachineWriteBoundaryTest`).

## When Core is a separate application

- Each service application registers as an `ExternalApplication` in Core with the narrowest scopes it needs (for example `employees.read`, `id_cards.verify`, `organizations.read`) and organization bounds where the service is not city-wide.
- Tokens are issued by Core administrators, stored in the service's secret store, never in code, `.env` committed to Git, logs or documentation, and given an expiry. Rotation: issue the new token, deploy, then revoke the old one.
- Every request carries `X-Request-ID` (`RequestCorrelationId`), so one action can be followed across both applications' logs and `audit_logs.request_id`.
- Requests on behalf of a person keep that person as the actor: the service passes the acting user's Core identifier, Core records it in the audit row and still applies the application's own scopes and bounds. A service never borrows a person's session.
- No service receives Core database credentials.
- Write calls (when the API gains them) require an idempotency key (see [../integration/idempotency.md](../integration/idempotency.md)).

## Revocation and incidents

Remove a scope from the application (takes effect at once, no wait for token
expiry), revoke tokens, or disable the application in Core → Integration
Center. Key and secret recovery: [../key-recovery-runbook.md](../key-recovery-runbook.md).
