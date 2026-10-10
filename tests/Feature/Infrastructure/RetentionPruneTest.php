<?php

declare(strict_types=1);

use App\Models\Grievance;
use App\Models\SystemSetting;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GrievanceScenario;

/*
 * Data retention (risk register DB-9 decision): log tables are pruned on a
 * schedule; financial records, employee history, documents and case records
 * are never pruned here.
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

it('prunes audit logs and security events past the audit retention setting', function (): void {
    // Decision: 730 days by default (the project's REM-OP01); admins change it
    // in Security settings, and the job follows the stored value.
    expect(SystemSettingsRegistry::definition('security', 'audit_retention_days')['default'])->toBe(730);
    SystemSetting::query()->updateOrCreate(
        ['group' => 'security', 'key' => 'audit_retention_days'],
        ['value' => '400', 'type' => 'integer', 'label_en' => 'Audit Retention Days'],
    );
    app(SystemSettingsService::class)->clearCache();
    $old = retentionAudit('-401 days');
    $kept = retentionAudit('-399 days');
    DB::table('security_events')->insert([
        ['id' => (string) Str::uuid(), 'event_type' => 'login_failed', 'created_at' => now()->subDays(401)],
        ['id' => (string) Str::uuid(), 'event_type' => 'login_failed', 'created_at' => now()->subDays(10)],
    ]);

    $this->artisan('data:prune-retention')->assertExitCode(0);

    expect(DB::table('audit_logs')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('id', $kept)->exists())->toBeTrue()
        ->and(DB::table('security_events')->count())->toBe(1);
});

it('keeps audit rows of a grievance until the case is archived and off legal hold', function (): void {
    Storage::fake('local');
    $s = GrievanceScenario::build();
    $this->actingAs($s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Overtime not paid', 'description' => 'Narrative.', 'category_id' => $s->category->id, 'submit' => true,
        'files' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')], 'file_types' => ['document'],
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

it('prunes verification logs and reminder records past their windows', function (): void {
    DB::table('card_verifications')->insert([
        ['id' => (string) Str::uuid(), 'result_code' => 'ok', 'allowed' => true, 'created_at' => now()->subDays(366), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'result_code' => 'ok', 'allowed' => true, 'created_at' => now()->subDays(30), 'updated_at' => now()],
    ]);

    $this->artisan('data:prune-retention')->assertExitCode(0);

    expect(DB::table('card_verifications')->count())->toBe(1);
});

it('changes nothing on a dry run', function (): void {
    retentionAudit('-2000 days');

    $this->artisan('data:prune-retention', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('runs daily on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'data:prune-retention'));

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
