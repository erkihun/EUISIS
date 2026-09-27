<?php

use App\Enums\AuditEventType;
use App\Events\BackupHealthAlert;
use App\Models\AuditLog;
use App\Models\BackupOperation;
use App\Models\BackupRestoreRequest;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\BackupHealthNotification;
use App\Services\Backup\BackupMonitor;
use App\Services\Backup\BackupStatusService;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Fixtures/backup_report.php';

function backupUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

it('denies unauthenticated and unauthorized backup access', function () {
    $this->get(route('backups.index'))->assertRedirect(route('login'));
    $this->actingAs(backupUser())->get(route('backups.index'))->assertForbidden();
});

it('withholds backup infrastructure permissions from operational and organization admins', function () {
    foreach (['City Admin', 'Public Service Bureau Admin', 'Organizational Admin'] as $role) {
        expect(collect(DefaultRoleMatrix::permissionsFor($role))->filter(fn ($p) => str_starts_with($p, 'backups.')))->toHaveCount(0);
    }
    expect(DefaultRoleMatrix::permissionsFor('System Admin'))->toContain('backups.view_status', 'backups.restore_approve');
});

it('shows sanitized health, ignores command parameters, and audits access', function () {
    fakeBackupInfrastructure();
    $this->actingAs(backupUser(['backups.view_status']))
        ->get(route('backups.index', ['command' => 'echo DO_NOT_EXECUTE', 'stanza' => 'other; rm -rf /']))
        ->assertOk()->assertDontSee('TOP_SECRET')
        ->assertInertia(fn (Assert $page) => $page->component('System/BackupRecovery')->where('status.overall_status', 'HEALTHY')
            ->where('status.stanza', 'euisis')->where('status.diagnostics', [])->has('history', 0)->has('status.operations', 0));
    expect(AuditLog::where('event_type', AuditEventType::BackupStatusViewed)->count())->toBe(1);
    // Only the two fixed read-only commands ran; request parameters never reach a process.
    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertDidntRun(fn ($process) => str_contains(implode(' ', (array) $process->command), 'DO_NOT_EXECUTE')
        || str_contains(implode(' ', (array) $process->command), 'rm -rf') || in_array('--stanza=other; rm -rf /', (array) $process->command, true));
});

function restoreRequest(User $requester, string $status = 'REQUESTED'): BackupRestoreRequest
{
    return BackupRestoreRequest::create(['requested_by' => $requester->id, 'restore_type' => 'POINT_IN_TIME', 'incident_reference' => 'INC-002',
        'reason' => 'Accidental bulk deletion of assignments.', 'target_time' => now()->subHour(), 'status' => $status]);
}

