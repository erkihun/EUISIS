<?php

declare(strict_types=1);

use App\Support\Database\ReadOnlyModel;
use App\Support\Retention\RetentionMatrix;
use Illuminate\Database\Eloquent\Model;
use Modules\Cafeteria\Contracts\Data\TransactionStatus;
use Modules\Cafeteria\Domain\Enums\CafeteriaTransactionStatus;
use Modules\Transport\Contracts\Data\TripStatus;
use Modules\Transport\Domain\Enums\TransportTripStatus;

/*
 * A module's public API is its Contracts namespace (ADR 0017):
 *
 * - Code outside a module (app/ and other modules) uses only its Contracts.
 *   Its service provider (Modules/<Name>/Providers) is the only place that
 *   wires its internals into the application; route files name its
 *   controllers.
 * - Contracts are interfaces plus immutable data (Contracts\Data) and expose
 *   no Eloquent model, builder, response, form request or internal class.
 * - Every contract is bound to an implementation.
 * - Only the owner touches its tables; the few read-only exceptions are
 *   listed below with their reason, and may not write.
 * - Class names from before the move are kept for stored data only; no source
 *   file may use them to reach a module's internals.
 * - Modules do not depend on each other in a cycle.
 */

$root = dirname(__DIR__, 3);
$modules = array_map('basename', glob($root.'/Modules/*', GLOB_ONLYDIR) ?: []);

/*
 * Development-only tooling that seeds a module's tables directly. The
 * production build leaves these paths out (scripts/release/build-production-branch.sh).
 */
const MODULE_DEV_TOOLING = [
    'App\\Console\\Commands\\Transport\\SeedTransportLoadTest' => 'app/Console/Commands/Transport/SeedTransportLoadTest.php',
    'App\\Support\\Demo\\DemoDataset' => 'app/Support/Demo/DemoDataset.php',
    'App\\Support\\Demo\\DemoDataValidator' => 'app/Support/Demo/DemoDataValidator.php',
    'App\\Console\\Commands\\Scale\\GenerateScaleData' => 'app/Console/Commands/Scale/GenerateScaleData.php',
    'App\\Console\\Commands\\Scale\\ExportApiLoadTestData' => 'app/Console/Commands/Scale/ExportApiLoadTestData.php',
    'App\\Console\\Commands\\Scale\\SeedPublicFeedbackScale' => 'app/Console/Commands/Scale/SeedPublicFeedbackScale.php',
    'App\\Console\\Commands\\Scale\\ExportLoadTestData' => 'app/Console/Commands/Scale/ExportLoadTestData.php',
];

/*
 * Code outside a module that may query its tables, and why. Reporting readers
 * are checked to write nothing; every reader is checked not to write the
 * module's tables. Catalogues that only name tables (the organization-scope
 * registry, the retention matrix, config/auth.php) are not queries and need
 * no entry; the retention matrix has its own check below.
 */
