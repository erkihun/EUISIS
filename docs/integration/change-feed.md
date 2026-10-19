# Change feed

Audience: partner engineers pulling master-data changes from Addis One.

The change feed is the **authoritative pull and recovery** mechanism. Webhooks are
push notifications on top of it; never make a webhook your only source.

## Endpoints

All require a registered application token, scope `changes.read`, and the feed to be
enabled (`404 change_feed_disabled` otherwise). People's tokens get
`403 application_required`.

### `GET /api/v1/changes`

| Query | Meaning |
| --- | --- |
| `cursor` | Opaque, from the previous response. Omit to start from the beginning. |
| `limit` | 1–500, default 100. |
| `event_types` | Comma-separated subset, e.g. `id_card.revoked,employee.status_changed`. |
| `aggregate_types` | Comma-separated subset, e.g. `employee,id_card`. |

```json
{ "data": [ /* event envelopes, see event-catalog.md */ ], "next_cursor": "eyJ2Ijox…", "has_more": true, "request_id": "…" }
```

- Ordered by feed position; keyset pagination, never offset.
- Keep requesting with `next_cursor` while `has_more` is true; then poll with the last
  `next_cursor` (every 10–60 s is plenty — the feed is filled every ~10 s).
- Each call returns only events whose type your scopes allow **and** whose
  organization (or previous organization, for moves) is inside your bound. Both are
  SQL filters; nothing outside your bound is ever read for you.
- A page may be shorter than `limit` or empty while `next_cursor` still advances:
  rows you may not see are skipped.

### `GET /api/v1/changes/watermark`

`{ "cursor": "…", "as_of": "…" }` — the current end of the feed. Use it for bootstrap.

### `POST /api/v1/changes/reconcile`

```json
{ "aggregate_type": "employee", "uuids": ["…", "…"] }   // at most 100
```

Returns, per UUID, the latest event **you can see**: `found`, `sequence`,
`last_event_id`, `last_event_type`, `change_kind`, `occurred_at`. Compare with your
stored sequence to detect what you missed. Not a data dump: it returns versions,
never records.

## Cursor

Opaque, signed and bound to your application. A tampered, forged or another
application's cursor is `400 invalid_cursor`. It is a bookmark, never an authority —
scopes and grants are applied on every call.

## Guarantees

- **No skips, no duplicates under concurrent writes.** Positions are assigned only to
  committed events, in the order they become visible, and never below a position
  already served. A late-committing transaction lands after your cursor.
- **Per-aggregate order.** `sequence` increases by one per aggregate. A gap while the
  aggregate stayed inside your bound means you missed an event (recover with the
  feed or `reconcile`). Gaps can be legitimate when the record moved outside your
  bound and back.
- **At-least-once.** Re-reading from an older cursor returns the same events with the
  same `event_id`s.
- **Eventual consistency.** The feed trails a committed change by seconds. Your cached
  copies are never authoritative; query the API when a decision depends on current
  state (e.g. card verification at the point of service).

## Bootstrap (new partner)

1. `GET /changes/watermark` → store `W`.
2. Page through the authorised resource APIs (`/api/v1/employees`, `/organizations/directory`, … — scoped, `per_page` ≤ 100). Never ask for "all 3M employees".
3. `GET /changes?cursor=W` and apply everything after the watermark. Changes made
   during step 2 appear here, so no race window is left; applying an event to a
   record you already fetched in its newer state is harmless (use `sequence`).
4. Run `reconcile` on a sample (or all) of your UUIDs to confirm.

`updated_after` on the resource APIs is fine for ad-hoc catch-up but can miss or
repeat rows under concurrent writes; the feed cannot.
