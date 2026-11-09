-- Give synthetic employees (SC-<n>) the varied names scale:generate now writes
-- (GenerateScaleData::syntheticName: about one distinct name per person, an
-- English name_en and the same name in Ge'ez in full_name, as on the live
-- register). For benchmark databases generated with the old 16-name pattern,
-- where every name search matched 1/16 of the table. Synthetic rows only.
--   psql -d <benchmark-db> -v ON_ERROR_STOP=1 -f loadtest/db/realistic-names.sql
-- The trigram/btree name indexes are dropped and rebuilt around the rewrite
-- (much faster than updating them row by row); \timing reports each step.

\timing on
DROP INDEX IF EXISTS employees_full_name_trgm_idx, employees_name_en_trgm_idx, employees_full_name_index, employees_name_en_index;

WITH s AS (
  SELECT ARRAY['a','be','ke','de','ma','ra','ta','se','la','mi','na','ha','ge','te','fi','wo','zu','yo','bi','sa'] AS lat,
         ARRAY['አ','በ','ከ','ደ','ማ','ራ','ታ','ሰ','ላ','ሚ','ና','ሃ','ገ','ተ','ፊ','ዎ','ዙ','ዮ','ቢ','ሳ'] AS am
), d AS (
  SELECT e.id,
         ARRAY(SELECT ((h.h / (20::bigint ^ k)::bigint) % 20)::int + 1 FROM generate_series(0, 6) k ORDER BY k) AS x
  FROM employees e
  CROSS JOIN LATERAL (SELECT (substring(e.employee_number FROM 4)::bigint * 2654435761) % 4294967296 AS h) h
  WHERE e.employee_number ~ '^SC-[0-9]+$'
)
UPDATE employees e SET
  first_name = initcap(s.lat[d.x[1]] || s.lat[d.x[2]]),
  last_name  = initcap(s.lat[d.x[3]] || s.lat[d.x[4]]),
  name_en    = initcap(s.lat[d.x[1]] || s.lat[d.x[2]]) || ' ' || initcap(s.lat[d.x[3]] || s.lat[d.x[4]]) || ' '
               || initcap(s.lat[d.x[5]] || s.lat[d.x[6]] || s.lat[d.x[7]]),
  full_name  = s.am[d.x[1]] || s.am[d.x[2]] || ' ' || s.am[d.x[3]] || s.am[d.x[4]] || ' ' || s.am[d.x[5]] || s.am[d.x[6]] || s.am[d.x[7]]
FROM d, s
WHERE e.id = d.id;

CREATE INDEX employees_full_name_index ON employees (full_name);
CREATE INDEX employees_name_en_index ON employees (name_en);
CREATE INDEX employees_full_name_trgm_idx ON employees USING gin (full_name gin_trgm_ops);
CREATE INDEX employees_name_en_trgm_idx ON employees USING gin (name_en gin_trgm_ops);
VACUUM ANALYZE employees;