it('requires request permission and validates each restore type', function () {
    $valid = ['restore_type' => 'POINT_IN_TIME', 'incident_reference' => 'INC-001', 'reason' => 'Accidental deletion of employee records.',
        'target_time' => now()->subHour()->format('Y-m-d\TH:i:sP')];
    $this->actingAs(backupUser())->post(route('backups.requests.store'), $valid)->assertForbidden();
    $requester = backupUser(['backups.restore_request']);
    $this->actingAs($requester)->post(route('backups.requests.store'), $valid)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('backups.requests.store'), ['restore_type' => 'LATEST', 'incident_reference' => 'INC-003', 'reason' => 'Primary database server lost.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('backups.requests.store'), ['restore_type' => 'BACKUP_SET', 'incident_reference' => 'INC-004', 'reason' => 'Restore the pre-migration backup.', 'backup_reference' => '20260927-010000F'])
        ->assertSessionHasNoErrors();
    foreach ([
        [['target_time' => now()->addHour()->format('Y-m-d\TH:i:sP')], 'target_time'],
        [['target_time' => null], 'target_time'],
        [['restore_type' => 'LATEST'], 'target_time'],
        [['restore_type' => 'BACKUP_SET', 'target_time' => null, 'backup_reference' => '20260927-010000F; rm -rf /'], 'backup_reference'],
        [['restore_type' => 'EVERYTHING'], 'restore_type'],
        [['incident_reference' => '$(reboot)'], 'incident_reference'],
        [['reason' => 'short'], 'reason'],
    ] as [$override, $field]) {
        $this->actingAs($requester)->post(route('backups.requests.store'), [...$valid, ...$override])->assertSessionHasErrors($field);
    }
    expect(BackupRestoreRequest::count())->toBe(3)
        ->and(AuditLog::where('event_type', AuditEventType::RestoreRequested)->count())->toBe(3);
});

it('runs the staged recovery workflow with separate approval and operator-only execution', function () {
    $requester = backupUser(['backups.restore_request', 'backups.restore_review', 'backups.restore_approve', 'backups.run']);
    $approver = backupUser(['backups.restore_approve']);
    $record = restoreRequest($requester);
    $url = route('backups.requests.transition', $record);
    $cli = fn (string $action, ?string $evidence = null) => $this->artisan('backup:restore-record', array_filter(['request' => $record->id, 'action' => $action, '--actor' => $requester->id, '--evidence' => $evidence]));

    $this->actingAs($approver)->post($url, ['action' => 'approve'])->assertSessionHasErrors('action');
    $this->actingAs(backupUser())->post($url, ['action' => 'review'])->assertForbidden();
    foreach (['complete', 'test-start', 'start', 'shell'] as $operatorOnly) {
        $this->actingAs($requester)->post($url, ['action' => $operatorOnly, 'evidence_reference' => 'EVID-001'])->assertSessionHasErrors('action');
    }
    $this->actingAs($requester)->post($url, ['action' => 'review'])->assertSessionHasNoErrors();
    expect($record->fresh()->status)->toBe('UNDER_REVIEW');
    $this->actingAs(backupUser(['backups.restore_review']))->post($url, ['action' => 'approve'])->assertForbidden();
    $this->actingAs($requester)->post($url, ['action' => 'approve'])->assertForbidden();
    $this->actingAs($approver)->post($url, ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($record->fresh()->status)->toBe('APPROVED');

    // Production restore cannot be authorized or started before a verified isolated test.
    $this->actingAs($approver)->post($url, ['action' => 'authorize-production'])->assertSessionHasErrors('action');
    $cli('start', 'SAFEGUARD-001')->assertFailed();
    $cli('complete', 'EVID-003')->assertFailed();
    $cli('test-start')->assertFailed();
    $cli('test-start', 'TEST-ENV-001')->assertSuccessful();
    expect($record->fresh()->started_at)->not->toBeNull();
    $cli('test-pass', 'VALIDATION-001')->assertSuccessful();
    expect($record->fresh()->status)->toBe('TEST_RESTORE_VERIFIED');

    $this->actingAs($requester)->post($url, ['action' => 'authorize-production'])->assertForbidden();
    $this->actingAs($approver)->post($url, ['action' => 'authorize-production'])->assertSessionHasNoErrors();
    expect($record->fresh())->status->toBe('PRODUCTION_RESTORE_AUTHORIZED')->production_authorized_by->toBe($approver->id);
    $cli('complete', 'EVID-003')->assertFailed();
    $cli('start')->assertFailed();
    $cli('start', 'SAFEGUARD-001')->assertSuccessful();
    $cli('fail')->assertSuccessful();

    $record->refresh();
    expect($record->status)->toBe('FAILED')->and($record->completed_at)->not->toBeNull()
        ->and($record->failure_summary)->toBe('PRODUCTION_RESTORE_FAILED: consult restricted incident record.');
    foreach ([AuditEventType::RestoreReviewed, AuditEventType::RestoreApproved, AuditEventType::RestoreTestStarted, AuditEventType::RestoreTestPassed,
        AuditEventType::ProductionRestoreAuthorized, AuditEventType::ProductionRestoreStarted, AuditEventType::ProductionRestoreFailed] as $event) {
        expect(AuditLog::where('event_type', $event)->count())->toBe(1, $event->value);
    }
});

it('records failed isolated tests with a fixed failure code', function () {
    $operator = backupUser(['backups.run']);
    $record = restoreRequest(backupUser(), 'TEST_RESTORE_RUNNING');
    $this->artisan('backup:restore-record', ['request' => $record->id, 'action' => 'test-fail', '--actor' => $operator->id, '--evidence' => 'password=hunter2'])->assertSuccessful();
    expect($record->fresh())->status->toBe('FAILED')->failure_summary->toBe('TEST_RESTORE_FAILED: consult restricted incident record.')
        ->evidence_reference->toBeNull();
    $this->artisan('backup:restore-record', ['request' => $record->id, 'action' => 'test-start', '--actor' => backupUser()->id, '--evidence' => 'EVID-1'])->assertFailed();
});

it('lets requesters withdraw only their own open requests', function () {
    $requester = backupUser(['backups.restore_request']);
    $other = backupUser(['backups.restore_request']);
    $mine = restoreRequest($requester);
    $this->actingAs($other)->post(route('backups.requests.transition', $mine), ['action' => 'cancel'])->assertForbidden();
    $this->actingAs($requester)->post(route('backups.requests.transition', $mine), ['action' => 'cancel'])->assertSessionHasNoErrors();
    expect($mine->fresh()->status)->toBe('CANCELLED');
    $authorized = restoreRequest($other, 'PRODUCTION_RESTORE_AUTHORIZED');
    $this->actingAs(backupUser(['backups.restore_approve']))->post(route('backups.requests.transition', $authorized), ['action' => 'cancel'])->assertSessionHasNoErrors();
    $restoring = restoreRequest($requester, 'RESTORING');
    $this->actingAs($requester)->post(route('backups.requests.transition', $restoring), ['action' => 'cancel'])->assertSessionHasErrors('action');
    expect(AuditLog::where('event_type', AuditEventType::RestoreCancelled)->count())->toBe(2);
});

it('exposes only allowlisted policy metadata', function () {
    fakeBackupInfrastructure();
    config(['backup.report_path' => '/srv/ops-only/status.json', 'backup.pgbackrest.config' => null, 'backup.alert_user_ids' => [987654]]);
    $this->actingAs(backupUser(['backups.view_status', 'backups.manage_policy']))->get(route('backups.index'))
        ->assertOk()->assertDontSee('/srv/ops-only')->assertDontSee('987654')
        ->assertInertia(fn (Assert $page) => $page->has('policy', 12)->where('policy.retention_full_count', 12)->missing('policy.report_path'));
});

it('lists sanitized inventory and summarizes health without secrets', function () {
    fakeBackupInfrastructure();
    $this->artisan('backup:list')->expectsOutputToContain('20260927-010000F')->doesntExpectOutputToContain('TOP_SECRET')->assertSuccessful();
    $this->artisan('backup:list', ['--repo' => '1; rm -rf /'])->assertFailed();
    $this->artisan('backup:health', ['-v' => true])->expectsOutputToContain('Overall Status: HEALTHY')->expectsOutputToContain('pgBackRest Binary: FOUND')
        ->expectsOutputToContain('parser: OK')->doesntExpectOutputToContain('TOP_SECRET')->assertSuccessful();
    config(['backup.enabled' => false]);
    $this->artisan('backup:health')->expectsOutputToContain('Overall Status: NOT_CONFIGURED')->expectsOutputToContain('Reason Code: NOT_CONFIGURED')
        ->expectsOutputToContain('Production Blocker: NO (not enforced in this environment)')->assertFailed();
    $this->artisan('backup:list')->expectsOutputToContain('Backup inventory unavailable: NOT_CONFIGURED')->assertFailed();
});

it('shows the backup card on the dashboard only to authorized users', function () {
    fakeBackupInfrastructure();
    $this->actingAs(backupUser(['dashboard.view', 'backups.view_status']))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('backupHealth.overall_status', 'HEALTHY')->has('backupHealth.operations', 0)->where('backupHealth.diagnostics', []));
    $this->actingAs(backupUser(['dashboard.view']))->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('backupHealth', null));
});

