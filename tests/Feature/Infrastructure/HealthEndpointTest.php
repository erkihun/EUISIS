<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

it('fails readiness when the private storage root is not a usable directory (a broken shared mount)', function (): void {
    $notADirectory = storage_path('framework/testing/not-a-mount-'.uniqid());
    @mkdir(dirname($notADirectory), 0777, true);
    file_put_contents($notADirectory, 'x');
    config(['filesystems.disks.local.root' => $notADirectory]);
    Storage::forgetDisk('local');

    try {
        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.storage', 'fail');
    } finally {
        @unlink($notADirectory);
    }
});

it('probes a database queue with one row lookup, not a COUNT over the jobs table', function (): void {
    config(['queue.default' => 'database']);
    DB::enableQueryLog();

    $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.queue', 'ok');

    $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'jobs'));
    expect($sql)->not->toBeEmpty();
    expect($sql->implode(' '))->not->toContain('count(');
});

it('does not start a session for a probe', function (): void {
    $this->get('/health/live')->assertCookieMissing(config('session.cookie'));
});
