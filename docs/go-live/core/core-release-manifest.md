# Core release manifest

| Item | Value |
|---|---|
| Git commit | `15c095aadc3d1e087d18734566120738c1292a7a` |
| Release tag | untagged |
| Built at (UTC) | 2026-10-25T22:33:14Z |
| PHP (build) | 8.4.25 — servers: PHP 8.4 |
| Node (build) | v26.7.0 |
| Laravel | v13.38.0 |
| Inertia (server) | v3.5.2 |
| Sanctum | v4.3.3 |
| Database | PostgreSQL 18 (rehearsed on 18.6); MariaDB 10.4 passes the suite |
| composer.lock SHA-256 | `110cfe2e7ee1baf196c96cb0094badf107533408a6d40fe7ebce3847d825326a` |
| Frontend build manifest SHA-256 | `03fed388b8c605b16bf9916cf32bc5f295748d6b5a5069a7cd22c6dbffe8e2d5` |
| Migrations | 29 |
| Artifact | `core-15c095aa.tar.gz` (7.7 MB), SHA-256 `1815711609fee1cdf1d5719a9e162dd0194e36c49b875fe090687c3d5ed03912`, built by `deploy/release/build-artifact.sh` |
| Status | **release candidate, not agreed and not tagged**: proposed tag `core-v1.0.0-rc1` once the release commit is agreed and pushed and CI is green (D-12). Generated with `deploy/release/manifest.php` from the deployed staging release |

## Migrations

- `0001_01_01_000000_create_users_table`
- `0001_01_01_000001_create_cache_table`
- `0001_01_01_000002_create_jobs_table`
- `2026_10_25_113335_create_personal_access_tokens_table`
- `2026_10_25_120000_create_golden_path_tables`
- `2026_10_25_120100_create_core_access_and_audit_tables`
- `2026_10_25_130000_create_core_settings_table`
- `2026_10_25_140000_create_code_sequences_table`
- `2026_10_25_180000_add_hierarchy_review_metadata`
- `2026_10_25_190000_harden_core_access_and_audit`
- `2026_10_25_190100_create_employee_duplicate_reviews_table`
- `2026_10_25_200000_create_integration_tables`
- `2026_10_26_100000_make_organization_scope_explicit`
- `2026_10_26_110000_add_employee_verification_workflow`
- `2026_10_26_120000_add_multi_factor_authentication_to_users`
- `2026_10_27_100000_create_employee_documents_table`
- `2026_10_27_110000_extend_employee_duplicate_reviews`
- `2026_10_27_120000_create_core_roles_tables`
- `2026_10_27_130000_create_import_batches_tables`
- `2026_10_27_140000_add_p2_integrity_constraints`
- `2026_10_28_100000_normalize_position_masters`
- `2026_10_28_100100_grant_p3_permissions`
- `2026_10_28_100200_add_assignment_provenance`
- `2026_10_28_100300_create_card_production_tables`
- `2026_10_28_100400_create_digital_credentials_table`
- `2026_10_28_100500_add_audit_integrity_and_break_glass`
- `2026_10_28_100600_harden_webhook_endpoints`
- `2026_10_29_100000_create_oversight_tables`
- `2026_10_29_100100_add_scale_indexes`

## Environment variables

Required in production are marked **required**; secrets come from the secret store, never the artifact.