it('imports history once and sends alerts only to authorized configured recipients', function ($failure) {
    Event::fake([BackupHealthAlert::class]);
    Notification::fake();
    $operator = backupUser(['backups.view_status', 'backups.view_logs']);
    $ordinary = backupUser();
    config(['backup.alert_user_ids' => [$operator->id, $ordinary->id]]);
    $evidence = healthyEvidence();
    $info = null;
    if ($failure === 'stale') {
        $stop = now()->subHours(31)->timestamp;
        $info = pgbackrestInfo(backups: [['label' => '20260927-010000F', 'type' => 'full', 'stop' => $stop, 'repo' => 1],
            ['label' => '20260927-010000F', 'type' => 'full', 'stop' => $stop, 'repo' => 2]]);
    } else {
        $evidence['operations'][0]['type'] = $failure;
        $evidence['operations'][0]['status'] = 'FAILED';
    }
    fakeBackupInfrastructure(info: $info, evidence: $evidence);
    $service = app(BackupStatusService::class);
    app(BackupMonitor::class)->sync($service->status());
    app(BackupMonitor::class)->sync($service->status());
    expect(BackupOperation::count())->toBe(4)->and(AuditLog::count())->toBe(8);
    Event::assertDispatchedTimes(BackupHealthAlert::class, 1);
    Notification::assertSentToTimes($operator, BackupHealthNotification::class, 1);
    Notification::assertNotSentTo($ordinary, BackupHealthNotification::class);
})->with(['FULL_BACKUP', 'RESTORE_TEST', 'stale']);

