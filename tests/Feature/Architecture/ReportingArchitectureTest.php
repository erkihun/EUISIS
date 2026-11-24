<?php

declare(strict_types=1);

/*
 * Boundaries of the Reporting module (Modules/Reporting/README.md), beyond
 * the rules every module shares (ModuleBoundariesTest, ModulePublicApiTest,
 * ModuleOwnershipTest):
 *
 * - Domain is pure PHP: no framework, no HTTP, no persistence.
 * - Application reaches the outside only through its ports: never the Core's
 *   models or services, never another module.
 * - Only the integration adapters name the Core's contracts or another
 *   module's contracts; nothing in the module names another owner's model.
 * - No class of the module queries a table it does not own.
 * - Shared infrastructure and the rest of the application reach Reporting
 *   through Contracts only (also in ModulePublicApiTest).
 * - The frontend module imports no other module's internals, and nothing
 *   outside it imports its internals.
 *
 * What these checks cannot prove: they read source text and `use` graphs.
 * A table reached through a dynamically built name, or a raw SQL string
 * assembled at run time, would escape them. Data ownership is therefore also
 * covered by behaviour tests and by review.
 */

const REPORTING_ROOT = 'Modules/Reporting';

/** @return array<string, string> repository-relative path => source */
function reportingSources(string $subdirectory = ''): array
{
    $root = dirname(__DIR__, 3);
    $base = $root.'/'.REPORTING_ROOT.($subdirectory === '' ? '' : '/'.$subdirectory);
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($sources);

    return $sources;
}

/*
 * The layer rules below read `use` lines and fully qualified names in the
 * source instead of using Pest's arch() analysis: that analysis keeps the
 * parsed code base in memory, and with every module's rules in one run it
 * exhausted PHP's memory limit. ModuleBoundariesTest still checks the shared
 * layer rules for this module through arch().
 */

/**
 * @param  list<string>  $forbidden  namespace prefixes
 * @return list<string> "path: reference" for every forbidden import or fully qualified name
 */
function reportingForbiddenUses(string $layer, array $forbidden): array
{
    $alternatives = implode('|', array_map(fn (string $ns): string => preg_quote($ns, '/'), $forbidden));
    $offenders = [];
    foreach (reportingSources($layer) as $path => $source) {
        // Comments may name anything; code may not.
        $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        if (preg_match_all('/^use\s+(?:function\s+)?('.$alternatives.')(?:\\\\[^;]*)?;|(?<![\w\\\\])\\\\('.$alternatives.')\\\\\w/m', $code, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $hit) {
                $offenders[] = "{$path}: ".trim($hit[0]);
            }
        }
    }

    return $offenders;
}

it('keeps the Domain pure PHP: no framework, no HTTP, no persistence, nothing else of the module', function (): void {
    expect(reportingForbiddenUses('Domain', [
        'Illuminate', 'Laravel', 'Carbon', 'App', 'Inertia', 'Symfony', 'PhpOffice', 'Spatie',
        'Modules\Reporting\Application', 'Modules\Reporting\Infrastructure', 'Modules\Reporting\Http', 'Modules\Reporting\Providers', 'Modules\Reporting\Contracts',
    ]))->toBe([])
        ->and(count(reportingSources('Domain')))->toBeGreaterThan(10);
});

it('keeps the Application layer off HTTP and off the Core', function (): void {
    expect(reportingForbiddenUses('Application', [
        'Illuminate\Http', 'Inertia', 'App', 'PhpOffice', 'Modules\Reporting\Http', 'Modules\Reporting\Providers',
    ]))->toBe([]);
});

it('keeps the Contracts free of the framework and of the module\'s internals', function (): void {
    expect(reportingForbiddenUses('Contracts', [
        'Illuminate', 'App', 'Carbon', 'Modules\Reporting\Domain', 'Modules\Reporting\Application', 'Modules\Reporting\Infrastructure', 'Modules\Reporting\Http',
    ]))->toBe([]);
});

