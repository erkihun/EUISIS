<?php

use App\Models\Permission;
use App\Models\User;
use App\Services\Backup\BackupStatusService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once __DIR__.'/../../Fixtures/backup_report.php';

function backupHealth(?bool $enforce = null): array
{
    return app(BackupStatusService::class)->status(fresh: true, enforce: $enforce);
}

function infoFails(int $exit, string $stderr): array
{
    return ['* version' => Process::result("pgBackRest 2.54.0\n"), '* info' => Process::result(output: '', errorOutput: $stderr, exitCode: $exit)];
}

function ranCommand(string $last): Closure
{
    return fn (PendingProcess $process) => is_array($process->command) && end($process->command) === $last;
}

it('reports NOT_CONFIGURED in development when the integration is disabled, never healthy', function () {
    Process::preventStrayProcesses();
    Process::fake();
    config(['backup.enabled' => false]);
    fakeWal(healthyWal());
    fakeEvidence(healthyEvidence());
    expect(backupHealth())->overall_status->toBe('NOT_CONFIGURED')->reason_code->toBe('NOT_CONFIGURED')
        ->infrastructure_status->toBe('NOT_CONFIGURED')->repository_status->toBe('NOT_CHECKED')->production_blocker->toBeFalse()
        ->message->toBe('Production backup infrastructure is not configured in this development environment.');
    Process::assertNothingRan();
});

it('treats missing infrastructure as a CRITICAL production blocker in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['backup.enabled' => false]);
    fakeWal(healthyWal());
    fakeEvidence(healthyEvidence());
    expect(backupHealth())->overall_status->toBe('CRITICAL')->production_blocker->toBeTrue()->enforced->toBeTrue()
        ->message->toBe('Production backup infrastructure is unavailable.');
});

it('returns COMMAND_NOT_FOUND without starting a process when pgBackRest is missing', function () {
    fakeBackupInfrastructure();
    config(['backup.pgbackrest.binary' => base_path('missing/pgbackrest')]);
    $status = backupHealth();
    expect($status)->overall_status->toBe('INFRASTRUCTURE_UNAVAILABLE')->reason_code->toBe('COMMAND_NOT_FOUND')
        ->binary->toBe('NOT_FOUND')->production_blocker->toBeFalse()
        ->and($status['diagnostics'])->preflight->toBe('BINARY_NOT_FOUND')->platform->toBe(PHP_OS_FAMILY);
    expect(backupHealth(enforce: true))->overall_status->toBe('CRITICAL')->production_blocker->toBeTrue();
    Process::assertNothingRan();
});

it('rejects unsafe configuration before any process starts', function ($settings) {
    fakeBackupInfrastructure();
    config($settings);
    expect(backupHealth())->reason_code->toBe('INVALID_CONFIGURATION')->infrastructure_status->toBe('UNAVAILABLE');
    Process::assertNothingRan();
})->with([
    'shell in stanza' => [['backup.pgbackrest.stanza' => 'euisis; rm -rf /']],
    'option in stanza' => [['backup.pgbackrest.stanza' => '--repo1-path=/tmp']],
    'relative binary' => [['backup.pgbackrest.binary' => 'pgbackrest']],
    'relative config' => [['backup.pgbackrest.config' => 'pgbackrest.conf']],
    'unsafe run-as user' => [['backup.pgbackrest.run_as' => 'postgres; id']],
    'unknown driver' => [['backup.driver' => 'shell']],
]);

it('reports CONFIG_NOT_FOUND for a configured pgBackRest file that does not exist', function () {
    fakeBackupInfrastructure();
    config(['backup.pgbackrest.config' => base_path('missing/pgbackrest.conf')]);
    expect(backupHealth())->reason_code->toBe('CONFIG_NOT_FOUND')->infrastructure_status->toBe('UNAVAILABLE');
    Process::assertNothingRan();
});

