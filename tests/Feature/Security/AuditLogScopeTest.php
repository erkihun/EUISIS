<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Permission::findOrCreate('audit.view', 'web');
    Permission::findOrCreate('employees.viewPii', 'web');
});

function auditOrg(string $name): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'AUD'], ['name_en' => 'Audit type']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'AUD-'.Str::upper(Str::random(6)),
        'name_en' => $name,
        'name_am' => $name,
        'status' => 'active',
    ]);
}

function auditRow(?Organization $org, array $new = ['status' => 'active']): AuditLog
{
    return AuditLog::query()->create([
        'event_type' => AuditEventType::cases()[0],
        'organization_id' => $org?->id,
        'new_values' => $new,
        'request_id' => (string) Str::uuid7(),
        'created_at' => now(),
    ]);
}

function scopedAuditor(Organization $org, bool $withPii = false): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($withPii ? ['audit.view', 'employees.viewPii'] : ['audit.view']);
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

test('a scoped auditor sees only audit rows of their own organization', function (): void {
    $own = auditOrg('Own');
    $other = auditOrg('Other');
    $mine = auditRow($own);
    auditRow($other);
    auditRow(null); // system-wide event

    actingAs(scopedAuditor($own))
        ->get('/audit-logs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AuditLogs/Index')
            ->has('auditLogs', 1)
            ->where('auditLogs.0.id', $mine->id));
});

test('employee personal data in audit values is masked without viewPii', function (): void {
    $own = auditOrg('Own');
    auditRow($own, [
        'full_name' => 'Abebe Kebede', 'phone' => '0911000000', 'email' => 'a@example.test',
        'address' => 'Bole', 'date_of_birth' => '1990-01-01', 'emergency_contact_phone' => '0922000000',
    ]);

    actingAs(scopedAuditor($own))
        ->get('/audit-logs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auditLogs.0.new_values.full_name', 'Abebe Kebede')
            ->where('auditLogs.0.new_values.phone', '[MASKED]')
            ->where('auditLogs.0.new_values.email', '[MASKED]')
            ->where('auditLogs.0.new_values.address', '[MASKED]')
            ->where('auditLogs.0.new_values.date_of_birth', '[MASKED]')
            ->where('auditLogs.0.new_values.emergency_contact_phone', '[MASKED]'));
});

test('an auditor with viewPii sees the recorded personal data', function (): void {
    $own = auditOrg('Own');
    auditRow($own, ['phone' => '0911000000']);

    actingAs(scopedAuditor($own, withPii: true))
        ->get('/audit-logs')
        ->assertInertia(fn (Assert $page) => $page->where('auditLogs.0.new_values.phone', '0911000000'));
});

test('a numeric search matches the actor id without a text comparison on the bigint column', function (): void {
    $own = auditOrg('Own');
    $actor = User::factory()->create();
    $hit = auditRow($own);
    $hit->forceFill(['actor_user_id' => $actor->id])->save();
    auditRow($own);

    actingAs(scopedAuditor($own))
        ->get('/audit-logs?search='.$actor->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('auditLogs', 1)->where('auditLogs.0.id', $hit->id));
});
