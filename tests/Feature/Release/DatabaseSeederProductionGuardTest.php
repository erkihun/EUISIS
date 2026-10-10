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

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'DatabaseSeeder creates demo data');
});