it('detects a forbidden import when one is planted', function (): void {
    // The checks above must not pass by matching nothing.
    $code = "<?php\nnamespace Modules\\Reporting\\Domain;\nuse Illuminate\\Support\\Str;\nfinal class X { public function y(): string { return \\Carbon\\Carbon::now()->toDateString(); } }";
    $alternatives = implode('|', array_map(fn (string $ns): string => preg_quote($ns, '/'), ['Illuminate', 'Carbon']));

    expect(preg_match_all('/^use\s+(?:function\s+)?('.$alternatives.')(?:\\\\[^;]*)?;|(?<![\w\\\\])\\\\('.$alternatives.')\\\\\w/m', $code))->toBe(2);
});

it('never names a page prop after one the layout shares with every page', function (): void {
    // A page prop with a shared prop's name replaces it for that page: a Reporting page that sent
    // "settings" took the system settings (brand, theme, calendar) away from the layout and broke it.
    $middleware = (string) file_get_contents(dirname(__DIR__, 3).'/app/Http/Middleware/HandleInertiaRequests.php');
    preg_match_all("/^\s{12}'([A-Za-z_]+)'\s*=>/m", $middleware, $shared);
    $shared = array_values(array_unique([...$shared[1], 'auth', 'flash', 'errors', 'ziggy']));
    expect($shared)->toContain('settings', 'features', 'calendar', 'branding');

    $offenders = [];
    foreach (reportingSources('Http/Controllers') as $path => $source) {
        // The top-level keys of each Inertia::render() props array (12 spaces deep, or first on the line after "[").
        preg_match_all("/Inertia::render\('[^']+',\s*\[(.*?)\n\s{8}\]\);/s", $source, $renders);
        foreach ($renders[1] as $props) {
            preg_match_all("/(?:^|\n)\s{12}'([A-Za-z_]+)'\s*=>|^\s*'([A-Za-z_]+)'\s*=>/", $props, $keys);
            foreach (array_filter([...$keys[1], ...$keys[2]]) as $key) {
                if (in_array($key, $shared, true)) {
                    $offenders[] = "{$path}: {$key}";
                }
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('keeps the Core and other modules behind the integration adapters', function (): void {
    $offenders = [];
    foreach (reportingSources() as $path => $source) {
        // No model, service or action of the Core anywhere in the module.
        if (preg_match('/^use App\\\\(Models|Services|Actions|Jobs|Events)\\\\[^;]+;/m', $source, $hit)) {
            $offenders[] = "{$path}: {$hit[0]}";
        }
        $adapter = str_starts_with($path, REPORTING_ROOT.'/Infrastructure/Integration/') || str_starts_with($path, REPORTING_ROOT.'/Infrastructure/Notifications/');
        // The Core's published contracts and other modules' contracts: adapters only.
        if (! $adapter && preg_match('/^use App\\\\Backbone\\\\[^;]+;/m', $source, $hit)) {
            $offenders[] = "{$path}: {$hit[0]}";
        }
        if (preg_match_all('/Modules\\\\(?!Reporting\\\\)(\w+)\\\\(\w+)/', $source, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $hit) {
                if (! $adapter || $hit[2] !== 'Contracts') {
                    $offenders[] = "{$path}: {$hit[0]}";
                }
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('queries only the tables Reporting owns', function (): void {
    $offenders = [];
    foreach (reportingSources() as $path => $source) {
        if (str_contains($path, '/Database/Migrations/')) {
            // Migrations create the module's tables and nothing else.
            preg_match_all('/Schema::(?:create|table|dropIfExists|hasTable)\(\s*[\'"]([a-z_]+)[\'"]/', $source, $schema);
            foreach ($schema[1] as $table) {
                if (! str_starts_with($table, 'reporting_')) {
                    $offenders[] = "{$path}: schema change on {$table}";
                }
            }
            if (preg_match('/->(?:constrained|references|foreign)\(/', $source) && preg_match_all('/constrained\(\s*[\'"]([a-z_]+)[\'"]/', $source, $keys)) {
                foreach ($keys[1] as $table) {
                    if (! str_starts_with($table, 'reporting_')) {
                        $offenders[] = "{$path}: foreign key to {$table}";
                    }
                }
            }

            continue;
        }
        // Query-builder and raw SQL table references.
        preg_match_all('/(?:DB::table|->table|->from|->join|->leftJoin|->rightJoin|->crossJoin|->joinSub)\(\s*[\'"]([a-z_]+)[\'"]/', $source, $tables);
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+[`"]?([a-z_]{3,})[`"]?/', $source, $raw);
        preg_match_all('/Rule::(?:exists|unique)\(\s*[\'"]([a-z_]+)[\'"]/', $source, $rules);
        preg_match_all('/protected \$table = [\'"]([a-z_]+)[\'"]/', $source, $models);
        foreach ([...$tables[1], ...$raw[1], ...$rules[1], ...$models[1]] as $table) {
            if (! str_starts_with($table, 'reporting_')) {
                $offenders[] = "{$path}: {$table}";
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('declares no relation from a Reporting model to another owner\'s model', function (): void {
    $offenders = [];
    foreach (reportingSources('Infrastructure/Persistence/Models') as $path => $source) {
        preg_match_all('/->(?:belongsTo|hasMany|hasOne|belongsToMany|morphTo|morphMany|morphOne|hasManyThrough)\(\s*([\\\\\w]+)::class/', $source, $relations);
        foreach ($relations[1] as $target) {
            $resolved = str_contains($target, '\\') ? ltrim($target, '\\') : 'Modules\\Reporting\\Infrastructure\\Persistence\\Models\\'.$target;
            if (! str_starts_with($resolved, 'Modules\\Reporting\\') || ! class_exists($resolved)) {
                $offenders[] = "{$path}: {$target}";
            }
        }
        if (preg_match('/->morphTo\(\)/', $source)) {
            $offenders[] = "{$path}: polymorphic relation";
        }
    }
    expect($offenders)->toBe([]);
});

it('is reached from outside only through its Contracts', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    foreach (['app', 'Modules', 'routes', 'config', 'database'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // Modules/ownership.php is the registry that names every module's models; it uses none of them.
            if ($file->getExtension() !== 'php' || str_starts_with($path, REPORTING_ROOT.'/') || $path === 'Modules/ownership.php') {
                continue;
            }
            if (preg_match('/Modules\\\\+Reporting\\\\+(Domain|Application|Infrastructure|Http|Providers)\b/', (string) file_get_contents($file->getPathname()), $hit)) {
                $offenders[] = "{$path}: {$hit[0]}";
            }
        }
    }
    // The one place that names the module's provider.
    expect($offenders)->toBe([]);
    expect(file_get_contents($root.'/bootstrap/providers.php'))->toContain('Modules\Reporting\Providers\ReportingServiceProvider');
});

it('keeps the frontend module\'s imports inside its boundary', function (): void {
    $root = dirname(__DIR__, 3).'/resources/js';
    $module = $root.'/modules/reporting';
    expect(is_dir($module))->toBeTrue();
    $offenders = [];
    $imports = function (string $file): array {
        preg_match_all('/(?:import|export)\s[^;]*?from\s+[\'"]([^\'"]+)[\'"]|import\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', (string) file_get_contents($file), $m);

        return array_values(array_filter([...$m[1], ...$m[2]]));
    };
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $inside = str_starts_with($path, str_replace('\\', '/', $module).'/');
        foreach ($imports($path) as $import) {
            if ($inside) {
                // From the application: the shell (layouts), generic UI components, hooks, the editor, shared types and utilities.
                // Never another business module's pages or its component folder.
                if (preg_match('#^@/(Pages/|modules/(?!reporting/))#', $import) === 1
                    || (preg_match('#^@/Components/([a-z][A-Za-z\-]*)/#', $import, $folder) === 1 && ! in_array($folder[1], ['ui', 'forms', 'errors'], true))) {
                    $offenders[] = substr($path, strlen($root) + 1).' imports '.$import;
                }
            } elseif (preg_match('#^@/modules/reporting/(?!i18n/)#', $import) === 1 && ! str_ends_with($path, '/app.tsx')) {
                // Outside code may load the module's dictionaries (the language bundles) and nothing else.
                $offenders[] = substr($path, strlen($root) + 1).' imports '.$import;
            }
        }
    }
    expect($offenders)->toBe([]);
});
