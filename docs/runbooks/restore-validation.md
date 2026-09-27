# Isolated restore validation

Run `backup.py restore-test --repo=N --config=...` ONLY on a separately provisioned isolated
runner. Its root must be mode 0700 with `.euisis-isolated-restore` containing `ISOLATED_RUNNER`;
configuration also requires `isolated=true`. These are guards, not proof of network isolation:
the infrastructure operator must enforce firewalls, storage separation and read-only repo
credentials. Never provision this marker on the primary host.

Recover file snapshots and matching release/keys first. Configure PostgreSQL binaries of the
same major as the backup. The runner selects a backup before now minus target_lag_seconds,
creates a fresh private directory, restores a time target, remaps tablespaces, replaces copied
server configuration, starts only on a private Unix socket, checks schema/data/decryption/files,
then stops and removes only its created directory. The journal records the selected backup
label, size and UTC recovery target. It never uses a caller-provided target path.
No TCP listener, workers, emails or external application requests are started.

If no suitable backup/WAL reaches the time target, the test fails. An idle cluster may not
have a replay record proving a recent target; choose a rehearsed target/workload window through
the reviewed test configuration. Missing binaries, keys, files or release compatibility also
fail. Cleanup failures retain the directory for restricted operator cleanup and record failure;
monitor abandoned sandboxes and expire their sensitive data under the incident policy.
Deletion is filesystem cleanup, not certified media erasure: use encrypted scratch volumes
and destroy their keys when decommissioning.

The automatic check is read-only and verifies actual migrated EUISIS table names. It checks
schema/release parity, unvalidated constraints, employee assignment/card orphan references,
up to 50 encrypted national IDs and 50 employee document paths. It does not invent expected
row counts or claim to validate every record. Full browser login, all file classes and
incident-specific invariants are required operator evidence below.

- [ ] PostgreSQL started with expected major/extensions and reached selected target/timeline
- [ ] Connections work; DB is promoted after the automated PITR test; no archive writes from test
- [ ] Release and migrations match without automatically applying migrations
- [ ] Organizations, units, positions, employees and assignments readable
- [ ] ID cards, service providers and cafeteria transactions readable
- [ ] Constraints and domain-specific transaction/settlement/card invariants checked
- [ ] Backup checksums/repository verification passed for the tested repository
- [ ] All critical file categories restored, reference/checksum/version validation passed
- [ ] Expected encrypted fields/canary decrypt with recovered historical key material
- [ ] Login and permission boundaries pass in the isolated application
- [ ] Card rendering/service read smoke tests pass; no real notifications or external writes
- [ ] Independent repository 2 exercised, not just repository 1
- [ ] Measured RPO/RTO, backup label, time target, timeline and evidence ticket recorded
- [ ] Scratch cluster stopped; data securely cleaned up or restricted incident retention assigned
- [ ] Sanitized journal transferred to collector; application history/audit and alert path checked

Repeat at least monthly as a proposed baseline, before major migration and after backup,
infrastructure or key changes. Business/security owners approve the actual frequency.
Refresh controls.json only after reviewing this checklist and the evidence ticket.
