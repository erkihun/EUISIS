# Addis One Help — production readiness

## Deployment configuration

- No new tables or migrations; no schema change.
- Settings (System Settings › Assistant › Public site help): `public_enabled`
  (default on), knowledge / service info / support switches (on),
  `public_status_enabled` (off — `NEEDS_POLICY_DECISION_PUBLIC_STATUS`),
  message limit, per-minute and per-day limits, session turns.
- AI narrative for visitors needs `public_content` in Intelligence › "Data
  allowed towards the provider" (off by default) and a configured provider;
  without it the assistant is deterministic ("Limited help") and complete.
- Rate limiter uses the cache store: Redis in production for distributed
  limits (`NEEDS_DEPLOYMENT_CONFIGURATION`).
- Public knowledge: publish guides as `public` documents (Intelligence ›
  Knowledge) and FAQs/services through Public Site Management; nothing is
  public by accident.

## Verification record (2026-10-19, local)

Filled in by the final hand-off (tests, build, audits, browser pass); the
commands run were the standard set:

```
php artisan test tests/Feature/PublicAssistant
php artisan test --filter="Ziggy|AdminUiConsistency|TranslationAudit|SharedBrandingProp|BoundaryHardening|PublicFeedback|PublicSite"
php artisan test --parallel --processes=6 --filter='/^(?!.*PerformanceWorkflowTest)/'
vendor/bin/pint --test  ·  npm run typecheck  ·  npm run build  ·  node scripts/i18n-audit.mjs --strict
php artisan about  ·  php artisan route:list --name=public-assistant  ·  php artisan migrate:status  ·  php artisan migrate --pretend
composer audit --locked  ·  npm audit --omit=dev
```

## P0 / P1 posture

P0 — none open: public-only retrieval in SQL, no internal tool in the
registry, no employee/submission/analytics/diagnostics access, tracking
cannot be bypassed (no lookup exists), no admin links (route registry),
prompt text cannot change capabilities (settings), model output is text,
rate limiting works, AI failure degrades to deterministic blocks, forms
and QR flows are independent of the assistant.

P1 — none known after the local E2E pass.