it('imports logical backup history without a repository and audits its failure', function () {
    Event::fake([BackupHealthAlert::class]);
    $evidence = healthyEvidence();
    $evidence['operations'][] = ['id' => (string) Str::uuid(), 'type' => 'LOGICAL_BACKUP', 'repository' => null,
        'status' => 'FAILED', 'started_at' => now()->timestamp - 900, 'completed_at' => now()->timestamp - 600];
    fakeBackupInfrastructure(evidence: $evidence);
    app(BackupMonitor::class)->sync(app(BackupStatusService::class)->status());
    expect(BackupOperation::where('type', 'LOGICAL_BACKUP')->first())->repository->toBeNull()->status->toBe('FAILED')
        ->and(AuditLog::where('event_type', AuditEventType::BackupFailed)->count())->toBe(1);
    Event::assertDispatched(BackupHealthAlert::class, fn ($event) => $event->state === 'WARNING');
});

it('does not alert for an intentionally unconfigured development environment', function () {
    Event::fake([BackupHealthAlert::class]);
    config(['backup.enabled' => false, 'backup.alert_user_ids' => [1]]);
    fakeWal(healthyWal());
    app(BackupMonitor::class)->sync(app(BackupStatusService::class)->status());
    Event::assertNotDispatched(BackupHealthAlert::class);
    app(BackupMonitor::class)->sync(app(BackupStatusService::class)->status(enforce: true));
    Event::assertDispatched(BackupHealthAlert::class, fn ($event) => $event->state === 'CRITICAL');
});

it('does not emit critical alerts for healthy backup reports', function () {
    Event::fake([BackupHealthAlert::class]);
    fakeBackupInfrastructure();
    app(BackupMonitor::class)->sync(app(BackupStatusService::class)->status());
    Event::assertNotDispatched(BackupHealthAlert::class);
});

it('does not expose retention mutation or backup download endpoints', function () {
    $this->actingAs(backupUser(['backups.manage_policy']))->delete('/system/backup-recovery/repository')->assertNotFound();
    $this->get('/system/backup-recovery/download')->assertNotFound();
});
