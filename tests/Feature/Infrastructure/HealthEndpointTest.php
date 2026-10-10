<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('reports liveness without touching any dependency', function (): void {
    $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness with each essential dependency', function (): void {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => [
            'database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok',
        ]]);
});

it('answers 503 and names only the failing check when a dependency is down', function (): void {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('SQLSTATE secret-host:5432 password=hunter2'));

    $response = $this->getJson('/health/ready')->assertStatus(503);

    $response->assertJsonPath('status', 'fail')->assertJsonPath('checks.database', 'fail');
    expect($response->getContent())->not->toContain('secret-host')->not->toContain('hunter2');
});

it('does not start a session for a probe', function (): void {
    $this->get('/health/live')->assertCookieMissing(config('session.cookie'));
});
