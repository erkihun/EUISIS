# Transport — Offline Mode (policy-gated)

Default: **off** (`transport_offline` flag off and `offline_enabled = false`). With both off the sync endpoint answers `409 offline_disabled` / per-item `offline_disabled` and nothing is stored as accepted: offline never fails open.

When enabled (NEEDS_POLICY_DECISION_OFFLINE_BOARDING: who may enable it and for which routes):

1. The device queues boardings locally with an `offline_uuid`, the credential, trip, stop and the device's `recorded_at`.
2. `POST /api/v1/transport/boardings/sync` (max items per call, device-authenticated) runs **each item through the same engine** with `source = offline_sync`; `offline_uuid` is the idempotency key, so a retried batch replays the same answers.
3. Guards: the record's date must match the trip's date (`offline_trip_date_mismatch`); it must be younger than `offline_grace_minutes` (`offline_record_too_old`); duplicates and capacity apply as online; the server clock dates the row, the device's time is kept as `offline_recorded_at`.
4. Sync state: `offline_pending` until reconciled on arrival → `offline_reconciled` (accepted) or `offline_rejected`. Statements count only reconciled rows and report any still pending in `basis.data_quality.offline_pending_boardings`; the data-quality check `offline_pending_boardings` and the `transport` health component show the backlog.
5. Manifests show the sync state per row; exceptions `offline_replay` record retries.

Vehicle positions (`transport_gps`) follow the same pattern and are retained `gps_retention_days` (NEEDS_POLICY_DECISION_TRANSPORT_RETENTION).
