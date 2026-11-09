<?php

declare(strict_types=1);

/*
 * Layer rules inside each module under Modules/ (ADR 0016, ADR 0017):
 *
 * - Domain depends on nothing else in its module except its own Contracts,
 *   and never on HTTP.
 * - Application never uses its module's Http layer or provider.
 * - Infrastructure never uses its module's Http layer or provider.
 *
 * What other code may use of a module (its Contracts only) is checked in
 * ModulePublicApiTest.
 */

$modules = array_map('basename', glob(dirname(__DIR__, 3).'/Modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    $ns = "Modules\\{$module}";

    arch("{$module}: Domain depends only on Domain and Contracts")
        ->expect("{$ns}\\Domain")
        ->not->toUse(["{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", "{$ns}\\Providers", 'Illuminate\\Http']);

    arch("{$module}: Application does not use Http")
        ->expect("{$ns}\\Application")
        ->not->toUse(["{$ns}\\Http", "{$ns}\\Providers"]);

    arch("{$module}: Infrastructure does not use Http")
        ->expect("{$ns}\\Infrastructure")
        ->not->toUse(["{$ns}\\Http", "{$ns}\\Providers"]);
}

it('has every module laid out in the six layers', function () use ($modules): void {
    expect($modules)->not->toBeEmpty();
    foreach ($modules as $module) {
        foreach (glob(dirname(__DIR__, 3)."/Modules/{$module}/*", GLOB_ONLYDIR) ?: [] as $layer) {
            expect(basename($layer))->toBeIn(['Contracts', 'Domain', 'Application', 'Infrastructure', 'Http', 'Providers'], "Modules/{$module}/".basename($layer).' is not one of the six layers');
        }
        expect(is_file(dirname(__DIR__, 3)."/Modules/{$module}/Providers/{$module}ServiceProvider.php"))->toBeTrue("Modules/{$module} has no {$module}ServiceProvider")
            ->and(require dirname(__DIR__, 3).'/bootstrap/providers.php')->toContain("Modules\\{$module}\\Providers\\{$module}ServiceProvider");
    }
});
