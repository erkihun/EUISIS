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
];

/** Backbone and integration-platform code is Core, not a service module. */
const CORE_OWNED_PATHS = '#^app/(Backbone/|Services/Integration/|Services/Core/|Http/Controllers/Core/|Http/Controllers/Api/)#';

/** @return array<string, int> module => files importing a Golden Path model */
function serviceModuleCoreImports(): array
{
    $root = dirname(__DIR__, 2);
    $counts = array_fill_keys(array_keys(SERVICE_MODULES), 0);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
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
        'cafeteria' => 21,
        // 2026-10-25: route access, organization lookup, eligibility request, the
        // reevaluation job and the scan service read through Core contracts.
        'transport' => 8,
        'grievance' => 15,
        'discipline' => 2,
        'performance' => 16,
        'assessment' => 13,
        'daily_work' => 12,
        'field_work' => 8,
        'recruitment' => 4,
        'transfer' => 15,
        'feedback' => 17,
        'intelligence' => 2,
        'situation_room' => 12,
        'communication' => 12,
        'provider_portal' => 7,
        'organization_design' => 37,
    ];

    $current = serviceModuleCoreImports();
    $grown = array_filter($current, fn (int $count, string $module): bool => $count > $baseline[$module], ARRAY_FILTER_USE_BOTH);
    $shrunk = array_filter($current, fn (int $count, string $module): bool => $count < $baseline[$module], ARRAY_FILTER_USE_BOTH);

    expect($grown)->toBe([], 'A service module gained a direct Golden Path model import. Use a Core contract (app/Backbone/Contracts) or an adapter in the module instead.')
        ->and($shrunk)->toBe([], 'A module dropped direct imports: lower its baseline to the new count.');
});
