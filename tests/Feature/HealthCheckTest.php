<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('reports healthy when the database and cache answer', function (): void {
    $this->get('/up')->assertOk();
});

it('reports unhealthy when the database is unreachable', function (): void {
    // A load balancer must take a node that lost its database out of rotation.
    config()->set('app.debug', false);
    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => storage_path('framework/testing/missing-dir/none.sqlite'),
        'prefix' => '',
    ]);
    $healthy = config('database.default');
    config()->set('database.default', 'unreachable');
    DB::purge('unreachable');

    $response = $this->get('/up');
    config()->set('database.default', $healthy); // the test's own rollback needs it

    $response->assertStatus(500);
});
