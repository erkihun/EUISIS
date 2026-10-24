# Consumer guide — Addis One master-data events

Audience: engineers building a partner system (cafeteria, transport, HR satellite)
that keeps in step with Addis One.

## 1. Authenticate

Bearer token of your registered application ([partner-authentication.md](partner-authentication.md)).
One credential for the resource APIs, the change feed and the webhook endpoints.

## 2. Scopes

`changes.read` for the feed, plus the domain scope of every event family you need
([event-catalog.md](event-catalog.md)). Without `employees.basic_read` you see no
`employee.*` events; without `id_cards.verify` no `id_card.*`. Your organization bound
applies to every event.

## 3. Identifiers

Store Addis One UUIDs (`employee_uuid`, `card_uuid`, `organization_uuid`,
`assignment_uuid`, `position_uuid`, `unit_uuid`). Employee numbers and card numbers
are display references and may change; UUIDs never do. Never use database integers.

## 4. Change feed (pull, authoritative)

See [change-feed.md](change-feed.md). Persist `next_cursor` **after** you have applied
the page. Bootstrap from the watermark.

## 5. Events

Envelope and types in [event-catalog.md](event-catalog.md). Rules:

- process `event_id` **idempotently** — duplicates are normal;
- use `sequence` per aggregate to detect gaps and out-of-order arrival; ignore an
  event whose sequence is not newer than the one you applied;
- `change_kind: tombstone` → remove from your active view / stop service;
- unsupported `event_version` → park it and alert; do not guess;
- unknown fields → ignore;
- treat your copy as a cache; call the API when a decision depends on current state.

## 6. Webhooks (push, optional)

[webhook-security.md](webhook-security.md): verify the signature over the raw body,
reject timestamps older than 5 minutes, de-duplicate on `event_id`, answer 2xx within
a few seconds and do the work asynchronously. A webhook is a hint to look; the feed
is the record.

## 7. Retries and errors

Your endpoint: 2xx = done; 429/5xx/timeouts are retried with backoff; other 4xx stop
delivery (dead) until replayed. Our API errors use
`{ message, error_code, request_id }` with 400/401/403/404/409/422/429
([api-v1-contract.md](api-v1-contract.md)); quote `request_id` when you report a
problem.

## 8. Reconciliation

After an outage: read the feed from your last cursor — that alone restores your state.
For spot checks, `POST /api/v1/changes/reconcile` with up to 100 UUIDs and compare
sequences ([replay-and-reconciliation.md](replay-and-reconciliation.md)).

## 9. Cafeteria pilot — what to do with each event

| Event | Cafeteria action |
| --- | --- |
| `id_card.revoked`, `id_card.suspended`, `id_card.expired`, `id_card.status_changed` (not active) | Drop cached eligibility for that `card_uuid` immediately. |
| `id_card.activated` | The card may be served again; the next scan still verifies with Addis One. |
| `employee.status_changed` (tombstone) | Stop serving the employee; keep history. |
| `employee.transferred` | Re-check the organization assignment for the cafeteria; `source_only` means the employee left your scope. |
| `assignment.closed` | Remove the closed placement from any cache. |
| `hierarchy_version.published` | Re-read the organization directory if you cache it. |

Card verification at the point of service always calls Addis One
([id-card-verification.md](id-card-verification.md)); events only keep caches fresh.
