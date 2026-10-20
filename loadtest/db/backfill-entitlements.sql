-- One active cafeteria entitlement per synthetic employee that has none, for a
-- benchmark database built by scale:generate before it created entitlements.
-- Synthetic rows only (SC- employee numbers). Never run against production.
--   psql -d <benchmark-db> -f loadtest/db/backfill-entitlements.sql

INSERT INTO entitlements (id, employee_id, service_type_id, status, quota_used, effective_from, created_at, updated_at)
SELECT gen_random_uuid(), e.id, st.id, 'active', 0, DATE '2024-01-01', now(), now()
FROM employees e
CROSS JOIN (SELECT id FROM service_types WHERE code = 'cafeteria' LIMIT 1) st
WHERE e.employee_number LIKE 'SC-%'
  AND NOT EXISTS (SELECT 1 FROM entitlements x WHERE x.employee_id = e.id AND x.service_type_id = st.id);

ANALYZE entitlements;