const MODULE_TABLE_READERS = [
    'Transport' => [
        // Situation Room panels aggregate boardings and trips by organization scope (counts only).
        'app/Services/SituationRoom/Analytics/TransportAnalytics.php' => 'reporting',
    ],
    'Cafeteria' => [
        // Situation Room panels aggregate meals, refusals and subsidy by organization scope (totals only).
        'app/Services/SituationRoom/Analytics/CafeteriaAnalytics.php' => 'reporting',
        // Situation Room's supporting indicators count meals by scope.
        'app/Services/SituationRoom/SupportingIndicators.php' => 'reporting',
        // The data-integrity audit looks for duplicate ledger entries; it reports, never repairs.
        'app/Console/Commands/Audit/AuditDuplicateData.php' => 'reporting',
        // One-off move of legacy provider users: reads cafeteria locations and assignments to
        // find a user's provider; it writes provider users only (checked below).
        'Modules/Providers/Infrastructure/Console/MigrateLegacyProviderUsers.php' => 'migration',
    ],
    'Providers' => [
        // Provider names for the Situation Room transport panel.
        'app/Services/SituationRoom/Analytics/TransportAnalytics.php' => 'reporting',
        // Transport's data-quality checks (providers without a contract) and its reports (provider names).
        'Modules/Transport/Application/Services/TransportDataQualityService.php' => 'reporting',
        'Modules/Transport/Application/Services/TransportReportService.php' => 'reporting',
        // The cafeteria configuration audit counts portal users per provider.
        'Modules/Cafeteria/Infrastructure/Console/AuditCafeteriaConfiguration.php' => 'reporting',
    ],
    'Entitlements' => [
        // Settlement and dashboard aggregates; all are read-only.
        'app/Console/Commands/Audit/AuditDuplicateData.php' => 'reporting',
        'app/Services/Dashboard/DashboardChartService.php' => 'reporting',
        'app/Services/Dashboard/DashboardMetricService.php' => 'reporting',
        'Modules/Transport/Application/Services/TransportDataQualityService.php' => 'reporting',
        // One-off provider-user migration reads the old service-point association.
        'Modules/Providers/Infrastructure/Console/MigrateLegacyProviderUsers.php' => 'migration',
    ],
    'FieldWork' => [
        // Situation Room panels count field work by status and type, within the viewer's scope (totals only).
        'app/Services/SituationRoom/Analytics/FieldWorkAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/SupportingIndicators.php' => 'reporting',
    ],
    'Grievance' => [
        // The Situation Room case summary counts grievances by status within scope (totals only).
        'app/Services/SituationRoom/Analytics/CaseSummaryAnalytics.php' => 'reporting',
        // Retention keeps the audit rows of grievances still open or on legal hold: it reads which
        // they are (it deletes only audit rows, never grievances; checked below).
        'app/Services/Retention/RetentionProcessor.php' => 'retention',
    ],
    'Discipline' => [
        // The Situation Room case summary counts discipline cases by status within scope (totals only).
        'app/Services/SituationRoom/Analytics/CaseSummaryAnalytics.php' => 'reporting',
    ],
    'DailyWork' => [
        // Situation Room panels and the Intelligence service analysis aggregate daily logs and items (totals only).
        'app/Services/SituationRoom/Analytics/DailyOperationsAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/ServiceDeliveryAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/SupportingIndicators.php' => 'reporting',
        'app/Services/Intelligence/Business/ServiceAnalysis.php' => 'reporting',
    ],
    'Performance' => [
        // The Intelligence performance analysis and the Situation Room panels aggregate cycles,
        // agreements, reviews, KPI actuals and appeals within scope (totals only).
        'app/Services/Intelligence/Business/PerformanceAnalysis.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/PerformanceAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/CaseSummaryAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/SupportingIndicators.php' => 'reporting',
    ],
    'Feedback' => [
        // Production readiness counts active routing rules and institutions with an active QR.
        'app/Console/Commands/ProductionReadiness.php' => 'reporting',
        // Intelligence reports count submissions, QR coverage and services rated, by scope and target grants (totals only).
        'app/Services/Intelligence/Business/DataQualityAnalysis.php' => 'reporting',
        'app/Services/Intelligence/Business/PublicVoiceAnalysis.php' => 'reporting',
        'app/Services/Intelligence/Business/ServiceAnalysis.php' => 'reporting',
        // Situation Room panels aggregate submissions within the viewer's scope and target grants (totals only).
        'app/Services/SituationRoom/Analytics/CriticalAttentionAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/DataQualityAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/PublicVoiceAnalytics.php' => 'reporting',
        'app/Services/SituationRoom/Analytics/ServiceDeliveryAnalytics.php' => 'reporting',
        // The attention wall counts institutions without an active feedback QR.
        'app/Services/SituationRoom/AttentionCenter.php' => 'reporting',
    ],
];

