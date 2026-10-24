<?php

declare(strict_types=1);

/*
 * Architecture drift checks (docs/governance/platform-governance.md), beside
 * BackboneBoundaryTest, ServiceModuleCoreDependencyTest and
 * MachineWriteBoundaryTest:
 *
 *   1. Situation Room, Intelligence and the assistants read the Golden Path
 *      but never change it: no backbone command or write action imported, no
 *      raw table write, no save on a master record.
 *   2. Organization-scope logic lives in OrganizationScopeService; code that
 *      reads the scope table directly is a known, listed exception.
 *   3. Partner API controllers never hand a master record straight to the
 *      response; they answer through resources or explicit arrays.
 *
 * Allowlists may only shrink: a new violation fails, and so does an
 * allowlisted file that no longer matches.
 */

/** @return list<string> repository-relative PHP paths under app/ */
function governanceAppFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($files);

    return $files;
}

it('keeps Situation Room, Intelligence and the assistants read-only on the Golden Path', function (): void {
    $root = dirname(__DIR__, 2);
    $readOnly = '#^app/(Services|Http/Controllers|Jobs|Console/Commands)/.*(SituationRoom|Intelligence|Assistant)#';
    $writer = '#^App\\\\(Backbone\\\\Commands|Actions\\\\(Organizations|OrganizationUnits|OrganizationTypes|OrganizationUnitTypes|Positions|Employees|EmployeeAssignments|IdCards|Transfers|OrganizationalChange|Hierarchy))\\\\#';
    $tableWrite = "#DB::table\\(['\"](organizations|organization_units|positions|employees|employee_assignments|id_cards|position_occupancies)['\"]\\).*->(insert|update|delete|upsert|increment|decrement|updateOrInsert)\\(#";
    $recordWrite = '#\$(employee|organization|unit|position|assignment|card|idCard)->(save|update|delete|forceDelete|forceFill|fill)\(#';
    $found = [];

    foreach (governanceAppFiles() as $path) {
        if (! preg_match($readOnly, $path)) {
            continue;
        }
        $source = (string) file_get_contents($root.'/'.$path);
        preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+);/m', $source, $m);
        foreach ($m[1] as $class) {
            if (preg_match($writer, $class)) {
                $found[] = $path.' -> '.$class;
            }
        }
        foreach (preg_split('/\R/', $source) as $number => $line) {
            if (preg_match($tableWrite, $line) || preg_match($recordWrite, $line)) {
                $found[] = $path.':'.($number + 1);
            }
        }
    }

    expect($found)->toBe([], 'Analytics and AI may suggest, never change master data: route the change through an authorized Core workflow (platform-governance.md).');
});

it('keeps organization-scope logic in OrganizationScopeService', function (): void {
    $root = dirname(__DIR__, 2);
    $found = [];
    foreach (governanceAppFiles() as $path) {
        if (str_starts_with($path, 'app/Models/')) {
            continue;
        }
        if (preg_match("#['\"]user_organization_scopes['\"]|UserOrganizationScope::#", (string) file_get_contents($root.'/'.$path))) {
            $found[] = $path;
        }
    }

    $allowed = [
        // Scope administration itself.
        'app/Http/Controllers/Web/UserOrganizationScopeController.php',
        'app/Http/Requests/UserOrganizationScopes/StoreUserOrganizationScopeRequest.php',
        'app/Providers/AppServiceProvider.php',
        // Read-only reports and impact checks that count grants, not decide access.
        'app/Console/Commands/Audit/AuditDuplicateData.php',
        'app/Services/OrganizationalChange/ChangeRequestImpactAnalyzer.php',
        'app/Services/Organizations/OrganizationDeletionGuard.php',
        'app/Services/Organizations/OrganizationStatisticsService.php',
        // Development load-test generators, never run in production.
        'app/Console/Commands/Scale/ExportLoadTestData.php',
        'app/Console/Commands/Scale/GenerateScaleData.php',
        'app/Console/Commands/Scale/SeedPublicFeedbackScale.php',
    ];

    expect(array_values(array_diff($found, $allowed)))->toBe([], 'New direct use of the organization-scope table. Ask OrganizationScopeService instead of re-implementing scope (ADR 0003).')
        ->and(array_values(array_diff($allowed, $found)))->toBe([], 'This exception is gone: remove it from the allowlist.');
});

it('keeps partner API controllers from returning master records directly', function (): void {
    $root = dirname(__DIR__, 2);
    $raw = '#(return|->json\()\s*\$(employee|organization|unit|position|assignment|card|idCard|user)(->toArray\(\))?\s*[;,)]#';
    $found = [];
    foreach (governanceAppFiles() as $path) {
        if (! str_starts_with($path, 'app/Http/Controllers/Api/')) {
            continue;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($root.'/'.$path)) as $number => $line) {
            if (preg_match($raw, $line)) {
                $found[] = $path.':'.($number + 1);
            }
        }
    }

    expect($found)->toBe([], 'A master record goes to a partner unfiltered. Answer through an API resource with only the contracted fields (api-lifecycle.md).');
});
