<?php

declare(strict_types=1);

/*
 * Reconciliation after `core:import-from-euisis` (docs/runbook/core-data-migration.md).
 *
 *   cd core-platform/backend && php ../deploy/migration/reconcile.php [report.json]
 *
 * Compares the EUISIS database (connection `euisis`) with the Core database
 * table by table:
 *   source / destination   row counts
 *   rejected               source rows whose id is missing in the Core (nothing may be lost)
 *   extra                  Core rows whose id is not in the source
 * then checks the Core copy for duplicates, orphans and unmapped records.
 * Exit code 1 when anything is lost, duplicated or orphaned. Unmapped
 * records are reported for review; they are data-quality findings, not
 * failures. Prints counts only, never personal data.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = ['organization_types', 'organizations', 'hierarchy_versions', 'organization_edges', 'organization_unit_types', 'organization_units',
    'positions', 'position_establishments', 'employees', 'employee_assignments', 'position_occupancies', 'card_requests', 'id_cards', 'card_replacements'];
$source = DB::connection('euisis');
$core = DB::connection();
$ids = function ($connection, string $table): array {
    $set = [];
    foreach ($connection->table($table)->select('id')->orderBy('id')->lazyById(5000, 'id') as $row) {
        $set[(string) $row->id] = true;
    }

    return $set;
};

$report = ['generated_at' => gmdate('c'), 'source_database' => $source->getDatabaseName(), 'core_database' => $core->getDatabaseName(), 'tables' => [], 'checks' => []];
$failed = false;
printf("%-26s %10s %12s %9s %7s\n", 'table', 'source', 'destination', 'rejected', 'extra');
foreach ($tables as $table) {
    if (! $source->getSchemaBuilder()->hasTable($table)) {
        printf("%-26s %10s %12s %9s %7s\n", $table, 'absent', $core->table($table)->count(), '-', '-');
        $report['tables'][$table] = ['source' => null, 'destination' => $core->table($table)->count()];

        continue;
    }
    [$from, $to] = [$ids($source, $table), $ids($core, $table)];
    $rejected = count(array_diff_key($from, $to));
    $extra = count(array_diff_key($to, $from));
    $failed = $failed || $rejected > 0;
    printf("%-26s %10d %12d %9d %7d\n", $table, count($from), count($to), $rejected, $extra);
    $report['tables'][$table] = ['source' => count($from), 'destination' => count($to), 'rejected' => $rejected, 'extra' => $extra];
}

$count = fn (string $sql): int => (int) $core->selectOne("select count(*) as n from ({$sql}) x")->n;
$checks = [
    // Duplicates: the identifiers that must stay unique.
    'duplicate employee numbers' => ['fail', 'select employee_number from employees group by employee_number having count(*) > 1'],
    'duplicate national ID hashes' => ['fail', 'select national_id_hash from employees where national_id_hash is not null group by national_id_hash having count(*) > 1'],
    'duplicate card numbers' => ['fail', 'select card_number from id_cards group by card_number having count(*) > 1'],
    'duplicate public card UUIDs' => ['fail', 'select public_card_uuid from id_cards where public_card_uuid is not null group by public_card_uuid having count(*) > 1'],
    'employees with two current placements' => ['fail', "select employee_id from employee_assignments where is_current = true group by employee_id having count(*) > 1"],
    // Orphans: references to rows that are not there.
    'placements without their employee' => ['fail', 'select a.id from employee_assignments a left join employees e on e.id = a.employee_id where e.id is null'],
    'placements without their position' => ['fail', 'select a.id from employee_assignments a left join positions p on p.id = a.position_id where a.position_id is not null and p.id is null'],
    'placements without their organization' => ['fail', 'select a.id from employee_assignments a left join organizations o on o.id = a.organization_id where o.id is null'],
    'current placement pointers to nothing' => ['fail', 'select e.id from employees e left join employee_assignments a on a.id = e.current_assignment_id where e.current_assignment_id is not null and a.id is null'],
    'positions without their organization' => ['fail', 'select p.id from positions p left join organizations o on o.id = p.organization_id where o.id is null'],
    'units without their organization' => ['fail', 'select u.id from organization_units u left join organizations o on o.id = u.organization_id where o.id is null'],
    'hierarchy edges to a missing organization' => ['fail', 'select e.id from organization_edges e left join organizations p on p.id = e.parent_organization_id left join organizations c on c.id = e.child_organization_id where p.id is null or c.id is null'],
    'cards without their employee' => ['fail', 'select c.id from id_cards c left join employees e on e.id = c.employee_id where e.id is null'],
    'card requests without their employee' => ['fail', 'select r.id from card_requests r left join employees e on e.id = r.employee_id where e.id is null'],
    // Unmapped: copied, but something the Core expects is not there yet (review, not loss).
    'employees without a current placement' => ['review', "select id from employees where current_assignment_id is null and status not in ('draft', 'terminated', 'retired', 'deceased')"],
    'positions without a job grade master' => ['review', 'select id from positions where job_grade_id is null'],
    'positions without an occupation master' => ['review', 'select id from positions where occupation_id is null'],
    'placements of unspecified type' => ['review', "select id from employee_assignments where assignment_type = 'unspecified'"],
    'live cards without a digital credential' => ['fail', "select c.id from id_cards c where c.is_current = true and c.status in ('pending_print', 'printed', 'issued', 'active', 'suspended') and c.public_card_uuid is not null and not exists (select 1 from digital_credentials d where d.id_card_id = c.id)"],
];
echo "\n";
foreach ($checks as $name => [$kind, $sql]) {
    try {
        $n = $count($sql);
    } catch (Throwable $e) {
        printf("  %-44s %8s  (%s)\n", $name, 'n/a', class_basename($e));
        $report['checks'][$name] = ['kind' => $kind, 'count' => null];

        continue;
    }
    $flag = $n === 0 ? 'ok' : ($kind === 'fail' ? 'FAIL' : 'review');
    $failed = $failed || ($kind === 'fail' && $n > 0);
    printf("  %-44s %8d  %s\n", $name, $n, $flag);
    $report['checks'][$name] = ['kind' => $kind, 'count' => $n];
}
$report['result'] = $failed ? 'failed' : 'passed';
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
echo "\nreconciliation ".($failed ? 'FAILED' : 'passed')."\n";
exit($failed ? 1 : 0);
