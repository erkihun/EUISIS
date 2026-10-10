<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;

/*
 * DatabaseSeeder creates demo organizations, employees and provider/user
 * accounts (and purges is_demo rows). It must never run against production,
 * where reference data is seeded with the individual reference seeders.
 */
it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // Invoke the seeder directly: `db:seed` would first stop at Laravel's own
    // production confirmation prompt, which an operator can answer "yes" to.
    expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())
        ->toThrow(RuntimeException::class, 'DatabaseSeeder creates demo data');
});