| Variable | Read by | Production |
|---|---|---|
| `APP_DEBUG` | app | **required** |
| `APP_ENV` | app | **required** |
| `APP_FAKER_LOCALE` | app | optional |
| `APP_FALLBACK_LOCALE` | app | optional |
| `APP_KEY` | app | **required** (secret) |
| `APP_LOCALE` | app | optional |
| `APP_MAINTENANCE_DRIVER` | app | optional |
| `APP_MAINTENANCE_STORE` | app | optional |
| `APP_NAME` | app, cache, database, logging, mail, session | optional |
| `APP_PREVIOUS_KEYS` | app | optional |
| `APP_URL` | app, filesystems, mail | **required** |
| `AUTH_GUARD` | auth | optional |
| `AUTH_MODEL` | auth | optional |
| `AUTH_PASSWORD_BROKER` | auth | optional |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | auth | optional |
| `AUTH_PASSWORD_TIMEOUT` | auth | optional |
| `AWS_ACCESS_KEY_ID` | cache, filesystems, queue, services | optional |
| `AWS_BUCKET` | filesystems | optional |
| `AWS_DEFAULT_REGION` | cache, filesystems, queue, services | optional |
| `AWS_ENDPOINT` | filesystems | optional |
| `AWS_SECRET_ACCESS_KEY` | cache, filesystems, queue, services | optional (secret) |
| `AWS_URL` | filesystems | optional |
| `AWS_USE_PATH_STYLE_ENDPOINT` | filesystems | optional |
| `BEANSTALKD_QUEUE` | queue | optional |
| `BEANSTALKD_QUEUE_HOST` | queue | optional |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | queue | optional |
| `CACHE_PREFIX` | cache | optional |
| `CACHE_STORAGE_DISK` | cache | optional |
| `CACHE_STORAGE_PATH` | cache | optional |
| `CACHE_STORE` | cache | **required** |
| `CORE_AUDIT_HEAD_DISK` | core | **required** |
| `CORE_AUDIT_HEAD_ROOT` | filesystems | optional |
| `CORE_AUDIT_SEAL_KEY` | core | **required** (secret) |
| `CORE_BACKUP_MAX_AGE_HOURS` | core | optional |
| `CORE_BACKUP_STATUS_FILE` | core | **required** |
| `CORE_CREDENTIAL_KEYS` | core | **required** (secret) |
| `CORE_CSP_ENFORCE` | core | optional |
| `CORE_EVIDENCE_ROOT` | filesystems | optional |
| `CORE_EXPORTS_ROOT` | filesystems | optional |
| `CORE_FRONTEND_ORIGINS` | core, cors | **required** |
| `CORE_IMPORTS_ROOT` | filesystems | optional |
| `CORE_MFA_ENFORCE` | core | **required** |
| `CORE_MONITOR_TOKEN` | core | **required** (secret) |
| `CORE_SLOW_REQUEST_MS` | core | optional |
| `DB_CACHE_CONNECTION` | cache | optional |
| `DB_CACHE_LOCK_CONNECTION` | cache | optional |
| `DB_CACHE_LOCK_TABLE` | cache | optional |
| `DB_CACHE_TABLE` | cache | optional |
| `DB_CHARSET` | database | optional |
| `DB_COLLATION` | database | optional |
| `DB_CONNECTION` | database, queue | **required** |
| `DB_DATABASE` | database | **required** |
| `DB_ENCRYPT` | database | optional |
| `DB_FOREIGN_KEYS` | database | optional |
| `DB_HOST` | database | **required** |
| `DB_PASSWORD` | database | **required** (secret) |
| `DB_PORT` | database | **required** |
| `DB_QUEUE` | queue | optional |
| `DB_QUEUE_CONNECTION` | queue | optional |
| `DB_QUEUE_RETRY_AFTER` | queue | optional |
| `DB_QUEUE_TABLE` | queue | optional |
| `DB_SOCKET` | database | optional |
| `DB_SSLMODE` | database | optional |
| `DB_TRUST_SERVER_CERTIFICATE` | database | optional |
| `DB_URL` | database | optional |
| `DB_USERNAME` | database | **required** |
| `DYNAMODB_CACHE_TABLE` | cache | optional |
| `DYNAMODB_ENDPOINT` | cache | optional |
| `EUISIS_APP_URL` | core | optional |
| `EUISIS_DB_CHARSET` | database | optional |
| `EUISIS_DB_COLLATION` | database | optional |
| `EUISIS_DB_CONNECTION` | database | optional |
| `EUISIS_DB_DATABASE` | database | optional |
| `EUISIS_DB_HOST` | database | optional |
| `EUISIS_DB_PASSWORD` | database | optional (secret) |
| `EUISIS_DB_PORT` | database | optional |
| `EUISIS_DB_SCHEMA` | database | optional |
| `EUISIS_DB_SSLMODE` | database | optional |
| `EUISIS_DB_USERNAME` | database | optional |
| `FILESYSTEM_DISK` | filesystems | optional |
| `LOG_CHANNEL` | logging | optional |
| `LOG_DAILY_DAYS` | logging | optional |
| `LOG_DEPRECATIONS_CHANNEL` | logging | optional |
| `LOG_DEPRECATIONS_TRACE` | logging | optional |
| `LOG_LEVEL` | logging | optional |
| `LOG_PAPERTRAIL_HANDLER` | logging | optional |
| `LOG_SLACK_EMOJI` | logging | optional |
| `LOG_SLACK_USERNAME` | logging | optional |
| `LOG_SLACK_WEBHOOK_URL` | logging | optional |
| `LOG_STACK` | logging | optional |
| `LOG_STDERR_FORMATTER` | logging | optional |
| `LOG_SYSLOG_FACILITY` | logging | optional |
| `MAIL_EHLO_DOMAIN` | mail | optional |
| `MAIL_FROM_ADDRESS` | mail | optional |
| `MAIL_FROM_NAME` | mail | optional |
| `MAIL_HOST` | mail | optional |
| `MAIL_LOG_CHANNEL` | mail | optional |
| `MAIL_MAILER` | mail | **required** |
| `MAIL_PASSWORD` | mail | optional (secret) |
| `MAIL_PORT` | mail | optional |
| `MAIL_SCHEME` | mail | optional |
| `MAIL_SENDMAIL_PATH` | mail | optional |
| `MAIL_URL` | mail | optional |
| `MAIL_USERNAME` | mail | optional |
| `MEMCACHED_HOST` | cache | optional |
| `MEMCACHED_PASSWORD` | cache | optional |
| `MEMCACHED_PERSISTENT_ID` | cache | optional |
| `MEMCACHED_PORT` | cache | optional |
| `MEMCACHED_USERNAME` | cache | optional |
| `MYSQL_ATTR_SSL_CA` | database | optional |
| `NATIONAL_ID_HASH_KEY` | core | **required** (secret) |
| `PAPERTRAIL_PORT` | logging | optional |
| `PAPERTRAIL_URL` | logging | optional |
| `POSTMARK_API_KEY` | services | optional |
| `POSTMARK_MESSAGE_STREAM_ID` | mail | optional |
| `QUEUE_CONNECTION` | queue | **required** |
| `QUEUE_FAILED_DRIVER` | queue | optional |
| `REDIS_BACKOFF_ALGORITHM` | database | optional |
| `REDIS_BACKOFF_BASE` | database | optional |
| `REDIS_BACKOFF_CAP` | database | optional |
| `REDIS_CACHE_CONNECTION` | cache | optional |
| `REDIS_CACHE_DB` | database | optional |
| `REDIS_CACHE_LOCK_CONNECTION` | cache | optional |
| `REDIS_CLIENT` | database | optional |
| `REDIS_CLUSTER` | database | optional |
| `REDIS_DB` | database | optional |
| `REDIS_HOST` | database | optional |
| `REDIS_MAX_RETRIES` | database | optional |
| `REDIS_PASSWORD` | database | optional (secret) |
| `REDIS_PERSISTENT` | database | optional |
| `REDIS_PORT` | database | optional |
| `REDIS_PREFIX` | database | optional |
| `REDIS_QUEUE` | queue | optional |
| `REDIS_QUEUE_CONNECTION` | queue | optional |
| `REDIS_QUEUE_RETRY_AFTER` | queue | optional |
| `REDIS_URL` | database | optional |
| `REDIS_USERNAME` | database | optional |
| `RESEND_API_KEY` | services | optional |
| `SANCTUM_STATEFUL_DOMAINS` | sanctum | optional |
| `SANCTUM_TOKEN_PREFIX` | sanctum | optional |
| `SESSION_CONNECTION` | session | optional |
| `SESSION_COOKIE` | session | optional |
| `SESSION_DOMAIN` | session | optional |
| `SESSION_DRIVER` | session | **required** |
| `SESSION_ENCRYPT` | session | optional |
| `SESSION_EXPIRE_ON_CLOSE` | session | optional |
| `SESSION_HTTP_ONLY` | session | optional |
| `SESSION_LIFETIME` | session | optional |
| `SESSION_PARTITIONED_COOKIE` | session | optional |
| `SESSION_PATH` | session | optional |
| `SESSION_SAME_SITE` | session | optional |
| `SESSION_SECURE_COOKIE` | session | **required** |
| `SESSION_STORE` | session | optional |
| `SESSION_TABLE` | session | optional |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | services | optional |
| `SLACK_BOT_USER_OAUTH_TOKEN` | services | optional |
| `SQS_PREFIX` | queue | optional |
| `SQS_QUEUE` | queue | optional |
| `SQS_SUFFIX` | queue | optional |
| `TRUSTED_PROXIES` | trustedproxy | **required** |

## Scheduled commands (cron: `* * * * * php artisan schedule:run`)

| Command | Cron | Overlap protected | One server |
|---|---|---|---|
| `core:expire-cards` | `15 0 * * *` | yes | yes |
| `core:deliver-webhooks` | `* * * * *` | yes | yes |
| `core:audit-seal --all` | `*/5 * * * *` | yes | yes |
| `core:audit-ship-heads` | `*/5 * * * *` | yes | yes |
| `core:audit-verify` | `30 2 * * *` | yes | yes |
| `core:break-glass-expire` | `* * * * *` | yes | yes |
| `core:prune-integration-data` | `15 3 * * *` | yes | yes |
| `core:notify-expiring` | `*/5 * * * *` | yes | yes |
| `core:data-quality-scan` | `20 * * * *` | yes | yes |
| `core:prune-exports` | `30 3 * * *` | yes | yes |

## Workers

- `php artisan queue:work database --queue=notifications,default --tries=3 --max-time=3600 --memory=256`, at least 2 processes under systemd/supervisor; `queue:restart` on every deploy.
- No Horizon (no Redis). The cache, sessions and queue use the database.
