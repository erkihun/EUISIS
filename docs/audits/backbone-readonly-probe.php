<?php
// Audit helper: aggregate metadata only; transaction forbids database writes.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'pgsql') {
    throw new RuntimeException('This probe requires PostgreSQL.');
}
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $db->statement("SET LOCAL statement_timeout = '10s'");
    $queries = [
        'engine' => 'SHOW server_version',
        'counts' => 'SELECT (SELECT count(*) FROM employees) employees, (SELECT count(*) FROM organizations) organizations, (SELECT count(*) FROM organization_units) units, (SELECT count(*) FROM positions) positions, (SELECT count(*) FROM employee_assignments) assignments, (SELECT count(*) FROM id_cards) cards',
        'invariants' => "SELECT (SELECT count(*) FROM (SELECT employee_id FROM employee_assignments WHERE is_current GROUP BY employee_id HAVING count(*) > 1) a) duplicate_current_assignments, (SELECT count(*) FROM employees e LEFT JOIN employee_assignments a ON a.id=e.current_assignment_id WHERE e.current_assignment_id IS NOT NULL AND (a.id IS NULL OR a.employee_id<>e.id OR NOT a.is_current)) invalid_assignment_pointers, (SELECT count(*) FROM employee_assignments a JOIN employees e ON e.id=a.employee_id WHERE a.is_current AND e.current_assignment_id IS DISTINCT FROM a.id) unpointed_current_assignments",
        'indexes' => "SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname=current_schema() AND tablename IN ('employees','employee_assignments','id_cards') ORDER BY tablename,indexname",
        'uuid_columns' => "SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name IN ('employees','organizations','organization_units','positions','employee_assignments','id_cards') AND column_name IN ('id','public_card_uuid') ORDER BY table_name,column_name",
        'directory_plan' => "EXPLAIN SELECT id,employee_number FROM employees WHERE updated_at >= CURRENT_DATE - INTERVAL '1 day' ORDER BY employee_number LIMIT 100",
        'identity_plan' => "EXPLAIN SELECT id FROM employees WHERE id='00000000-0000-0000-0000-000000000000'::uuid",
    ];
    $result = ['captured_at' => now()->toIso8601String()];
    foreach ($queries as $key => $sql) {
        $result[$key] = $db->select($sql);
    }
    foreach (['database.default','cache.default','session.driver','queue.default','filesystems.disks.local.driver','filesystems.disks.public.driver','app.timezone'] as $key) {
        $result['runtime'][$key] = config($key);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $db->rollBack();
}
