# Addis One Production Security Checklist

**Project:** Addis One — Addis Ababa City Administration Addis Ababa Integrated Employee Management & Service System  
**Purpose:** Gate-check before each production deployment. All items must be verified by the DevOps lead and sign-off recorded.

---

## Pre-Deployment Gate

### Application Configuration

- [ ] `APP_ENV=production` is set in the production `.env`
- [ ] `APP_DEBUG=false` is set in the production `.env`
- [ ] `APP_KEY` is a 32-character random string (run `php artisan key:generate` if absent)
- [ ] `APP_URL` is set to the production HTTPS URL (e.g. `https://euisis.addisababa.gov.et`)
- [ ] No development credentials, test API keys, or placeholder values remain in `.env`

### HTTPS & Transport

- [ ] SSL/TLS certificate is valid and not expired (check with `openssl s_client -connect <host>:443`)
- [ ] HTTP traffic is redirected to HTTPS at the Nginx/load-balancer level (HTTP 301 redirect)
- [ ] HSTS header will be sent once the `SecurityHeaders` middleware HSTS line is uncommented
- [ ] `SESSION_SECURE_COOKIE=true` is set in production `.env`

### Session Security

- [ ] `SESSION_ENCRYPT=true` is set in production `.env`
- [ ] `SESSION_DRIVER=database` (not `file` or `cookie`)
- [ ] Session lifetime reviewed for appropriateness (`security.session_timeout_minutes` in system settings)

### Logging

- [ ] `LOG_LEVEL=warning` (not `debug`) in production `.env`
- [ ] `LOG_STACK=daily` in production `.env`
- [ ] `storage/logs/` directory has restricted permissions (mode 640 or tighter)
- [ ] Logs are being forwarded to centralised monitoring

### Security Headers

- [ ] `SecurityHeaders` middleware is registered in `bootstrap/app.php` (done — verify with a browser security header scanner such as securityheaders.com)
- [ ] No `X-Powered-By: PHP` header leaks (disable in `php.ini`: `expose_php = Off`)
- [ ] No `Server: nginx/x.x.x` version disclosure (set `server_tokens off;` in Nginx)

### Caching & Performance

- [ ] `php artisan config:cache` — config is cached
- [ ] `php artisan route:cache` — routes are cached
- [ ] `php artisan event:cache` — events are cached
- [ ] `php artisan view:cache` — views are cached
- [ ] `php artisan storage:link` — storage symlink is created

### Database

- [ ] `DB_CONNECTION=pgsql` (not `sqlite`) in production `.env`
- [ ] Database user has minimal privileges (SELECT/INSERT/UPDATE/DELETE on app tables only; no DROP/CREATE)
- [ ] Database is not publicly exposed (no port 5432 open to the internet)
- [ ] Database password is a strong random value (minimum 24 characters)
- [ ] Database backups are configured and tested (see `docs/backup-and-disaster-recovery.md`)

### Queue & Scheduler

- [ ] Queue workers are running under a process supervisor (systemd or Supervisor)
- [ ] Queue workers will be restarted after deployment (`php artisan queue:restart`)
- [ ] Laravel Scheduler is configured in crontab: `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`
- [ ] Failed jobs are monitored (`php artisan queue:failed`)

### Admin & Access Control

- [ ] Super Admin account count is minimal (maximum 3 accounts)
- [ ] Default/seed passwords have been changed on all admin accounts
- [ ] `MFA_REQUIRED_ROLES` lists every privileged role (default `"Super Admin,City Admin"`)
- [ ] `MFA_ENFORCE=true` (or unset; defaults true outside `APP_ENV=testing`)
- [ ] Every member of an MFA-required role has completed enrolment via `/mfa/setup`
- [ ] `REGISTRATION_ENABLED=false` in production `.env` — public self-service registration is off
- [ ] All admin accounts use organisation-specific email addresses (not generic providers)

### Sensitive Data Encryption (PII)

- [ ] `users.national_id` and `users.phone_number` casts are `encrypted` (see `app/Models/User.php`)
- [ ] `employees.national_id` cast is `encrypted` (see `app/Models/Employee.php`)
- [ ] Migration `2026_05_25_000001_add_hash_columns_to_users_and_employees` has been run
- [ ] After cutover, run `php artisan sensitive-data:encrypt-existing` (dry-run first)
- [ ] `APP_KEY` is backed up to the secrets manager (loss == data loss)

### Sanctum / API tokens

- [ ] `SANCTUM_TOKEN_EXPIRATION_MINUTES` is set (default 43200 = 30 days). Lower in stricter envs.

### Dependency Security

- [ ] `composer audit` run with no HIGH or CRITICAL findings
- [ ] `npm audit --audit-level=moderate` run with no unresolved findings
- [ ] `composer install --no-dev --optimize-autoloader` used for production build
- [ ] `npm run build` produces a clean production build without errors

### Storage & File Access

- [ ] `storage/` and `bootstrap/cache/` are writable by the web server user only
- [ ] `.env` file is not accessible via the web (Nginx must block access to dot-files)
- [ ] `public/` is the only web-accessible directory
- [ ] Employee photos served from private disk with authenticated access (currently using public storage — flag if not yet migrated)

### Audit Logs

- [ ] Audit logging is active and writing to the database
- [ ] `audit_logs` table is not truncated or writable by the application database user (consider write-only pattern)
- [ ] A process exists for reviewing audit logs weekly

### Secrets Management

- [ ] No secrets committed to the git repository (run `git log --all --oneline | head -20` and verify no `.env` files)
- [ ] `.env` is in `.gitignore`
- [ ] AWS credentials (if used) are IAM-scoped with minimum permissions
- [ ] Mail credentials are valid and not test values

### Incident Response

- [ ] Incident response contacts are documented (see `docs/infrastructure-security.md`)
- [ ] On-call runbook is accessible to the operations team
- [ ] Alert channels (email/Slack/Telegram) are configured and tested via `system-settings/test-email`

---

## Post-Deployment Verification

- [ ] Login flow works end-to-end in production
- [ ] Rate limiting triggers correctly (test with 6 rapid login failures)
- [ ] A non-admin user cannot access admin routes (test with a role-restricted account)
- [ ] API `/api/v1/cards/verify` returns 401 without a valid Sanctum token
- [ ] Security headers are present in HTTP responses (verify with browser DevTools or `curl -I https://<host>/`)
- [ ] No stack traces visible in error pages (trigger a 404 and a 403)
- [ ] Audit log entries are written for login, logout, and card actions
- [ ] Backup restore procedure tested within the last 30 days

---

## Sign-off

| Role | Name | Date | Signature |
|---|---|---|---|
| DevOps Lead | | | |
| Security Lead | | | |
| Project Manager | | | |
