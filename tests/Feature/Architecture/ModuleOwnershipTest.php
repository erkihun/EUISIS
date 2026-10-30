<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

/*
 * Every Eloquent model, and so every business table, has exactly one owning
 * module (ADR 0017, Modules/ownership.php). A new model fails here until its
 * owner is recorded; a model of a migrated module must live in that module.
 */

/** @return list<class-string<Model>> */
function concreteModels(): array
{
    $root = base_path();
    $models = [];
    foreach ([['app/Models', 'App\\Models'], ...array_map(
        fn (string $dir): array => [substr($dir, strlen($root) + 1), 'Modules\\'.basename(dirname($dir, 2)).'\\Domain\\Models'],
        glob($root.'/Modules/*/Domain/Models', GLOB_ONLYDIR) ?: [],
    )] as [$dir, $namespace]) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(base_path($dir)) + 1, -4);
            $class = $namespace.'\\'.str_replace(['/', '\\'], '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }
    }
    sort($models);

    return $models;
}

it('gives every model exactly one owning module', function (): void {
    $ownership = require base_path('Modules/ownership.php');
    $owners = [];
    foreach ($ownership as $module => $entry) {
        foreach ($entry['models'] as $model) {
            expect($owners)->not->toHaveKey($model, "{$model} is listed under both {$module} and ".($owners[$model] ?? ''));
            $owners[$model] = $module;
        }
    }

    $models = concreteModels();
    expect(array_values(array_diff($models, array_keys($owners))))->toBe([], 'models with no owner in Modules/ownership.php')
        ->and(array_values(array_diff(array_keys($owners), $models)))->toBe([], 'listed in Modules/ownership.php but not a model any more');
});

it('keeps the models of a migrated module inside that module', function (): void {
    foreach (require base_path('Modules/ownership.php') as $module => $entry) {
        if ($entry['home'] === 'app') {
            continue;
        }
        expect(is_dir(base_path($entry['home'])))->toBeTrue("{$entry['home']} does not exist");
        foreach ($entry['models'] as $model) {
            expect($model)->toStartWith("Modules\\{$module}\\Domain\\", "{$model} belongs to the migrated {$module} module but lives outside it");
        }
    }
});
