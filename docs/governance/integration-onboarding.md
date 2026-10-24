# Integration onboarding

How a new consumer (partner system, terminal, or a future separated service)
gets access to Core. Partner-facing guide: [../integration/partner-onboarding.md](../integration/partner-onboarding.md).

## Registry

The registry of consumers is the set of registered applications in Core ›
Integration Center (`external_applications`): code, owner, status, allowed
scopes, organization bounds, IP allow-list, rate limit, tokens and their expiry,
last use (`api_request_logs`). `php artisan api:access-review` lists the ones
whose access needs a decision (UNREVIEWED, UNSCOPED, OVERPRIVILEGED,
LEGACY_CREDENTIAL, PROVIDER_UNBOUND).

## Steps

| # | Step | Who |
| --- | --- | --- |
| 1 | Written request: purpose, data needed, organizations covered, contact | consumer |
| 2 | Decide the narrowest scopes and organization bounds; data minimisation check | integration owner + security owner |
| 3 | Register the application in Integration Center; test environment first | integration owner |
| 4 | Issue a token with an expiry; hand it over through an agreed secure channel, never e-mail or chat | integration owner |
| 5 | Webhook subscription if needed: HTTPS endpoint, secret handed over the same way | integration owner |
| 6 | Consumer passes its own tests against the test environment | consumer |
| 7 | Production registration; record approval in the change ticket | security owner |
| 8 | Monthly review via `api:access-review`; token rotation before expiry | integration owner |

## Offboarding

Remove scopes, revoke tokens, disable the application and its webhook
subscriptions. Its request logs follow the normal API log retention
(kept until the API log retention is approved; [retention-matrix.md](retention-matrix.md)); the application record itself is kept.
