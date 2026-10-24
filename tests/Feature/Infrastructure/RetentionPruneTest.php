<?php

declare(strict_types=1);

use App\Models\Grievance;
use App\Models\SystemSetting;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\Retention\RetentionMatrix;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GrievanceScenario;

/*
 * Data retention (ADR 0004): record classes are kept until their retention is
 * approved (NEEDS_POLICY_DECISION_RETENTION); an approved class is archived or
 * purged only with its approval reference. Operational housekeeping
 * (notifications, reminders) keeps its automatic windows. Financial records,
 * employee history, documents and case records have no deletion path.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

function retentionAudit(string $age, ?string $type = null, ?string $id = null): string
{
    $key = (string) Str::uuid();
    DB::table('audit_logs')->insert([
        'id' => $key, 'event_type' => 'TEST_EVENT', 'auditable_type' => $type, 'auditable_id' => $id,
        'created_at' => Carbon::parse($age),
    ]);

    return $key;
}

function approveRetention(string $class, string $action, ?int $days = null): void
{
    config(["retention.records.{$class}" => ['action' => $action, 'days' => $days, 'approval' => 'RET-APPROVAL-'.strtoupper($class)]]);
}

it('keeps every record class until its retention is approved', function (): void {
    $old = retentionAudit('-2000 days');
    DB::table('card_verifications')->insert(['id' => (string) Str::uuid(), 'result_code' => 'ok', 'allowed' => true, 'created_at' => now()->subDays(2000), 'updated_at' => now()]);

    $this->artisan('data:prune-retention')
        ->expectsOutputToContain('audit_logs: kept (NEEDS_POLICY_DECISION_RETENTION)')
        ->assertExitCode(0);

    expect(DB::table('audit_logs')->where('id', $old)->exists())->toBeTrue()
        ->and(DB::table('card_verifications')->count())->toBe(1)
        ->and(collect(app(RetentionMatrix::class)->classes())->pluck('state')->unique()->all())->toBe([RetentionMatrix::NEEDS_POLICY_DECISION]);
});

it('purges approved audit logs and security events past the approved period, and records the approval', function (): void {
    SystemSetting::query()->updateOrCreate(
        ['group' => 'security', 'key' => 'audit_retention_days'],
        ['value' => '400', 'type' => 'integer', 'label_en' => 'Audit Retention Days'],
    );
    app(SystemSettingsService::class)->clearCache();
    approveRetention('audit_logs', 'purge_when_authorized');
    $old = retentionAudit('-401 days');
    $kept = retentionAudit('-399 days');
    DB::table('security_events')->insert([
        ['id' => (string) Str::uuid(), 'event_type' => 'login_failed', 'created_at' => now()->subDays(401)],
        ['id' => (string) Str::uuid(), 'event_type' => 'login_failed', 'created_at' => now()->subDays(10)],
    ]);

    $this->artisan('data:prune-retention')->assertExitCode(0);

    expect(DB::table('audit_logs')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('id', $kept)->exists())->toBeTrue()
        ->and(DB::table('security_events')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'RETENTION_CLEANUP')->where('new_values', 'like', '%RET-APPROVAL-AUDIT_LOGS%')->exists())->toBeTrue();
});

it('archives approved verification logs with a checksum manifest before removing them', function (): void {
    Storage::fake('local');
    approveRetention('verification_logs', 'archive', 365);
    DB::table('card_verifications')->insert([
        ['id' => (string) Str::uuid(), 'result_code' => 'old', 'allowed' => true, 'created_at' => now()->subDays(366), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'result_code' => 'new', 'allowed' => true, 'created_at' => now()->subDays(30), 'updated_at' => now()],
    ]);

    $this->artisan('data:prune-retention')->assertExitCode(0);

    expect(DB::table('card_verifications')->pluck('result_code')->all())->toBe(['new']);
    $manifest = array_map(fn (string $line): array => json_decode($line, true), array_filter(explode("\n", Storage::disk('local')->get('retention-archive/verification_logs/manifest.ndjson'))));
    $archived = gzdecode(Storage::disk('local')->get($manifest[0]['file']));
    expect($manifest)->toHaveCount(1)
        ->and($manifest[0])->table->toBe('card_verifications')->rows->toBe(1)->approval->toBe('RET-APPROVAL-VERIFICATION_LOGS')
        ->and(hash('sha256', $archived))->toBe($manifest[0]['sha256'])
        ->and($archived)->toContain('"result_code":"old"');
});

it('needs the approval reference and the period, not just an action', function (): void {
    config(['retention.records.api_logs' => ['action' => 'purge_when_authorized', 'days' => 90, 'approval' => null]]);
    expect(app(RetentionMatrix::class)->for('api_logs')['state'])->toBe(RetentionMatrix::NEEDS_POLICY_DECISION);

    config(['retention.records.api_logs' => ['action' => 'purge_when_authorized', 'days' => null, 'approval' => 'RET-1']]);
    expect(app(RetentionMatrix::class)->for('api_logs')['state'])->toBe(RetentionMatrix::NEEDS_POLICY_DECISION);

    // A class the application cannot process is approved but never deleted automatically.
    config(['retention.records.employee_master' => ['action' => 'purge_when_authorized', 'days' => 3650, 'approval' => 'RET-2']]);
    expect(app(RetentionMatrix::class)->for('employee_master')['state'])->toBe(RetentionMatrix::APPROVED_NOT_AUTOMATED);
});

it('keeps audit rows of a grievance until the case is archived and off legal hold', function (): void {
    Storage::fake('local');
    approveRetention('audit_logs', 'purge_when_authorized');
    $s = GrievanceScenario::build();
    $this->actingAs($s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Overtime not paid', 'description' => 'Narrative.', 'category_id' => $s->category->id, 'submit' => true,
        'files' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')], 'file_types' => ['document'],
        ...GrievanceScenario::application(),
    ])->assertRedirect();
    $grievance = Grievance::query()->latest('created_at')->firstOrFail();
    $row = retentionAudit('-1000 days', Grievance::class, $grievance->id);

    $this->artisan('data:prune-retention')->assertExitCode(0);
    expect(DB::table('audit_logs')->where('id', $row)->exists())->toBeTrue();

    $grievance->forceFill(['archived_at' => now(), 'legal_hold' => true])->save();
    $this->artisan('data:prune-retention')->assertExitCode(0);
    expect(DB::table('audit_logs')->where('id', $row)->exists())->toBeTrue();

    $grievance->forceFill(['legal_hold' => false])->save();
    $this->artisan('data:prune-retention')->assertExitCode(0);
    expect(DB::table('audit_logs')->where('id', $row)->exists())->toBeFalse();
})->skip(fn () => ! class_exists(GrievanceCaseService::class));

it('prunes read notifications after 90 days and any notification after 365', function (): void {
    $make = fn (string $age, ?string $read) => DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1,
        'data' => '{}', 'read_at' => $read ? Carbon::parse($read) : null,
        'created_at' => Carbon::parse($age), 'updated_at' => Carbon::parse($age),
    ]);
    $make('-100 days', '-95 days');   // read, old: pruned
    $make('-100 days', null);         // unread, under a year: kept
    $make('-400 days', null);         // any, over a year: pruned
    $make('-10 days', '-5 days');     // read, recent: kept

    $this->artisan('data:prune-retention')->assertExitCode(0);

    expect(DB::table('notifications')->count())->toBe(2);
});

it('changes nothing on a dry run', function (): void {
    approveRetention('audit_logs', 'purge_when_authorized');
    retentionAudit('-2000 days');

    $this->artisan('data:prune-retention', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('reports every class with its state and row count', function (): void {
    retentionAudit('-10 days');

    $this->artisan('data:retention-report')
        ->expectsOutputToContain('12 class(es) await the retention decision')
        ->assertSuccessful();
});

it('runs daily on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'data:prune-retention'));

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
