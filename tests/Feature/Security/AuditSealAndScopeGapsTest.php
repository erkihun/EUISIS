<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Audit\AuditLogSealer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Security hardening after the Core stages: audit-log tamper evidence
 * (audit:seal / audit:verify) and the report of accounts the explicit-scope
 * rule confines (access:scope-gaps).
 */

function sealAuditRow(string $reason): void
{
    app(WriteAuditLogAction::class)->execute(AuditEventType::cases()[0], null, null, null, null, ['note' => $reason], $reason);
}

it('seals each finished day and detects a changed, added or removed audit row', function (): void {
    $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    sealAuditRow('first');
    sealAuditRow('second');
    $this->travelTo(Carbon::parse('2026-10-21 11:00'));
    sealAuditRow('third');
    $this->travelTo(Carbon::parse('2026-10-23 09:00'));

    $this->artisan('audit:seal')->expectsOutputToContain('Sealed 3 day(s), 2026-10-20 to 2026-10-22.')->assertSuccessful();
    $this->artisan('audit:seal')->expectsOutputToContain('Nothing to seal')->assertSuccessful();
    $this->artisan('audit:verify')->expectsOutputToContain('All 3 sealed day(s) verify')->assertSuccessful();

    // Someone with database access edits a sealed row.
    DB::table('audit_logs')->where('reason', 'second')->update(['reason' => 'edited']);
    $this->artisan('audit:verify')->expectsOutputToContain('MODIFIED')->assertFailed();
    DB::table('audit_logs')->where('reason', 'edited')->update(['reason' => 'second']);
    $this->artisan('audit:verify')->assertSuccessful();

    // …removes one…
    $removed = DB::table('audit_logs')->where('reason', 'third')->first();
    DB::table('audit_logs')->where('id', $removed->id)->delete();
    $this->artisan('audit:verify')->expectsOutputToContain('MODIFIED')->assertFailed();
    DB::table('audit_logs')->insert((array) $removed);
    $this->artisan('audit:verify')->assertSuccessful();

    // …or rewrites a seal to match.
    DB::table(AuditLogSealer::TABLE)->where('sealed_on', '2026-10-20')->update(['digest' => str_repeat('0', 64)]);
    $this->artisan('audit:verify')->expectsOutputToContain('CHAIN_BROKEN')->assertFailed();
});

it('keeps verifying after the application key is rotated', function (): void {
    $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    sealAuditRow('before rotation');
    $this->travelTo(Carbon::parse('2026-10-21 09:00'));
    $this->artisan('audit:seal')->assertSuccessful();

    $old = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
    $this->artisan('audit:verify')->assertSuccessful();

    config(['app.previous_keys' => []]);
    $this->artisan('audit:verify')->expectsOutputToContain('UNKNOWN_KEY')->assertFailed();
});

it('drops the seals of days removed by retention', function (): void {
    $this->travelTo(Carbon::parse('2024-01-10 10:00'));
    sealAuditRow('old');
    $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    sealAuditRow('recent');
    $this->travelTo(Carbon::parse('2026-10-21 09:00'));
    $this->artisan('audit:seal')->assertSuccessful();

    // Approved audit retention (ADR 0004); the period is the Security setting (730 days by default).
    config(['retention.records.audit_logs' => ['action' => 'purge_when_authorized', 'days' => null, 'approval' => 'RET-AUDIT-1']]);
    $this->artisan('data:prune-retention')->assertSuccessful();

    // Audit retention of 730 days: rows and seals through 2024-10-21 go.
    expect(DB::table(AuditLogSealer::TABLE)->where('sealed_on', '<=', '2024-10-21')->count())->toBe(0)
        ->and(DB::table(AuditLogSealer::TABLE)->where('sealed_on', '2024-10-22')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('reason', 'old')->exists())->toBeFalse();
    $this->artisan('audit:verify')->assertSuccessful();
});

it('lists accounts the explicit-scope rule confines, without contact details', function (): void {
    Role::findOrCreate('HR Officer', 'web');
    Role::findOrCreate('City Records Reader', 'web');
    DB::table('roles')->where('name', 'City Records Reader')->update(['scope_type' => 'global']);

    $confined = User::factory()->create(['name' => 'Confined Officer', 'email' => 'confined@example.test'])->assignRole('HR Officer');
    $scoped = User::factory()->create(['name' => 'Scoped Officer'])->assignRole('HR Officer');
    UserOrganizationScope::query()->create(['user_id' => $scoped->id, 'scope_type' => OrganizationScopeType::Citywide, 'is_active' => true]);
    User::factory()->create(['name' => 'Global Reader'])->assignRole('City Records Reader');
    User::factory()->create(['name' => 'Direct Grant'])->givePermissionTo(Permission::findOrCreate('employees.view', 'web'));

    Artisan::call('access:scope-gaps', ['--json' => true]);
    $output = Artisan::output();
    $findings = collect(json_decode($output, true))->pluck('finding', 'name')->all();

    expect($findings)->toBe(['Confined Officer' => 'CONFINED_NO_ACCESS', 'Direct Grant' => 'DIRECT_PERMISSIONS_UNSCOPED'])
        ->and($output)->not->toContain('confined@example.test');
    $this->artisan('access:scope-gaps')->expectsOutputToContain('2 account(s) need an explicit organization scope')->assertSuccessful();
});

it('blocks go-live while the explicit-scope rule is switched off', function (): void {
    config(['security.organization_scope.require_explicit_scope' => false]);

    $this->artisan('production:readiness', ['--strict' => true])
        ->expectsOutputToContain('Organization-level roles need an explicit scope')
        ->assertExitCode(1);
});
