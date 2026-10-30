<?php

declare(strict_types=1);

/*
 * Stage 3 of the Core migration (docs/architecture/module-integration-matrix.md):
 * how many files in each service module still import a Golden Path model
 * directly instead of a Core contract. The numbers are a ratchet: a module may
 * only go down. Adding a direct import fails here; removing one fails too,
 * until the baseline below is lowered to the new count.
 *
 * Reads through models inside the same deployment are allowed for now
 * (they are frozen, not forbidden); writes are already refused by
 * BackboneBoundaryTest.
 */

const CORE_MODEL_IMPORT = '/^use App\\\\Models\\\\(Organization|OrganizationUnit|Position|PositionOccupancy|Employee|EmployeeAssignment|IdCard|CardRequest|HierarchyVersion|OrganizationClosurePath);$/m';

/** Module => path pattern under app/ (case-insensitive). */
const SERVICE_MODULES = [
    'cafeteria' => '#Cafeteria#i',
    'transport' => '#Transport#i',
    'grievance' => '#Grievance#i',
    'discipline' => '#Disciplin#i',
    'performance' => '#(Performance|Kpi|StrategicGoal)#i',
    'assessment' => '#Assessment#i',
    'daily_work' => '#DailyActivit#i',
    'field_work' => '#FieldWork#i',
    'recruitment' => '#Vacanc#i',
    'transfer' => '#Transfer#i',
    'feedback' => '#(PublicFeedback|ServiceFeedback)#i',
    'intelligence' => '#(Intelligence|Assistant)#i',
    'situation_room' => '#SituationRoom#i',
    'communication' => '#Communication#i',
    'provider_portal' => '#(ProviderPortal|Nfc)#i',
    'organization_design' => '#OrganizationDesign#i',
    'service_monitoring' => '#ServiceMonitoring#i',
];

/** Backbone and integration-platform code is Core, not a service module. */
const CORE_OWNED_PATHS = '#^app/(Backbone/|Services/Integration/|Services/Core/|Http/Controllers/Core/|Http/Controllers/Api/)#';

/** @return array<string, int> module => files importing a Golden Path model */
function serviceModuleCoreImports(): array
{
    $root = dirname(__DIR__, 2);
    $counts = array_fill_keys(array_keys(SERVICE_MODULES), 0);
    // Modules moved into Modules/ (ADR 0016) still count against their baseline.
    $files = new AppendIterator;
    foreach (['app', 'Modules'] as $dir) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)));
    }
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match(CORE_OWNED_PATHS, $path)) {
            continue;
        }
        foreach (SERVICE_MODULES as $module => $pattern) {
            if (preg_match($pattern, $path)) {
                if (preg_match(CORE_MODEL_IMPORT, (string) file_get_contents($file->getPathname()))) {
                    $counts[$module]++;
                }
                break;
            }
        }
    }

    return $counts;
}

it('lets no service module add a direct dependency on Golden Path models', function (): void {
    // Baseline 2026-10-24. Lower a number when a module moves onto Core contracts.
    $baseline = [
        // 2026-10-30: 39, not new coupling. The move to Modules/Cafeteria gave 15 models
        // `use` lines for Core relations they always had (they shared App\Models), and two
        // classes took over code that already read Core models in non-module files (My
        // Portal's cafeteria card, the NFC terminal scan). The provider-portal screens now
        // count here instead of under provider_portal.
        'cafeteria' => 39,
        // 2026-10-25: route access, organization lookup, eligibility request, the
        // reevaluation job and the scan service read through Core contracts.
        // 2026-10-30: 15, not new coupling: the move to Modules/Transport gave its
        // seven models (pass, route, contract, eligibility, exception, settlement,
        // transaction) `use App\Models\Employee/Organization` lines for relations
        // they always had; in App\Models they shared the namespace and were not counted.
        'transport' => 15,
        // 2026-10-30: 40 and 4, not new coupling: the moves into Modules/ gave the grievance
        // and discipline models `use App\Models\Employee/Organization/...` lines for relations
        // they always had (119 and 14 such lines in the move commits).
        'grievance' => 40,
        'discipline' => 4,
        // 2026-10-31: 30, not new coupling. The move to Modules/Performance gave 12 models
        // `use App\Models\Employee/Organization/...` lines for relations they always had (in
        // App\Models they shared the namespace), and the EmployeePerformance contract with its
        // implementation takes the EmployeeAssignment rows the transfer already passed to
        // the agreement service.
        'performance' => 30,
        // 2026-10-30: 19, 14 and 10, not new coupling: the moves into Modules/ gave the
        // modules' models `use App\Models\Employee/Organization/...` lines for relations
        // they always had; in App\Models they shared the namespace and were not counted.
        'assessment' => 19,
        'daily_work' => 14,
        'field_work' => 10,
        'recruitment' => 4,
        'transfer' => 15,
        'feedback' => 17,
        'intelligence' => 2,
        'situation_room' => 12,
        'communication' => 12,
        // 2026-10-30: 6; the cafeteria portal screens moved to Modules/Cafeteria and count there.
        'provider_portal' => 6,
        'organization_design' => 37,
        // 2026-10-30: 11 for the new module (ADR 0018). People, the structure and Discipline go
        // through contracts; what is left are Organization relations on its models (names on
        // every report row), the HR-count prefill and the employee picker. Lower as Core
        // publishes an organization read model and a workforce-count contract.
        'service_monitoring' => 11,
    ];

    $current = serviceModuleCoreImports();
    $grown = array_filter($current, fn (int $count, string $module): bool => $count > $baseline[$module], ARRAY_FILTER_USE_BOTH);
    $shrunk = array_filter($current, fn (int $count, string $module): bool => $count < $baseline[$module], ARRAY_FILTER_USE_BOTH);

    expect($grown)->toBe([], 'A service module gained a direct Golden Path model import. Use a Core contract (app/Backbone/Contracts) or an adapter in the module instead.')
        ->and($shrunk)->toBe([], 'A module dropped direct imports: lower its baseline to the new count.');
});
