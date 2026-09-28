# PostgreSQL point-in-time recovery

This is an operator procedure, not a browser action. No production restore occurs merely
because a request is approved. Requester and approver must differ.

1. Declare incident, stop further damaging writers where appropriate, preserve current
   failed/corrupt state, WAL and logs for investigation. Take a pre-restore physical backup
   or snapshot where technically possible; explain any inability in the incident ticket.
2. Establish the last safe target using incident/audit evidence. Use an explicit timezone
   (`2026-09-27 10:43:19+03:00` is only a format example). Decide inclusive/exclusive semantics
   and timeline with the DBA; estimate legitimate changes that will be lost/reconciled.
3. Submit the request in Backup & Recovery: type (point in time, specific backup set or latest),
   incident ticket, reason and target. A reviewer starts review, and a *different* approver
   approves the isolated test. Prepare a private isolated host
   with matching PostgreSQL major/extensions, sufficient storage, no public network listener,
   outbound access restricted to repositories, and recovered key/file material.
4. Inspect `pgbackrest --stanza=euisis --repo=2 --output=json info` in restricted operations
   tooling. Select a validated backup completed before the target. Check available timelines
   and archive retention; min/max WAL alone cannot prove no holes.
5. Record the test start: `php artisan backup:restore-record REQUEST_UUID test-start
   --actor=OPERATOR_ID --evidence=TEST_ENV_TICKET`. On that isolated host, configure the
   read-only repository connection and an **empty, newly created** data directory. As its PostgreSQL OS user, adapt this reviewed command:

```sh
pgbackrest --config=/etc/pgbackrest/restore.conf --stanza=euisis --repo=2 \
  --reset-pg1-host --pg1-path=/isolated/NEW_EMPTY_CLUSTER \
  --tablespace-map-all=/isolated/NEW_TABLESPACES \
  --set=REPLACE_VALIDATED_BACKUP_LABEL --type=time \
  --target='REPLACE_APPROVED_TIME_WITH_OFFSET' --target-action=pause \
  --archive-mode=off restore
```

6. Use an isolated PostgreSQL configuration, private socket/HBA and no production preload,
   connection, archive or replication settings. Start PostgreSQL, replay WAL and confirm
   it reached the intended target (inspect recovery state/logs/replay timestamp). Missing WAL,
   incompatible binaries, unreachable target or unexpected timeline is a hard stop.
7. Validate the [restore checklist](restore-validation.md), including encrypted fields,
   files and incident-specific data invariants. Record target actually reached, backup label,
   repository, timeline, checks, durations and evidence ticket. Rehearse from repository 2
   as well as repository 1. Record `test-pass --evidence=VALIDATION_TICKET` (or `test-fail`).
   A different approver reviews that evidence and selects **Authorize production restore**.
8. Arrange incident/maintenance window, freeze web/queue/integration writers and preserve
   their pending work. Take the pre-restore safeguard backup/snapshot of the current state,
   then record START with its evidence:
   `php artisan backup:restore-record REQUEST_UUID start --actor=OPERATOR_ID --evidence=SAFEGUARD_TICKET`.
   If the primary app DB is down, keep the signed authorization and execution log externally,
   then reconcile the audit after recovery. Never lose evidence by restoring the only copy.
9. Prefer controlled cutover to the validated replacement. Resume/promote at the approved
   target only after explicit DBA/approver review. Restore matching files/keys and release,
   update connection routing, restart workers with dispatch held, run smoke tests. Do not
   automatically migrate the recovered DB to a different release.
10. Reopen traffic gradually, reconcile post-target transactions and verify ongoing backups
    and archiving on the new timeline. Record complete/fail via `backup:restore-record`,
    preserving detailed reasons only in the restricted incident record. Conduct post-incident
    review and a fresh independent backup/test. Retain forensic state under approved policy.

Do not use `--delta` against the active primary directory. Do not use arbitrary shell
arguments received from HTTP. Repository retrieval and key access are strongly authorized
and audited outside normal application administration.
