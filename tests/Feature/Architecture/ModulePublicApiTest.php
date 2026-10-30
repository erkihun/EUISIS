<?php

declare(strict_types=1);

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
];

/*
 * Code outside a module that may name its tables, and why. Reporting and
 * catalogue entries are read-only and are checked for writes below.
 */
const MODULE_TABLE_READERS = [
    'Transport' => [
        // Situation Room panels aggregate boardings and trips by organization scope (counts only).
        'app/Services/SituationRoom/Analytics/TransportAnalytics.php' => 'reporting',
        // The organization-scope registry: which column scopes a table. Metadata, no query.
        'app/Providers/ServiceModuleServiceProvider.php' => 'catalogue',
        // The retention catalogue; transport tables sit only in categories that are not pruned automatically.
        'app/Support/Retention/RetentionMatrix.php' => 'catalogue',
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
        'app/Console/Commands/MigrateLegacyProviderUsers.php' => 'migration',
        // The cafeteria portal's auth guard and user provider are named after its table.
        'config/auth.php' => 'catalogue',
        'app/Providers/ServiceModuleServiceProvider.php' => 'catalogue',
        'app/Support/Retention/RetentionMatrix.php' => 'catalogue',
    ],
];

foreach ($modules as $module) {
    $ns = "Modules\\{$module}";

    arch("{$module}: other code uses only its Contracts")
        ->expect(array_values(array_merge(['App'], array_map(fn (string $other): string => "Modules\\{$other}", array_diff($modules, [$module])))))
        ->not->toUse(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers"])
        ->ignoring(array_keys(MODULE_DEV_TOOLING));

    arch("{$module}: Contracts expose no internals")
        ->expect("{$ns}\\Contracts")
        // Illuminate\Http\Request may pass through as request context (audit, IP), as in the Core's contracts.
        ->not->toUse(["{$ns}\\Domain", "{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers", 'Illuminate\\Database',
            'Illuminate\\Http\\Response', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\RedirectResponse', 'Illuminate\\Http\\Resources', 'Illuminate\\Http\\UploadedFile', 'Illuminate\\Foundation\\Http']);

    arch("{$module}: Contracts are interfaces")
        ->expect("{$ns}\\Contracts")
        ->toBeInterfaces()
        ->ignoring(["{$ns}\\Contracts\\Data", "{$ns}\\Contracts\\Events"]);

    arch("{$module}: contract data is final")
        ->expect(["{$ns}\\Contracts\\Data", "{$ns}\\Contracts\\Events"])
        ->toBeFinal();
}

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
    ];
    $pattern = '/(?<![A-Za-z0-9_\\\\])('.implode('|', array_map(fn (string $old): string => preg_quote($old, '/'), array_keys($map))).')(?![A-Za-z0-9_])/';

    $offenders = [];
    foreach (['app', 'Modules', 'routes', 'config', 'database', 'bootstrap', 'tests'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getExtension() !== 'php' || in_array($relative, $bridge, true) || str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }
            // Escaped strings ('App\\Models\\X') and plain names read the same.
            $source = str_replace('\\\\', '\\', (string) file_get_contents($file->getPathname()));
            if (preg_match($pattern, $source, $hit)) {
                $offenders[] = "{$relative} ({$hit[1]})";
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('keeps a module\'s tables to the module and its declared read-only readers', function () use ($root, $modules): void {
    $ownership = require $root.'/Modules/ownership.php';
    foreach ($modules as $module) {
        $tables = array_unique(array_map(fn (string $model): string => (new $model)->getTable(), array_filter($ownership[$module]['models'], fn (string $m): bool => is_subclass_of($m, Model::class))));
        // A table name as a whole string or before an alias ('x as t'); 'x.view' is a permission name.
        $names = implode('|', array_map(fn (string $t): string => preg_quote($t, '/'), $tables));
        $pattern = '/[\'"]('.$names.')(?=[\'" ])/';
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