it('maps failed pgBackRest runs to specific reasons without exposing error text', function ($exit, $stderr, $reason) {
    fakeBackupInfrastructure(processes: infoFails($exit, $stderr));
    $status = backupHealth();
    expect($status)->infrastructure_status->toBe('UNAVAILABLE')->reason_code->toBe($reason)
        ->and($status['diagnostics'])->exit_code->toBe($exit)->stderr_present->toBe($stderr !== '')
        ->and(json_encode($status))->not->toContain('/etc/pgbackrest', 'secret-host');
})->with([
    'permission denied' => [41, "ERROR: [041]: unable to open file '/etc/pgbackrest/pgbackrest.conf' for read: [13] Permission denied", 'PERMISSION_DENIED'],
    'sudo refused' => [1, 'sudo: a password is required', 'PERMISSION_DENIED'],
    'config missing' => [55, "ERROR: [055]: unable to open missing file '/etc/pgbackrest/pgbackrest.conf' for read", 'CONFIG_NOT_FOUND'],
    'invalid option' => [31, "ERROR: [031]: invalid option '--repo3-path'", 'INVALID_CONFIGURATION'],
    'repository host down' => [49, "ERROR: [049]: unable to connect to 'secret-host': connection refused", 'REPOSITORY_UNAVAILABLE'],
    'stanza never created' => [55, "ERROR: [055]: unable to load info file '/etc/pgbackrest/backup.info'\nHINT: has a stanza-create been performed?", 'STANZA_NOT_FOUND'],
    'exec not found' => [127, '', 'COMMAND_NOT_FOUND'],
    'unclassified failure' => [25, 'ERROR: [025]: assertion failed', 'COMMAND_FAILED'],
]);

it('reports STANZA_NOT_FOUND when pgBackRest answers without the configured stanza', function ($info) {
    fakeBackupInfrastructure(info: $info);
    expect(backupHealth())->infrastructure_status->toBe('AVAILABLE')->repository_status->toBe('STANZA_NOT_FOUND')
        ->overall_status->toBe('CRITICAL')->reason_code->toBe('STANZA_NOT_FOUND');
})->with(['empty JSON list' => '[]', 'missing stanza path' => fn () => pgbackrestInfo(backups: [], repoCodes: [1 => 1, 2 => 1])]);

it('reports REPOSITORY_UNAVAILABLE and PERMISSION_DENIED per repository', function () {
    $info = json_decode(pgbackrestInfo(repoCodes: [1 => 0, 2 => 99]), true);
    fakeBackupInfrastructure(info: json_encode($info));
    expect(backupHealth())->repository_status->toBe('REPOSITORY_UNAVAILABLE')->overall_status->toBe('CRITICAL')->reason_code->toBe('REPOSITORY_UNAVAILABLE');
    $info[0]['repo'][1]['status']['message'] = 'unable to open path: Permission denied';
    fakeBackupInfrastructure(info: json_encode($info));
    expect(backupHealth())->repository_status->toBe('PERMISSION_DENIED')->reason_code->toBe('PERMISSION_DENIED');
});

it('distinguishes INVALID_COMMAND_OUTPUT from missing binaries and failed runs', function ($output, $parser) {
    fakeBackupInfrastructure(info: $output);
    $status = backupHealth();
    expect($status)->infrastructure_status->toBe('UNAVAILABLE')->reason_code->toBe('INVALID_COMMAND_OUTPUT')
        ->and($status['diagnostics'])->parser->toBe($parser)->exit_code->toBe(0);
})->with([['{not json', 'INVALID_JSON'], ['', 'EMPTY_OR_OVERSIZED_OUTPUT'], ['{"stanza":"euisis"}', 'UNEXPECTED_SHAPE']]);

it('rejects an unrecognized version banner as invalid output', function () {
    fakeBackupInfrastructure(processes: ['* version' => Process::result('something else'), '* info' => Process::result(pgbackrestInfo())]);
    expect(backupHealth())->reason_code->toBe('INVALID_COMMAND_OUTPUT');
    Process::assertDidntRun(ranCommand('info'));
});

it('reports BACKUP_NOT_FOUND when the repository is reachable but empty', function () {
    fakeBackupInfrastructure(info: pgbackrestInfo(backups: [], repoCodes: [1 => 2, 2 => 2]));
    expect(backupHealth())->infrastructure_status->toBe('AVAILABLE')->repository_status->toBe('AVAILABLE')
        ->backup_status->toBe('BACKUP_NOT_FOUND')->overall_status->toBe('CRITICAL')->reason_code->toBe('BACKUP_NOT_FOUND')
        ->message->toBe('Backup infrastructure is configured, but no valid backup was found.');
});

