<?php

declare(strict_types=1);

use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'position-establishments.viewAny',
        'position-establishments.view',
        'position-establishments.create',
        'position-establishments.update',
        'position-establishments.approve',
        'position-establishments.archive',
        'positions.viewAny',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function makeTestOrganization(): Organization
{
    $orgType = OrganizationType::query()->firstOrCreate(
        ['code' => 'TEST-TYPE-EST'],
        ['name_en' => 'Test Type', 'is_active' => true],
    );

    return Organization::query()->create([
        'organization_type_id' => $orgType->id,
        'code' => 'ORG-EST-'.uniqid(),
        'name_en' => 'Test Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function superAdminForEstablishment(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function makeEstablishment(array $overrides = []): PositionEstablishment
{
    $org = makeTestOrganization();
    $pos = (new Position)->forceFill([
        'organization_id' => $org->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'title_en' => 'Test Position',
        'is_active' => true,
    ]);
    $pos->save();

    return PositionEstablishment::create(array_merge([
        'establishment_number' => 'EST-TEST-'.uniqid(),
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => 3,
        'effective_from' => '2026-01-01',
        'status' => EstablishmentStatus::Draft->value,
    ], $overrides));
}

it('super admin can create a position establishment', function (): void {
    $user = superAdminForEstablishment();
    $org = makeTestOrganization();
    $pos = (new Position)->forceFill([
        'organization_id' => $org->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'title_en' => 'My Position',
        'is_active' => true,
    ]);
    $pos->save();

    $response = $this->actingAs($user)->post(route('position-establishments.store'), [
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => 5,
        'effective_from' => '2026-06-01',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect(PositionEstablishment::where('position_id', $pos->id)->count())->toBe(1);
});

it('approved slots must be at least 1', function (): void {
    $user = superAdminForEstablishment();
    $org = makeTestOrganization();
    $pos = (new Position)->forceFill([
        'organization_id' => $org->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'title_en' => 'My Position',
        'is_active' => true,
    ]);
    $pos->save();

    $response = $this->actingAs($user)->post(route('position-establishments.store'), [
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => 0,
        'effective_from' => '2026-06-01',
    ]);

    $response->assertSessionHasErrors('approved_slots');
});

it('super admin can approve a draft establishment', function (): void {
    $user = superAdminForEstablishment();
    $establishment = makeEstablishment();

    expect($establishment->status)->toBe(EstablishmentStatus::Draft);

    $response = $this->actingAs($user)->post(route('position-establishments.approve', $establishment->id));

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect($establishment->fresh()->status)->toBe(EstablishmentStatus::Approved);
});

it('cannot approve an already-approved establishment', function (): void {
    $user = superAdminForEstablishment();
    $establishment = makeEstablishment(['status' => EstablishmentStatus::Approved->value]);

    $response = $this->actingAs($user)->postJson(route('position-establishments.approve', $establishment->id));

    expect($response->status())->toBe(422);
});

it('super admin can view establishment index', function (): void {
    $user = superAdminForEstablishment();

    $response = $this->actingAs($user)->get(route('position-establishments.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('PositionEstablishments/Index'));
});
