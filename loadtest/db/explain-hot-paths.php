<?php

declare(strict_types=1);

/*
 * Hot-path query benchmark (docs/production-readiness/database-capacity.md).
 *
 * Runs EXPLAIN (ANALYZE, BUFFERS) for the queries the API and web hot paths
 * issue, each with RUNS different randomly sampled keys, and prints one line
 * per query: median and max execution time, the scan types in the plan, rows
 * returned and shared buffers hit/read. EXPLAIN ANALYZE executes the query, so
 * it runs only read queries, inside a transaction that is rolled back.
 *
 *   DB_DATABASE=aaemployeedb_scale3m php loadtest/db/explain-hot-paths.php [runs] [only-name-substring]
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production') || DB::getDriverName() !== 'pgsql') {
    fwrite(STDERR, "Benchmark PostgreSQL databases only.\n");
    exit(1);
}

$runs = (int) ($argv[1] ?? 5);
$only = $argv[2] ?? null;
$pct = max(0.001, min(100, 3000 / max(1, (int) DB::selectOne('select reltuples::bigint n from pg_class where relname = ?', ['employees'])->n) * 100));

// Random synthetic keys spread over the table (TABLESAMPLE reads a few pages, not the table).
$sample = DB::select("
    SELECT e.id AS employee_id, e.employee_number, e.name_en, e.full_name, e.current_assignment_id, a.organization_id, a.organization_unit_id, a.position_id,
           c.id AS card_id, c.public_card_uuid, c.token_hash
    FROM employees e TABLESAMPLE SYSTEM ({$pct})
    JOIN employee_assignments a ON a.id = e.current_assignment_id
    JOIN id_cards c ON c.employee_id = e.id AND c.is_current
    LIMIT 500");
if ($sample === []) {
    fwrite(STDERR, "No sample rows.\n");
    exit(1);
}
$serviceType = DB::table('service_types')->where('code', 'cafeteria')->value('id');
$deepNumber = DB::selectOne('SELECT employee_number FROM employees ORDER BY employee_number OFFSET 1500000 LIMIT 1')?->employee_number ?? 'SC-1';
$today = now()->toDateString();

$q = [
    'employee by uuid (PK)' => fn ($s) => ['SELECT * FROM employees WHERE id = ? LIMIT 1', [$s->employee_id]],
    'employee by number (exact, unique btree)' => fn ($s) => ['SELECT * FROM employees WHERE employee_number = ? LIMIT 1', [$s->employee_number]],
    'employee by number (API ILIKE filter + COUNT)' => fn ($s) => ['SELECT count(*) FROM employees WHERE employee_number ILIKE ?', [$s->employee_number]],
    'employee by number (API lower() equality + COUNT)' => fn ($s) => ['SELECT count(*) FROM employees WHERE lower(employee_number) = lower(?)', [strtolower($s->employee_number)]],
    'web search number prefix (lower() LIKE SC-12345%)' => fn ($s) => ["SELECT count(*) FROM employees WHERE lower(employee_number) LIKE lower(?) ESCAPE '!'", [substr($s->employee_number, 0, 8).'%']],
    'web search name, new form (full_name OR name_en)' => fn ($s) => ["SELECT count(*) FROM employees WHERE full_name ILIKE ? ESCAPE '!' OR name_en ILIKE ? ESCAPE '!'", array_fill(0, 2, '%'.nameTerm($s->name_en).'%')],
    'web search e-mail (trigram)' => fn ($s) => ["SELECT count(*) FROM employees WHERE email ILIKE ? ESCAPE '!'", ['%sc'.substr($s->employee_number, 3).'@scale%']],
    'card by public uuid' => fn ($s) => ['SELECT * FROM id_cards WHERE public_card_uuid = ? LIMIT 1', [$s->public_card_uuid]],
    'card by id + token hash' => fn ($s) => ['SELECT * FROM id_cards WHERE id = ? AND token_hash = ? LIMIT 1', [$s->card_id, $s->token_hash]],
    'active card of employee' => fn ($s) => ["SELECT * FROM id_cards WHERE employee_id = ? AND status = 'active' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at::date >= ?) ORDER BY activated_at DESC, created_at DESC LIMIT 1", [$s->employee_id, $today]],
    'current assignment by employee' => fn ($s) => ['SELECT * FROM employee_assignments WHERE employee_id = ? AND is_current LIMIT 1', [$s->employee_id]],
    'entitlement in force (Entitlement::inForceFor)' => fn ($s) => ["SELECT * FROM entitlements WHERE employee_id = ? AND service_type_id = ? AND status = 'active' AND (effective_from IS NULL OR effective_from::date <= ?) AND (effective_to IS NULL OR effective_to::date >= ?) AND (service_provider_id IS NULL) ORDER BY CASE WHEN service_provider_id IS NULL THEN 1 ELSE 0 END, effective_from DESC LIMIT 1", [$s->employee_id, $serviceType, $today, $today]],
    'organization resolution (assignment -> org, unit)' => fn ($s) => ['SELECT o.id, o.code, u.id, u.code FROM employee_assignments a JOIN organizations o ON o.id = a.organization_id LEFT JOIN organization_units u ON u.id = a.organization_unit_id WHERE a.id = ?', [$s->current_assignment_id]],
    'position occupancy (current incumbents)' => fn ($s) => ["SELECT count(*) FROM employee_assignments WHERE position_id = ? AND is_current AND assignment_status = 'active'", [$s->position_id]],
    'units of an organization (page + COUNT)' => fn ($s) => ['SELECT (SELECT count(*) FROM organization_units WHERE organization_id = ?) AS total, * FROM organization_units WHERE organization_id = ? ORDER BY code LIMIT 25', [$s->organization_id, $s->organization_id]],
    'employees of an organization (current, page)' => fn ($s) => ['SELECT e.id, e.employee_number FROM employees e WHERE EXISTS (SELECT 1 FROM employee_assignments a WHERE a.id = e.current_assignment_id AND a.organization_id = ?) ORDER BY e.employee_number LIMIT 25', [$s->organization_id]],
    'employees of an organization (COUNT for paginate)' => fn ($s) => ['SELECT count(*) FROM employees e WHERE EXISTS (SELECT 1 FROM employee_assignments a WHERE a.id = e.current_assignment_id AND a.organization_id = ?)', [$s->organization_id]],
    'city employee list COUNT (API paginate, status=active)' => fn ($s) => ["SELECT count(*) FROM employees WHERE status = 'active'", []],
    'city employee list page 1 (ORDER BY number LIMIT 25)' => fn ($s) => ["SELECT id, employee_number, full_name FROM employees WHERE status = 'active' ORDER BY employee_number LIMIT 25", []],
    'city employee list deep OFFSET 1,500,000' => fn ($s) => ["SELECT id, employee_number, full_name FROM employees WHERE status = 'active' ORDER BY employee_number LIMIT 25 OFFSET 1500000", []],
    'city employee list deep KEYSET (number > cursor)' => fn ($s) => ["SELECT id, employee_number, full_name FROM employees WHERE status = 'active' AND employee_number > ? ORDER BY employee_number LIMIT 25", [$deepNumber]],
    'web search name %term% (trigram) + email/phone OR' => fn ($s) => ['SELECT count(*) FROM employees WHERE employee_number ILIKE ? OR full_name ILIKE ? OR name_en ILIKE ? OR email ILIKE ? OR phone ILIKE ?', array_fill(0, 5, '%'.nameTerm($s->name_en).'%')],
    'web search name %term% (trigram columns only)' => fn ($s) => ['SELECT count(*) FROM employees WHERE employee_number ILIKE ? OR full_name ILIKE ? OR name_en ILIKE ?', array_fill(0, 3, '%'.nameTerm($s->name_en).'%')],
    'web search page 1 (trigram columns, ORDER BY full_name, id)' => fn ($s) => ['SELECT id, employee_number, full_name FROM employees WHERE employee_number ILIKE ? OR full_name ILIKE ? OR name_en ILIKE ? ORDER BY full_name, id LIMIT 50', array_fill(0, 3, '%'.nameTerm($s->name_en).'%')],
    'web search given name only (%given%)' => fn ($s) => ['SELECT count(*) FROM employees WHERE employee_number ILIKE ? OR full_name ILIKE ? OR name_en ILIKE ?', array_fill(0, 3, '%'.strtok($s->name_en, ' ').'%')],
    'web search by exact employee number (%SC-1234567%)' => fn ($s) => ['SELECT count(*) FROM employees WHERE employee_number ILIKE ? OR full_name ILIKE ? OR name_en ILIKE ?', array_fill(0, 3, '%'.$s->employee_number.'%')],
    'Amharic name search %given father% (trigram)' => fn ($s) => ['SELECT count(*) FROM employees WHERE full_name ILIKE ?', ['%'.nameTerm($s->full_name).'%']],
    'change feed page (after cursor, city)' => fn ($s) => ['SELECT * FROM integration_events WHERE feed_position IS NOT NULL AND feed_position > ? AND feed_position <= (SELECT coalesce(max(feed_position), 0) FROM integration_events) ORDER BY feed_position LIMIT 101', [random_int(0, max(0, (int) DB::table('integration_events')->max('feed_position') - 100))]],
    'change feed page (after cursor, one organization)' => fn ($s) => ['SELECT * FROM integration_events WHERE feed_position IS NOT NULL AND feed_position > ? AND (is_global OR organization_id = ? OR related_organization_id = ?) ORDER BY feed_position LIMIT 101', [0, $s->organization_id, $s->organization_id]],
    'outbox pending claim (ORDER BY id LIMIT 500)' => fn ($s) => ["SELECT * FROM integration_events WHERE status = 'pending' AND available_at <= now() ORDER BY id LIMIT 500", []],
    'service transactions of an employee (FK, no index?)' => fn ($s) => ['SELECT * FROM service_transactions WHERE employee_id = ? ORDER BY occurred_at DESC LIMIT 20', [$s->employee_id]],
    'card verifications of a card (FK, no index?)' => fn ($s) => ['SELECT count(*) FROM card_verifications WHERE id_card_id = ?', [$s->card_id]],
    'Situation Room: current headcount by organization' => fn ($s) => ["SELECT a.organization_id, count(*) FROM employee_assignments a JOIN employees e ON e.id = a.employee_id WHERE a.is_current AND e.status = 'active' GROUP BY a.organization_id", []],
    'Situation Room: occupants by position (citywide subquery)' => fn ($s) => ["SELECT position_id, count(*) FROM employee_assignments WHERE is_current AND assignment_status = 'active' GROUP BY position_id", []],
];

/** The first two words of a name: what a person types into the search box. */
function nameTerm(string $name): string
{
    return implode(' ', array_slice(explode(' ', $name), 0, 2));
}