it('is HEALTHY only with recent backups, healthy WAL and current evidence', function () {
    fakeBackupInfrastructure();
    $status = backupHealth();
    expect($status)->overall_status->toBe('HEALTHY')->readiness->toBe('READY')->reason_code->toBeNull()->production_blocker->toBeFalse()
        ->version->toBe('2.54.0')->binary->toBe('FOUND')->stanza->toBe('euisis')
        ->backup_status->toBe('HEALTHY')->wal_status->toBe('HEALTHY')->verification_status->toBe('PASSED')->restore_test_status->toBe('PASSED')
        ->latest_full_backup_at->toBeInt()->backup_age_seconds->toBeLessThan(3700)->issues->toBe([]);
    expect(backupHealth(enforce: true))->production_blocker->toBeFalse();
});

it('grades backup age against the stale thresholds', function ($hours, $overall) {
    $stop = now()->timestamp - $hours * 3600;
    fakeBackupInfrastructure(info: pgbackrestInfo(backups: [['label' => '20260927-010000F', 'type' => 'full', 'stop' => $stop, 'repo' => 1],
        ['label' => '20260927-010000F', 'type' => 'full', 'stop' => $stop, 'repo' => 2]]));
    expect(backupHealth())->overall_status->toBe($overall)->backup_status->toBe($overall)->reason_code->toBe('BACKUP_STALE');
})->with([[31, 'WARNING'], [49, 'CRITICAL']]);

it('flags backups with errors and unencrypted repositories', function () {
    $now = now()->timestamp;
    fakeBackupInfrastructure(info: pgbackrestInfo(backups: [['label' => '20260927-010000F', 'type' => 'full', 'stop' => $now - 100, 'repo' => 1, 'error' => true],
        ['label' => '20260927-010000F', 'type' => 'full', 'stop' => $now - 100, 'repo' => 2]], ciphers: [1 => 'aes-256-cbc', 2 => 'none']));
    $codes = array_column(backupHealth()['issues'], 'code');
    expect($codes)->toContain('REPOSITORY_1_BACKUP_ERRORS', 'REPOSITORY_1_BACKUP_NOT_FOUND', 'REPOSITORY_2_NOT_ENCRYPTED');
});

it('keeps backups healthy but degrades overall status when WAL archiving is broken', function () {
    fakeBackupInfrastructure(wal: [...healthyWal(), 'status' => 'WAL_ARCHIVE_UNHEALTHY', 'reason_code' => 'ARCHIVE_FAILING']);
    expect(backupHealth())->backup_status->toBe('HEALTHY')->wal_status->toBe('WAL_ARCHIVE_UNHEALTHY')
        ->overall_status->toBe('CRITICAL')->reason_code->toBe('WAL_ARCHIVE_UNHEALTHY');
    config(['backup.pitr_required' => false]);
    expect(backupHealth())->overall_status->toBe('WARNING');
});

it('grades restore tests and verification from recorded evidence', function ($mutate, $state, $overall) {
    $evidence = healthyEvidence();
    $evidence['operations'] = $mutate($evidence['operations']);
    fakeBackupInfrastructure(evidence: $evidence);
    expect(backupHealth())->restore_test_status->toBe($state)->overall_status->toBe($overall);
})->with([
    'overdue' => [fn ($ops) => array_map(fn ($op) => $op['type'] === 'RESTORE_TEST' ? [...$op, 'started_at' => now()->timestamp - 41 * 86400, 'completed_at' => now()->timestamp - 40 * 86400] : $op, $ops), 'OVERDUE', 'WARNING'],
    'never recorded' => [fn ($ops) => array_values(array_filter($ops, fn ($op) => $op['type'] !== 'RESTORE_TEST')), 'NOT_RECORDED', 'WARNING'],
    'latest failed' => [fn ($ops) => array_map(fn ($op) => $op['type'] === 'RESTORE_TEST' ? [...$op, 'status' => 'FAILED'] : $op, $ops), 'FAILED', 'CRITICAL'],
]);

