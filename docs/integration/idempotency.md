# Idempotency

Write endpoints `POST /services/{type}/transactions` and
`POST /offline-sync/transactions` accept `Idempotency-Key` (≤ 128 chars). Use a
new random key (UUID) per logical operation and resend the **same key with the same
body** on retry.

| Situation | Response |
| --- | --- |
| First request | Executes normally |
| Repeat after success, same body | Stored response replayed, header `Idempotent-Replay: true`; nothing executes again |
| Repeat while the first is still running | `409 idempotency_in_progress` — retry after a short delay |
| Same key, different body | `409 idempotency_key_reused` |
| First request failed (non-2xx or error) | Key released; a retry executes |
| Key longer than 128 chars | `400 invalid_idempotency_key` |

## Guarantees

- The claim is a row in `api_idempotency_keys` under a database unique index on
  (principal type, principal id, route, key). Of two simultaneous requests exactly
  one inserts and executes — this holds across application servers.
- Keys are scoped to the **application or person**, not the token, so rotating a
  token keeps history, and two partners can never collide on a key.
- Keys expire after 24 hours; an expired key is treated as new.
- Duplicate transaction references are still rejected by the transaction rules
  (`duplicate_transaction`) independently of this header.
