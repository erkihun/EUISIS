<?php

declare(strict_types=1);

/*
 * Module boundaries (ADR 0016), checked for every module under Modules/:
 *
 * - Domain depends on nothing else in its module, and never on HTTP.
 * - Application never uses its module's Http layer.
 * - Infrastructure never uses its module's Http layer.
 * - Code outside a module (app/ and other modules) may use its Application
 *   services and Domain models/enums, never its Http or Infrastructure layer;
 *   only the composition root (service providers) wires those in.
 */

$modules = array_map('basename', glob(dirname(__DIR__, 3).'/Modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    $ns = "Modules\\{$module}";

    arch("{$module}: Domain depends only on Domain")
        ->expect("{$ns}\\Domain")
        ->not->toUse(["{$ns}\\Application", "{$ns}\\Infrastructure", "{$ns}\\Http", 'Illuminate\\Http']);

    arch("{$module}: Application does not use Http")
        ->expect("{$ns}\\Application")
        ->not->toUse("{$ns}\\Http");

    arch("{$module}: Infrastructure does not use Http")
        ->expect("{$ns}\\Infrastructure")
        ->not->toUse("{$ns}\\Http");

    arch("{$module}: other code never reaches into its Http or Infrastructure")
        ->expect(array_values(array_merge(['App'], array_map(fn (string $other): string => "Modules\\{$other}", array_diff($modules, [$module])))))
        ->not->toUse(["{$ns}\\Http", "{$ns}\\Infrastructure"])
        ->ignoring('App\\Providers');
}

it('has every module laid out in the four layers', function () use ($modules): void {
    expect($modules)->not->toBeEmpty();
    foreach ($modules as $module) {
        foreach (glob(dirname(__DIR__, 3)."/Modules/{$module}/*", GLOB_ONLYDIR) ?: [] as $layer) {
            expect(basename($layer))->toBeIn(['Domain', 'Application', 'Infrastructure', 'Http'], "Modules/{$module}/".basename($layer).' is not one of the four layers');
        }
    }
});