$scanTypes = function (array $node) use (&$scanTypes): array {
    $types = [];
    if (str_contains($node['Node Type'], 'Scan')) {
        $types[] = $node['Node Type'].(isset($node['Index Name']) ? "({$node['Index Name']})" : (isset($node['Relation Name']) ? "({$node['Relation Name']})" : ''));
    }
    if ($node['Node Type'] === 'Sort') {
        $types[] = 'Sort('.($node['Sort Method'] ?? '?').')';
    }
    foreach ($node['Plans'] ?? [] as $child) {
        $types = array_merge($types, $scanTypes($child));
    }

    return $types;
};

printf("%-58s %9s %9s %7s %8s %8s  %s\n", 'query', 'median_ms', 'max_ms', 'rows', 'hit', 'read', 'plan');
foreach ($q as $name => $build) {
    if ($only !== null && ! str_contains($name, $only)) {
        continue;
    }
    $times = [];
    $last = null;
    for ($i = 0; $i < $runs; $i++) {
        [$sql, $bindings] = $build($sample[array_rand($sample)]);
        DB::beginTransaction();
        try {
            $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)->{'QUERY PLAN'}, true)[0];
        } finally {
            DB::rollBack();
        }
        $times[] = $plan['Execution Time'] + $plan['Planning Time'];
        $last = $plan;
    }
    sort($times);
    $top = $last['Plan'];
    printf("%-58s %9.2f %9.2f %7d %8d %8d  %s\n", mb_strimwidth($name, 0, 58), $times[intdiv(count($times), 2)], max($times),
        $top['Actual Rows'], $top['Shared Hit Blocks'] ?? 0, $top['Shared Read Blocks'] ?? 0, implode(' > ', array_unique($scanTypes($top))));
}
