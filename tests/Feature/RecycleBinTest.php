<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\ServiceType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'recycle-bin.view',
        'recycle-bin.restore',
        'recycle-bin.viewDetails',
        'service-types.viewAny',
        'service-types.view',
        'service-types.delete',
        'service-types.archive',
        'service-types.restore',
        'service-types.viewDeleted',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Recycle Bin Admin', 'web')->syncPermissions(Permission::all());
});

function recycleBinAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Recycle Bin Admin');

    return $user;
}

it('blocks unauthorized users from recycle bin', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('recycle-bin.index'))
        ->assertForbidden();
});

it('lists deleted records and restores them', function (): void {
    $user = recycleBinAdmin();
    $serviceType = ServiceType::query()->create([
        'code' => 'RECYCLE',
        'name_en' => 'Recycle Test',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('service-types.archive', $serviceType), ['reason' => 'Duplicate setup'])
        ->assertRedirect(route('service-types.index'));

    $trashed = ServiceType::query()->withTrashed()->findOrFail($serviceType->id);

    expect($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->deleted_by)->toBe($user->id)
        ->and($trashed->deletion_reason)->toBe('Duplicate setup')
        ->and(AuditLog::query()->where('event_type', 'record_deleted')->where('auditable_id', $serviceType->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('recycle-bin.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('RecycleBin/Index')
            ->where('records.data.0.id', $serviceType->id)
            ->where('records.data.0.type', 'service_types')
        );

    $this->actingAs($user)
        ->post(route('recycle-bin.restore', ['type' => 'service_types', 'id' => $serviceType->id]))
        ->assertRedirect();

    expect($serviceType->fresh()->deleted_at)->toBeNull()
        ->and($serviceType->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'record_restored')->where('auditable_id', $serviceType->id)->exists())->toBeTrue();
});

it('has recycle bin localization files', function (): void {
    expect(file_exists(resource_path('js/i18n/en/recycleBin.ts')))->toBeTrue()
        ->and(file_exists(resource_path('js/i18n/am/recycleBin.ts')))->toBeTrue()
        ->and(trans('recycle-bin.deleted_successfully', [], 'en'))->toBe('Record permanently deleted.');
});

it('does not fail audit logging when an organization reference is stale', function (): void {
    $user = recycleBinAdmin();

    $auditLog = app(WriteAuditLogAction::class)->execute(
        AuditEventType::RecordDeleted,
        $user,
        organizationId: '019e2249-3b11-72ed-af97-f59d5f608701',
        oldValues: ['name' => 'stale organization reference'],
    );

    expect($auditLog->organization_id)->toBeNull()
        ->and($auditLog->event_type)->toBe(AuditEventType::RecordDeleted);
});
