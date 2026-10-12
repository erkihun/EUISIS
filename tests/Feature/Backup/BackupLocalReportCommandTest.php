<?php

declare(strict_types=1);

use App\Services\Backup\BackupStatusService;

require_once __DIR__.'/../../Fixtures/backup_report.php';

function localReportPath(): string
{
    return sys_get_temp_dir().'/euisis-local-report-'.uniqid().'/status.json';
}

function localReportHealth(): array
{
    return app(BackupStatusService::class)->status(fresh: true);
}

function configureLocalReport(string $path): void
{
    config(['backup.enabled' => true, 'backup.driver' => 'report', 'backup.cache_seconds' => 0, 'backup.report_path' => $path,
        'backup.rpo' => 'approved', 'backup.rto' => 'approved']);
    // WAL health comes from the live PostgreSQL connection, which the test database does not have.
    fakeWal(healthyWal());
}

it('writes a sample report that the report driver evaluates as healthy', function () {
    $path = localReportPath();
    configureLocalReport($path);

    $this->artisan('backup:local-report')->assertSuccessful();

    $report = json_decode((string) file_get_contents($path), true);
    expect($report['version'])->toBe(1)
        ->and($report['repositories'])->toHaveCount(2)
        ->and($report['observed_at'])->toBeGreaterThan(time() - 60);

    $status = localReportHealth();
    expect($status['overall_status'])->toBe('HEALTHY')
        ->and($status['driver'])->toBe('report')
        ->and($status['repositories'][0]['backups'])->not->toBeEmpty();
});

it('can simulate a stale backup and an unavailable repository', function (string $scenario, string $reason) {
    $path = localReportPath();
    configureLocalReport($path);

    $this->artisan('backup:local-report', ['--scenario' => $scenario])->assertSuccessful();

    $status = localReportHealth();
    expect($status['overall_status'])->toBe('CRITICAL')
        ->and(collect($status['issues'])->pluck('code'))->toContain($reason);
})->with([
    'stale backup' => ['stale-backup', 'REPOSITORY_1_BACKUP_STALE'],
    'repository down' => ['repository-down', 'REPOSITORY_2_UNAVAILABLE'],
]);

it('rejects an unknown scenario', function () {
    $path = localReportPath();
    configureLocalReport($path);

    $this->artisan('backup:local-report', ['--scenario' => 'armageddon'])->assertFailed();
    expect(file_exists($path))->toBeFalse();
});

it('never runs in production', function () {
    $path = localReportPath();
    config(['backup.report_path' => $path]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('backup:local-report')->assertFailed();
    expect(file_exists($path))->toBeFalse();
});
