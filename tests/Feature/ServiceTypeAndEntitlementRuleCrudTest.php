<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Modules\Entitlements\Domain\Models\EntitlementRule;
use Modules\Entitlements\Domain\Models\ServiceType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'service-types.viewAny',
        'service-types.view',
        'service-types.create',
        'service-types.update',
        'service-types.archive',
        'service-types.restore',
        'entitlement-rules.viewAny',
        'entitlement-rules.view',
        'entitlement-rules.create',
        'entitlement-rules.update',
        'entitlement-rules.archive',
        'entitlement-rules.restore',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Service Catalog Admin', 'web')->syncPermissions([
        'service-types.viewAny',
        'service-types.view',
        'service-types.create',
        'service-types.update',
        'service-types.archive',
        'service-types.restore',
        'entitlement-rules.viewAny',
        'entitlement-rules.view',
        'entitlement-rules.create',
        'entitlement-rules.update',
        'entitlement-rules.archive',
        'entitlement-rules.restore',
    ]);

    Role::findOrCreate('Service Catalog Viewer', 'web')->syncPermissions([
        'service-types.viewAny',
        'service-types.view',
        'entitlement-rules.viewAny',
        'entitlement-rules.view',
    ]);
});

function serviceCatalogAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Service Catalog Admin');

    return $user;
}

function serviceCatalogViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('Service Catalog Viewer');

    return $user;
}

it('requires authentication for service types and entitlement rules indexes', function (): void {
    $this->get(route('service-types.index'))->assertRedirect(route('login'));
    $this->get(route('entitlement-rules.index'))->assertRedirect(route('login'));
});

it('enforces service type permissions for create routes', function (): void {
    $viewer = serviceCatalogViewer();
    $admin = serviceCatalogAdmin();

    $this->actingAs($viewer)->get(route('service-types.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('service-types.create'))->assertOk();
});

it('creates service types with validation and writes an audit log', function (): void {
    $admin = serviceCatalogAdmin();

    $this->actingAs($admin)
        ->post(route('service-types.store'), [
            'code' => 'legal_aid',
            'name_en' => 'Legal Aid',
            'name_am' => 'የሕግ ድጋፍ',
            'description' => 'Legal aid service',
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('service-types.store'), [
            'code' => 'legal_aid',
            'name_en' => 'Duplicate',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('code');

    expect(AuditLog::query()->where('event_type', 'service_type_created')->exists())->toBeTrue();
});

it('updates and archives service types with permission checks', function (): void {
    $admin = serviceCatalogAdmin();
    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service', 'is_active' => true]);

    $this->actingAs($admin)
        ->patch(route('service-types.update', $serviceType), [
            'code' => 'cafeteria',
            'name_en' => 'Cafeteria Updated',
            'name_am' => 'ካፌቴሪያ',
            'description' => 'Updated',
            'is_active' => true,
        ])
        ->assertRedirect(route('service-types.show', $serviceType));

    $this->actingAs($admin)
        ->delete(route('service-types.archive', $serviceType))
        ->assertRedirect();

    expect(ServiceType::query()->withTrashed()->find($serviceType->id)->deleted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', 'service_type_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'record_deleted')->exists())->toBeTrue();
});

it('enforces entitlement rule permissions for create routes', function (): void {
    $viewer = serviceCatalogViewer();
    $admin = serviceCatalogAdmin();

    $this->actingAs($viewer)->get(route('entitlement-rules.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('entitlement-rules.create'))->assertOk();
});

it('creates entitlement rules with validation and writes an audit log', function (): void {
    $admin = serviceCatalogAdmin();
    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'consumer_association'], ['name_en' => 'Consumer Association Service', 'is_active' => true]);

    $this->actingAs($admin)
        ->post(route('entitlement-rules.store'), [
            'service_type_id' => $serviceType->id,
            'name' => 'Monthly Quota',
            'rule_definition' => [
                'quota_limit' => 12,
                'period_days' => 30,
                'notes' => 'Monthly limit',
            ],
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('entitlement-rules.store'), [
            'service_type_id' => '',
            'name' => '',
            'rule_definition' => [
                'quota_limit' => -1,
                'period_days' => 0,
            ],
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['service_type_id', 'name', 'rule_definition.quota_limit', 'rule_definition.period_days']);

    expect(AuditLog::query()->where('event_type', 'entitlement_rule_created')->exists())->toBeTrue();
});

it('updates and archives entitlement rules with permission checks', function (): void {
    $admin = serviceCatalogAdmin();
    $serviceType = ServiceType::query()->create([
        'code' => 'meals',
        'name_en' => 'Meals',
        'is_active' => true,
    ]);
    $rule = EntitlementRule::query()->create([
        'service_type_id' => $serviceType->id,
        'name' => 'Daily Meal',
        'rule_definition' => ['quota_limit' => 1, 'period_days' => 1, 'notes' => 'Daily'],
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->patch(route('entitlement-rules.update', $rule), [
            'service_type_id' => $serviceType->id,
            'name' => 'Daily Meal Updated',
            'rule_definition' => [
                'quota_limit' => 2,
                'period_days' => 1,
                'notes' => 'Updated note',
            ],
            'is_active' => true,
        ])
        ->assertRedirect(route('entitlement-rules.show', $rule));

    $this->actingAs($admin)
        ->delete(route('entitlement-rules.archive', $rule))
        ->assertRedirect();

    expect(EntitlementRule::query()->withTrashed()->find($rule->id)->deleted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', 'entitlement_rule_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'record_deleted')->exists())->toBeTrue();
});

it('documents database ui coverage for the new catalog modules', function (): void {
    $content = file_get_contents(base_path('docs/database-ui-coverage.md'));

    expect($content)->toContain('service_types')
        ->toContain('entitlement_rules');
});