it('reports COMMAND_TIMEOUT as UNKNOWN in development and CRITICAL when enforced', function () {
    fakeBackupInfrastructure(processes: ['* version' => Process::result("pgBackRest 2.54.0\n"),
        '* info' => fn () => new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess(['pgbackrest']), SymfonyTimeout::TYPE_GENERAL), new FakeProcessResult)]);
    expect(backupHealth())->overall_status->toBe('UNKNOWN')->infrastructure_status->toBe('UNKNOWN')->reason_code->toBe('COMMAND_TIMEOUT');
    expect(backupHealth(enforce: true))->overall_status->toBe('CRITICAL');
});

it('runs only fixed argument vectors with a bounded timeout and scrubbed environment', function () {
    $_ENV['PGBACKREST_REPO1_CIPHER_PASS'] = 'TOP_SECRET';
    fakeBackupInfrastructure();
    config(['backup.pgbackrest.run_as' => 'postgres', 'backup.pgbackrest.sudo_binary' => PHP_BINARY]);
    backupHealth();
    Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, '-n', '-u', 'postgres', PHP_BINARY,
        '--stanza=euisis', '--output=json', '--log-level-console=off', '--log-level-stderr=warn', '--log-level-file=off', 'info']
        && $process->timeout === 20 && $process->environment['PGBACKREST_REPO1_CIPHER_PASS'] === false && $process->environment['LC_ALL'] === 'C');
    unset($_ENV['PGBACKREST_REPO1_CIPHER_PASS']);
});

it('never leaks secrets from tool output, errors, status or logs', function () {
    Log::spy();
    fakeBackupInfrastructure(processes: infoFails(25, 'ERROR: [025]: repo1-cipher-pass=TOP_SECRET DB_PASSWORD=TOP_SECRET'));
    $status = backupHealth(enforce: true);
    expect(json_encode($status))->not->toContain('TOP_SECRET', 'cipher-pass')->and($status['diagnostics']['stderr_summary'])->toBe('PGBACKREST_ERROR_025');
    Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'error' && $message === 'backup.health.failed'
        && array_keys($context) === ['overall_status', 'reason_code', 'component', 'exit_code', 'environment', 'checked_at']
        && $context['reason_code'] === 'COMMAND_FAILED' && $context['exit_code'] === 25 && ! str_contains(json_encode($context), 'TOP_SECRET'));
    fakeBackupInfrastructure();
    expect(json_encode(backupHealth()))->not->toContain('TOP_SECRET', 'annotation');
});

it('caches probes briefly and re-probes on an explicit refresh', function () {
    fakeBackupInfrastructure();
    config(['backup.cache_seconds' => 60]);
    $service = app(BackupStatusService::class);
    $service->status();
    $service->summary();
    Process::assertRanTimes(ranCommand('info'), 1);
    $user = User::factory()->create();
    Permission::findOrCreate('backups.view_status', 'web');
    $user->givePermissionTo('backups.view_status');
    $this->actingAs($user)->post(route('backups.refresh'))->assertRedirect();
    Process::assertRanTimes(ranCommand('info'), 2);
    $this->actingAs(User::factory()->create())->post(route('backups.refresh'))->assertForbidden();
});

it('reads the report driver with distinct reasons', function () {
    fakeWal(healthyWal());
    config(['backup.enabled' => true, 'backup.driver' => 'report', 'backup.cache_seconds' => 0, 'backup.rpo' => 'approved', 'backup.rto' => 'approved', 'backup.alert_user_ids' => [1]]);
    config(['backup.report_path' => base_path('missing/status.json')]);
    expect(backupHealth())->reason_code->toBe('REPORT_NOT_FOUND')->infrastructure_status->toBe('UNAVAILABLE');
    $path = writeBackupReport(healthyBackupReport());
    file_put_contents($path, '{not json');
    expect(backupHealth())->reason_code->toBe('INVALID_REPORT');
    writeBackupReport([...healthyBackupReport(), 'observed_at' => now()->timestamp - 3600]);
    expect(backupHealth())->reason_code->toBe('REPORT_STALE')->overall_status->toBe('UNKNOWN');
    writeBackupReport(healthyBackupReport());
    expect(backupHealth())->overall_status->toBe('HEALTHY')->driver->toBe('report');
});