/* Uses of a module's internals frozen when it moved; may only shrink (see the file). */
$internalUseBaseline = require __DIR__.'/module-internal-use-baseline.php';

foreach ($modules as $module) {
    $ns = "Modules\\{$module}";

    // One expectation per outside namespace: Pest's not->toUse passes silently when
    // expect() is given a list of namespaces (found 2026-10-30 with a planted use).
    foreach (['App', ...array_map(fn (string $other): string => "Modules\\{$other}", array_values(array_diff($modules, [$module])))] as $outside) {
        arch("{$module}: {$outside} uses only its Contracts")
            ->expect($outside)
            ->not->toUse(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers"])
            ->ignoring([...array_keys(MODULE_DEV_TOOLING), ...($internalUseBaseline[$module] ?? [])]);
    }

    arch("{$module}: Contracts expose no internals")
        ->expect("{$ns}\\Contracts")
        // Illuminate\Http\Request may pass through as request context (audit, IP), as in the Core's contracts.
        ->not->toUse(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers", 'Illuminate\\Database',
            'Illuminate\\Http\\Response', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\RedirectResponse', 'Illuminate\\Http\\Resources', 'Illuminate\\Http\\UploadedFile', 'Illuminate\\Foundation\\Http'])
        // Published read models are Eloquent by design, and read-only (checked below).
        ->ignoring("{$ns}\\Contracts\\ReadModels");

    // An owner may publish read models of its records for other modules' relations
    // (ADR 0017): final, read-only (every write throws), and free of the module's internals.
    arch("{$module}: published read models are read-only")
        ->expect("{$ns}\\Contracts\\ReadModels")
        ->toExtend(ReadOnlyModel::class)
        ->toBeFinal()
        ->not->toUse(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers"]);

    arch("{$module}: Contracts are interfaces")
        ->expect("{$ns}\\Contracts")
        ->toBeInterfaces()
        ->ignoring(["{$ns}\\Contracts\\Data", "{$ns}\\Contracts\\Events", "{$ns}\\Contracts\\ReadModels"]);

    // Published values (enums) sit beside the final data classes.
    $enums = array_values(array_filter(
        array_map(fn (string $f): string => "{$ns}\\Contracts\\Data\\".basename($f, '.php'), glob($root."/Modules/{$module}/Contracts/Data/*.php") ?: []),
        fn (string $class): bool => enum_exists($class),
    ));
    arch("{$module}: contract data is final")
        ->expect("{$ns}\\Contracts\\Data")
        ->toBeFinal()
        ->ignoring($enums);

    arch("{$module}: contract events are final")
        ->expect("{$ns}\\Contracts\\Events")
        ->toBeFinal();
}

it('only shrinks the frozen uses of module internals', function () use ($internalUseBaseline): void {
    foreach ($internalUseBaseline as $module => $classes) {
        expect(count($classes))->toBe(count(array_unique($classes)), "{$module}: a class is listed twice");
        foreach ($classes as $class) {
            expect(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class))->toBeTrue("{$class} no longer exists: drop it from the {$module} baseline");
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            expect(preg_match('/Modules\\\\+'.$module.'\\\\+(Domain|Application|Infrastructure|Http|Providers)\\\\/', $source))
                ->toBe(1, "{$class} no longer uses {$module} internals: drop it from the baseline");
        }
    }
});

it('binds every module contract to an implementation', function () use ($root, $modules): void {
    $checked = 0;
    foreach ($modules as $module) {
        foreach (glob($root."/Modules/{$module}/Contracts/*.php") ?: [] as $file) {
            $contract = "Modules\\{$module}\\Contracts\\".basename($file, '.php');
            if (! interface_exists($contract)) {
                continue;
            }
            expect(app()->bound($contract))->toBeTrue("{$contract} is not bound")
                ->and(app($contract))->toBeInstanceOf($contract);
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(0);
});

it('keeps modules free of dependency cycles', function () use ($root, $modules): void {
    $edges = [];
    foreach ($modules as $module) {
        $edges[$module] = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root."/Modules/{$module}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/Modules\\\\+(\w+)\\\\+/', (string) file_get_contents($file->getPathname()), $m);
            foreach (array_unique($m[1]) as $other) {
                if ($other !== $module && in_array($other, $modules, true)) {
                    $edges[$module][$other] = true;
                }
            }
        }
    }

    $visit = function (string $node, array $path) use (&$visit, $edges): void {
        expect(in_array($node, $path, true))->toBeFalse('module cycle: '.implode(' -> ', [...$path, $node]));
        foreach (array_keys($edges[$node] ?? []) as $next) {
            $visit($next, [...$path, $node]);
        }
    };
    foreach ($modules as $module) {
        $visit($module, []);
    }
});

it('never uses a pre-move class name outside the compatibility bridge', function () use ($root): void {
    $map = require $root.'/Modules/legacy-class-map.php';
    $bridge = [
        'Modules/legacy-class-map.php',
        'tests/Feature/Architecture/ModuleMoveCompatibilityTest.php',
        ...array_map(fn (string $p): string => substr($p, strlen($root) + 1), glob($root.'/Modules/*/Providers/*ServiceProvider.php') ?: []),
        // Published read models store the owner's names in polymorphic columns (getMorphClass).
        ...array_map(fn (string $p): string => substr($p, strlen($root) + 1), glob($root.'/Modules/*/Contracts/ReadModels/*.php') ?: []),
    ];
    // One alternation per 150 names: PCRE caps the size of a compiled pattern.
    $patterns = array_map(
        fn (array $names): string => '/(?<![A-Za-z0-9_\\\\])('.implode('|', array_map(fn (string $old): string => preg_quote($old, '/'), $names)).')(?![A-Za-z0-9_])/',
        array_chunk(array_keys($map), 150),
    );

    $offenders = [];
    foreach (['app', 'Modules', 'routes', 'config', 'database', 'bootstrap', 'tests'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getExtension() !== 'php' || in_array($relative, $bridge, true) || str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }
            // Escaped strings ('App\\Models\\X') and plain names read the same.
            $source = str_replace('\\\\', '\\', (string) file_get_contents($file->getPathname()));
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source, $hit)) {
                    $offenders[] = "{$relative} ({$hit[1]})";

                    continue 2;
                }
            }
            // A bare short name in the old namespace (Entitlement::class inside App\Models)
            // resolves to the old class through its alias: the same old name, unqualified.
            if (preg_match('/^namespace ([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            preg_match_all('/^use ([^;]+?)(?: as (\w+))?;/m', $source, $uses, PREG_SET_ORDER);
            $imported = array_map(fn (array $u): string => $u[2] ?? substr($u[1], (int) strrpos('\\'.$u[1], '\\')), $uses);
            preg_match_all('/(?<![\w\\\\$>:])([A-Z]\w+)(?=::|\s+\$\w|\s*\|)/', $source, $bare);
            foreach (array_unique($bare[1]) as $short) {
                $old = $ns[1].'\\'.$short;
                if (isset($map[$old]) && ! in_array($short, $imported, true) && preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) '.$short.'\b/m', $source) !== 1) {
                    $offenders[] = "{$relative} ({$old}, unqualified)";
                }
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('keeps a module\'s tables to the module and its declared read-only readers', function () use ($root, $modules): void {
    $ownership = require $root.'/Modules/ownership.php';
    foreach ($modules as $module) {
        $tables = array_unique(array_map(fn (string $model): string => (new $model)->getTable(), array_filter($ownership[$module]['models'], fn (string $m): bool => is_subclass_of($m, Model::class))));
        // Database access to a table: the query builder's table(), the joins, from(), and the
        // schema checks, with the name as a whole string or before an alias ('x as t'). Words
        // like 'providers' are also prop keys and permission names, so a bare string does not
        // count. Validation rules (exists:x,id) are foreign-key checks, allowed in one database.
        $names = implode('|', array_map(fn (string $t): string => preg_quote($t, '/'), $tables));
        $pattern = '/(?:table|join|leftJoin|rightJoin|crossJoin|joinSub|from|hasTable|hasColumn|hasColumns)\(\s*[\'"]('.$names.')(?=[\'" ])/';
        $allowed = [...array_keys(MODULE_TABLE_READERS[$module] ?? []), ...array_values(MODULE_DEV_TOOLING)];

        $offenders = [];
        foreach (['app', 'Modules', 'routes', 'config', 'bootstrap'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($file->getExtension() !== 'php' || str_starts_with($relative, "Modules/{$module}/") || str_starts_with($relative, 'bootstrap/cache/') || in_array($relative, $allowed, true)) {
                    continue;
                }
                if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $hit)) {
                    $offenders[] = "{$relative} ({$hit[1]})";
                }
            }
        }
        expect($offenders)->toBe([], "{$module} tables named outside the module");

        foreach (MODULE_TABLE_READERS[$module] ?? [] as $path => $kind) {
            $source = (string) file_get_contents($root.'/'.$path);
            expect(preg_match($pattern, $source))->toBe(1, "{$path} no longer reads {$module} tables: drop it from the list");
            if ($kind === 'reporting') {
                expect($source)->not->toMatch('/->(insert\w*|upsert|update\w*|delete|forceDelete|truncate|increment|decrement)\(/', "{$path} may only read {$module} tables");
            }
            // No declared reader writes the module's tables, whatever else it writes.
            expect($source)->not->toMatch('/table\([\'"]('.$names.')[\'"]\)[^;]*->(insert\w*|upsert|update\w*|delete|truncate|increment|decrement)\(/', "{$path} writes {$module} tables");
        }
    }
});

it('lets only the owning module create its records', function () use ($root, $modules): void {
    // Static creation calls on a module's models (or `new` on them) outside the module.
    // Updates through save() cannot be told apart statically; the frozen list and
    // review cover those. Other modules create through the owner's Contracts.
    $ownership = require $root.'/Modules/ownership.php';
    foreach ($modules as $module) {
        $short = array_map(fn (string $model): string => substr($model, strrpos($model, '\\') + 1), $ownership[$module]['models']);
        $names = implode('|', array_map(fn (string $s): string => preg_quote($s, '/'), $short));
        $create = '/\b('.$names.')::(?:query\(\)\s*->\s*)?(?:withTrashed\(\)\s*->\s*)?(create|firstOrCreate|updateOrCreate|forceCreate|createQuietly|insert\w*|upsert)\(|\bnew ('.$names.')\(/';

        $offenders = [];
        foreach (['app', 'Modules'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($file->getExtension() !== 'php' || str_starts_with($relative, "Modules/{$module}/") || in_array($relative, MODULE_DEV_TOOLING, true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (str_contains($source, "Modules\\{$module}\\Domain") && preg_match($create, $source, $hit)) {
                    $offenders[] = "{$relative} ({$hit[0]})";
                }
            }
        }
        expect($offenders)->toBe([], "{$module} records created outside the module");
    }
});

it('never prunes transport or cafeteria records automatically', function (): void {
    foreach (RetentionMatrix::RECORD_CLASSES as $key => $category) {
        $owned = array_filter($category['tables'], fn (string $t): bool => str_starts_with($t, 'transport_') || str_starts_with($t, 'cafeteria_'));
        if ($owned !== []) {
            expect($category['automated'])->toBeFalse("retention category {$key} would prune ".implode(', ', $owned));
        }
    }
});

it('publishes exactly the status values the modules use', function (): void {
    expect(TripStatus::ALL)->toBe(TransportTripStatus::values())
        ->and(TransactionStatus::ALL)->toBe(array_column(CafeteriaTransactionStatus::cases(), 'value'));
});
