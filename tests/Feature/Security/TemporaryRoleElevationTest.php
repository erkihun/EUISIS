<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\TemporaryRoleGrant;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
 * Core Stage 6 break-glass (TD-17): a role for a limited time, with a reason and
 * a second person's approval, removed automatically and audited both ways.
 */

beforeEach(function (): void {
    Role::findOrCreate('Incident Responder', 'web');
    $this->grantee = User::factory()->create(['email' => 'ops@example.test']);
    $this->approver = User::factory()->create(['email' => 'lead@example.test']);
});

it('grants a role for a limited time and removes it when the time is up', function (): void {
    $this->artisan('access:elevate', [
        'email' => 'ops@example.test', 'role' => 'Incident Responder',
        '--minutes' => 30, '--reason' => 'Restore after incident 42', '--approved-by' => 'lead@example.test',
    ])->assertSuccessful();

    $grant = TemporaryRoleGrant::query()->sole();
    expect($this->grantee->fresh()->hasRole('Incident Responder'))->toBeTrue()
        ->and($grant->approved_by)->toBe($this->approver->id)
        ->and(AuditLog::query()->where('event_type', 'temporary_role_granted')->where('reason', 'Restore after incident 42')->exists())->toBeTrue();

    // Not yet expired: nothing changes.
    $this->artisan('access:expire-elevations')->assertSuccessful();
    expect($this->grantee->fresh()->hasRole('Incident Responder'))->toBeTrue();

    $this->travel(31)->minutes();
    $this->artisan('access:expire-elevations')->assertSuccessful();

    expect($this->grantee->fresh()->hasRole('Incident Responder'))->toBeFalse()
        ->and($grant->fresh()->ended_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', 'temporary_role_ended')->exists())->toBeTrue();
});

it('refuses an elevation without a reason, without a second approver, beyond a working day or for a role already held', function (array $options): void {
    $this->artisan('access:elevate', [
        'email' => 'ops@example.test', 'role' => 'Incident Responder',
        ...$options,
    ])->assertFailed();

    expect(TemporaryRoleGrant::query()->count())->toBe(0)
        ->and($this->grantee->fresh()->hasRole('Incident Responder'))->toBeFalse();
})->with([
    'no reason' => [['--approved-by' => 'lead@example.test']],
    'self approval' => [['--reason' => 'Restore after incident 42', '--approved-by' => 'ops@example.test']],
    'too long' => [['--reason' => 'Restore after incident 42', '--approved-by' => 'lead@example.test', '--minutes' => 481]],
    'unknown role' => [['--reason' => 'Restore after incident 42', '--approved-by' => 'lead@example.test', 'role' => 'Nope']],
]);

it('never removes a role the account held before the elevation', function (): void {
    $this->grantee->assignRole('Incident Responder');

    $this->artisan('access:elevate', [
        'email' => 'ops@example.test', 'role' => 'Incident Responder',
        '--reason' => 'Restore after incident 42', '--approved-by' => 'lead@example.test',
    ])->assertFailed();

    $this->travel(2)->hours();
    $this->artisan('access:expire-elevations')->assertSuccessful();
    expect($this->grantee->fresh()->hasRole('Incident Responder'))->toBeTrue();
});
