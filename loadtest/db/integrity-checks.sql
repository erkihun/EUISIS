-- Post-load integrity checks (docs/production-readiness/load-test-results.md).
-- Read-only. Every query must return 0; any other number is a P0 defect.
--   psql -h 127.0.0.1 -U <user> -d <benchmark-db> -f loadtest/db/integrity-checks.sql

\pset footer off
\echo 'Each check below must be 0.'

SELECT 'duplicate current assignment per employee' AS check, count(*) AS violations
FROM (SELECT employee_id FROM employee_assignments WHERE is_current GROUP BY employee_id HAVING count(*) > 1) d
UNION ALL
SELECT 'current assignment pointing at a missing employee', count(*)
FROM employee_assignments a WHERE a.is_current AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.id = a.employee_id)
UNION ALL
SELECT 'assignment pointing at a missing position', count(*)
FROM employee_assignments a WHERE a.position_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.id = a.position_id)
UNION ALL
SELECT 'assignment pointing at a missing organization', count(*)
FROM employee_assignments a WHERE NOT EXISTS (SELECT 1 FROM organizations o WHERE o.id = a.organization_id)
UNION ALL
SELECT 'employee.current_assignment_id not the current assignment', count(*)
FROM employees e JOIN employee_assignments a ON a.id = e.current_assignment_id
WHERE a.employee_id <> e.id OR NOT a.is_current
UNION ALL
SELECT 'position with more than one current incumbent', count(*)
FROM (SELECT position_id FROM employee_assignments WHERE is_current AND position_id IS NOT NULL GROUP BY position_id HAVING count(*) > 1) d
UNION ALL
SELECT 'more than one live current card per employee', count(*)
FROM (SELECT employee_id FROM id_cards WHERE is_current AND status IN ('pending_print','printed','issued','active')
      GROUP BY employee_id HAVING count(*) > 1) d
UNION ALL
SELECT 'duplicate public card uuid', count(*)
FROM (SELECT public_card_uuid FROM id_cards WHERE public_card_uuid IS NOT NULL GROUP BY public_card_uuid HAVING count(*) > 1) d
UNION ALL
SELECT 'duplicate employee number', count(*)
FROM (SELECT employee_number FROM employees GROUP BY employee_number HAVING count(*) > 1) d
UNION ALL
SELECT 'duplicate idempotency key per principal and route', count(*)
FROM (SELECT principal_type, principal_id, route, idempotency_key FROM api_idempotency_keys
      GROUP BY 1, 2, 3, 4 HAVING count(*) > 1) d
UNION ALL
SELECT 'duplicate service transaction reference', count(*)
FROM (SELECT reference FROM service_transactions WHERE reference IS NOT NULL GROUP BY reference HAVING count(*) > 1) d
UNION ALL
SELECT 'integration event with a duplicate aggregate sequence', count(*)
FROM (SELECT aggregate_type, aggregate_uuid, sequence FROM integration_events GROUP BY 1, 2, 3 HAVING count(*) > 1) d
UNION ALL
SELECT 'published integration event without a feed position', count(*)
FROM integration_events WHERE status = 'published' AND feed_position IS NULL
UNION ALL
SELECT 'gap in the change-feed positions', coalesce(max(feed_position) - min(feed_position) + 1 - count(*), 0)
FROM integration_events WHERE feed_position IS NOT NULL;