it('rejects evidence that misattributes repositories', function ($type, $repository) {
    fakeWal(healthyWal());
    $report = healthyBackupReport();
    $report['operations'][0] = [...$report['operations'][0], 'type' => $type, 'repository' => $repository];
    config(['backup.enabled' => true, 'backup.driver' => 'report', 'backup.cache_seconds' => 0]);
    writeBackupReport($report);
    expect(backupHealth())->verification_status->toBe('NOT_RECORDED');
})->with([['LOGICAL_BACKUP', 1], ['VERIFY', null]]);

it('treats the supplementary logical backup as a warning only', function ($case, $issue) {
    $evidence = healthyEvidence();
    config(['backup.logical_enabled' => $case !== 'disabled_failure']);
    if ($case !== 'missing') {
        $evidence['operations'][] = ['id' => (string) Str::uuid(), 'type' => 'LOGICAL_BACKUP', 'repository' => null,
            'status' => in_array($case, ['failed', 'disabled_failure'], true) ? 'FAILED' : 'SUCCEEDED',
            'started_at' => now()->timestamp - ($case === 'stale' ? 40 * 86400 : 900), 'completed_at' => now()->timestamp - ($case === 'stale' ? 40 * 86400 - 60 : 600)];
    }
    fakeBackupInfrastructure(evidence: $evidence);
    $status = backupHealth();
    expect($status['overall_status'])->toBe($issue ? 'WARNING' : 'HEALTHY');
    if ($issue) {
        expect(array_column($status['issues'], 'code'))->toContain($issue);
    }
})->with([
    ['healthy', null], ['missing', 'LOGICAL_BACKUP_MISSING_OR_STALE'], ['stale', 'LOGICAL_BACKUP_MISSING_OR_STALE'],
    ['failed', 'LOGICAL_BACKUP_FAILED_OR_STUCK'], ['disabled_failure', 'LOGICAL_BACKUP_FAILED_OR_STUCK'],
]);

it('rejects invalid policy values without probing', function ($key, $value) {
    fakeBackupInfrastructure();
    config(['backup.'.$key => $value]);
    expect(backupHealth())->reason_code->toBe('INVALID_CONFIGURATION');
    Process::assertNothingRan();
})->with([
    ['retention_full_count', -1], ['stale_hours', 0], ['critical_hours', 1], ['storage_warning_percent', 101],
    ['logical_stale_days', -5], ['cache_seconds', 3600], ['pgbackrest.timeout_seconds', 0],
    ['repository_names', [1 => 'Primary', 2 => str_repeat('x', 61)]],
]);

it('keeps readiness evidence gaps separate from operational health', function () {
    $evidence = healthyEvidence();
    $evidence['controls']['key_recovery'] = false;
    fakeBackupInfrastructure(evidence: $evidence);
    config(['backup.rpo' => 'NEEDS_DECISION']);
    $status = backupHealth(enforce: true);
    expect($status)->overall_status->toBe('HEALTHY')->readiness->toBe('NOT_READY')->production_blocker->toBeTrue()
        ->and(array_column($status['issues'], 'severity'))->each->toBe('READINESS');
});

it('renders the page when the backup tables are missing and reports why', function () {
    fakeBackupInfrastructure();
    Schema::drop('backup_operations');
    Schema::drop('backup_restore_requests');
    $user = User::factory()->create();
    foreach (['backups.view_status', 'backups.view_history'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo(['backups.view_status', 'backups.view_history']);
    $this->actingAs($user)->get(route('backups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('status.reason_code', 'HISTORY_UNAVAILABLE')->where('status.diagnostics', [])->has('history', 0));
});

it('explains backup readiness in production:readiness for each environment', function () {
    config(['backup.enabled' => false]);
    fakeWal(healthyWal());
    fakeEvidence(healthyEvidence());
    $this->artisan('production:readiness')->expectsOutputToContain('NOT_CONFIGURED (disabled)')->expectsOutputToContain('NO for this environment');
    $this->artisan('production:readiness', ['--strict' => true])->expectsOutputToContain('CRITICAL')
        ->expectsOutputToContain('Production backup infrastructure is unavailable')->assertExitCode(1);
});
