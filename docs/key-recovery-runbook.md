# Key recovery

Key custodian: appointed Security/System Admin. Recovery access: named authorized operator
with separately recorded approval. Actual custodians, escrow location and emergency access
process must be recorded in a restricted register, not this repository.

- Escrow Laravel APP_KEY, any previous application keys, each repository cipher password,
  storage/SSH credentials, database recovery credentials and configuration in an approved
  encrypted secrets store/offline escrow. Keep escrow outside backup repositories and Git.
- Record key identifiers/versions, creation/rotation dates, access policy and which retained
  backup generations require each key. Do not put actual keys, secret URLs, connection strings
  or values in tickets, application metadata, frontend variables, logs or this document.
- Require authenticated recovery access and access audit. Separate repository operators from
  key custodians where practical. Test the emergency access path when normal identity services
  are unavailable; do not make key escrow depend only on the failed database/server.
- In an isolated recovery, inject the matching APP_KEY securely into a private matching
  application release. Clear/rebuild its config cache there as appropriate. Decrypt existing
  encrypted national IDs through Laravel and compare expected test evidence without printing
  plaintext. `restore_check.php` bypasses the employee accessor's legacy plaintext fallback
  and fails when decrypt cannot work. An empty sample is not sufficient key proof; a custodian
  must verify a known protected ciphertext/canary and record evidence before attesting.
- Rotation does not retroactively change retained backups. Keep old keys as long as any
  required backup/ciphertext depends on them. For APP_KEY rotation, plan previous-key support
  and field re-encryption, invalidate sessions as needed, then test old and new generations.
  A repository cipher-key rotation may require a new repository and new full chain: follow
  the installed tool's procedure and retain old repository keys until expiry/holds permit.
- A successful DB restore without keys may leave encrypted HR fields unusable. Lost required
  key material is a production blocker; do not silently replace it with `key:generate`.

Record key retrieval duration and evidence ticket during each periodic exercise. Set the
key_recovery attestation only after successful recovery, never merely because a secret exists.
