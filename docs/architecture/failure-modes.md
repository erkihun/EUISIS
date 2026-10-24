# Failure modes

What each Core-dependent operation does when something it needs is missing or
unavailable. Three behaviours:

- **FAIL_CLOSED**: refuse, never guess. Used for identity, access and credentials.
- **CACHE_ALLOWED**: serve a recent cached answer, clearly as of its time.
- **DEFER_ALLOWED**: record the work and finish it later; the user is not blocked.

Today Core and the services share one application and one database, so "Core
down" means the whole application is down; the table still matters for the
parts that run outside the request (queues, partners, terminals) and for the
later separation.

| Operation | Behaviour | How it is implemented today |
| --- | --- | --- |
| Public QR / ID-checker verification | FAIL_CLOSED | Unknown, revoked, expired, replaced or non-current cards are invalid (`IdCardCredentialStatus`); the public page shows minimal data only |
| Partner card verification (`/api/v1/id-cards/verify`) | FAIL_CLOSED | Same credential status; scope and organization bounds checked first |
| Service eligibility (cafeteria, transport, NFC terminals) | FAIL_CLOSED | `EmployeeServiceEligibilityService` denies inactive employees and invalid cards and records the attempt |
| Permission and organization-scope checks | FAIL_CLOSED | Policies and `OrganizationScopeService`; a missing organization is refused (`canAccessOrganization(null)` is false) — except users with no scope rows, see [core-authority.md](core-authority.md) |
| Sign-in, MFA, OTP | FAIL_CLOSED | Throttles and lockouts live in the cache: if the cache store is down, sign-in fails rather than skipping the throttle |
| Master-data writes during a freeze | FAIL_CLOSED | `GuardMasterDataWrites` answers 423 / a clear message |
| Dashboards, Core metrics, Situation Room figures | CACHE_ALLOWED | Cached counts with their own time-to-live; stale figures are acceptable, wrong access is not |
| Integration events to partners | DEFER_ALLOWED | Transactional outbox; `integrations:publish` every 10 s; change feed by cursor |
| Webhook delivery | DEFER_ALLOWED | Retries with backoff (up to 8 attempts by default), then `dead`; replay from Integration Center |
| E-mail, SMS, notifications, exports | DEFER_ALLOWED | Queued jobs with retries |
| Transport/communication reactions to Core events | DEFER_ALLOWED | Queued listeners on backbone events |
| AI assistant and intelligence answers | DEFER_ALLOWED / degrade | The gateway reports unavailability; no Core decision depends on it |

## Never allowed

- A cached or guessed answer for "is this card valid" or "may this person see this organization".
- Creating a placeholder organization, employee or card because the real one could not be read.
- Retrying a write without an idempotency key.
