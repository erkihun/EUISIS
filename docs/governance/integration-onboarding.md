# Integration onboarding

How a new consumer (partner system, terminal, service provider, or a future
separated service) gets access to Core. Partner-facing guide:
[../integration/partner-onboarding.md](../integration/partner-onboarding.md).
Owner: Integration/API Owner with the Information Security Lead.

## Default rule

No integration receives, by default: the full employee database, direct
database credentials, an unrestricted (`*`) token, or city-wide access with
personal-data scopes. Access is purpose-specific: the narrowest scopes, bound
to the organizations the purpose covers, through `/api/v1`, events or the
change feed. `api:access-review` flags anything wider (OVERPRIVILEGED,
LEGACY_CREDENTIAL).

## Registry

The registry is the set of registered applications in Core › Integration
Center (`external_applications`). `php artisan api:consumers` prints it:
code, owner institution, contact, status, API version, scopes, organization
access and grants, provider binding, rate limit, last use, tokens and their
next expiry, with findings (UNREVIEWED, UNSCOPED, OVERPRIVILEGED,
LEGACY_CREDENTIAL, PROVIDER_UNBOUND, NEVER_USED, STALE, NO_TOKEN_EXPIRY).
Run it in each environment; it is the impact list before any API or event
change.

## Request questions

The written request answers, before anything is registered:

| Question | Why |
| --- | --- |
| What data is needed, and why? | data minimisation; decides scopes |
| Read or write? | machine clients never write master data (ADR 0002); service transactions only through provider scopes |
| Which organizations? | organization bounds |
| Expected traffic (peak per minute, daily) | rate limit, capacity |
| Is personal data required? Which fields? | PII scopes need the Data Owner's approval |
| Events or polling? Which event types? | webhook or change feed; event scopes |
| Offline requirement? | offline sync only for approved terminals |
| Failure behaviour: what happens when Core is unreachable or refuses? | the consumer must fail safe (no service without verification unless an approved offline rule says so) |
| System owner and technical contact | registry and incident contact |

## Steps

| # | Step | Who |
| --- | --- | --- |
| 1 | Written request answering the questions above | consumer |
| 2 | Decide the narrowest scopes and organization bounds; data minimisation check; PII approval where needed | Integration/API Owner, Information Security Lead, Data Owner |
| 3 | Register the application in the test or UAT environment first | Integration/API Owner |
| 4 | Consumer tests against the non-production environment: authentication, scopes, pagination, verification, events, error handling | consumer |
| 5 | Production registration with approval recorded in the change ticket: system owner, purpose, scopes, organization bounds, rate limit, credential lifetime and rotation | Information Security Lead |
| 6 | Issue the token and any webhook secret through an agreed secure channel, never e-mail or chat | Integration/API Owner |
| 7 | Monthly review through `api:consumers` and `api:access-review`; rotation before expiry | Integration/API Owner |

Registering, changing and revoking applications, scopes and tokens is
audited. Non-production environments use their own applications and tokens;
a production token is never used for testing. A dedicated, always-available
partner sandbox does not exist yet: the UAT environment serves that purpose
when it is provisioned (go-live gate G4).

## Service providers

For each provider: provider record and contract status, service type
(cafeteria, transport, other), allowed organization scope, its API
applications and devices (transport terminals, NFC readers), provider users,
last activity. When the relationship ends: disable the provider, its
applications and tokens, its devices and its provider users the same day.

## Offboarding

Remove scopes, revoke tokens, disable the application and its webhook
subscriptions. Its request logs follow the normal API log retention (kept
until the API log retention is approved; [retention-matrix.md](retention-matrix.md));
the application record itself is kept.
