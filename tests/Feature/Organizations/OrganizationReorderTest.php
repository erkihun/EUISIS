<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Reorder Manager', 'web')->givePermissionTo(['organizations.view', 'organizations.manage']);
    Role::findOrCreate('Reorder Viewer', 'web')->givePermissionTo(['organizations.view']);

    $this->reorderType = OrganizationType::query()->create(['code' => 'REORDER-TYPE', 'name_en' => 'Reorder Type']);
});

function reorderOrg(string $code, ?int $sortOrder = null, string $status = 'active'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->reorderType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'status' => $status,
        'sort_order' => $sortOrder,
    ]);
}

function reorderUser(string $role, OrganizationScopeType $scope = OrganizationScopeType::Citywide, ?Organization $organization = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'scope_type' => $scope,
        'organization_id' => $organization?->id,
        'is_active' => true,
    ]);

    return $user;
}

/** @return array{codes: list<string>, props: array<string, mixed>} */
function reorderList(User $user, array $query = []): array
{
    $result = [];
    test()->actingAs($user)
        ->get(route('organizations.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $props = $page->toArray()['props'];
            $result = ['codes' => array_column($props['organizations']['data'], 'code'), 'props' => $props];
        });

    return $result;
}

test('a manager saves an order, and it survives a reload as the default order', function (): void {
    $a = reorderOrg('A-1');
    $b = reorderOrg('B-2');
    $c = reorderOrg('C-3');
    $manager = reorderUser('Reorder Manager');

    $this->actingAs($manager)
        ->post(route('organizations.reorder'), ['ids' => [$c->id, $a->id, $b->id]])
        ->assertRedirect(route('organizations.index'))
        ->assertSessionHas('flash.type', 'success');

    $list = reorderList($manager);

    expect($list['codes'])->toBe(['C-3', 'A-1', 'B-2'])
        ->and($list['props']['sort'])->toBe(['column' => 'manual', 'direction' => 'asc'])
        ->and($list['props']['hasManualOrder'])->toBeTrue();
});

test('only rows whose position changed are written and audited, and updated_at stays', function (): void {
    $a = reorderOrg('A-1', 1);
    $b = reorderOrg('B-2', 2);
    $c = reorderOrg('C-3', 3);
    Organization::query()->update(['updated_at' => '2026-01-01 00:00:00']);
    $manager = reorderUser('Reorder Manager');

    $this->actingAs($manager)
        ->post(route('organizations.reorder'), ['ids' => [$a->id, $c->id, $b->id]])
        ->assertRedirect();

    $audits = AuditLog::query()->whereIn('auditable_id', [$a->id, $b->id, $c->id])->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('auditable_id')->sort()->values()->all())->toBe(collect([$b->id, $c->id])->sort()->values()->all())
        ->and($c->fresh()->sort_order)->toBe(2)
        ->and($b->fresh()->sort_order)->toBe(3)
        ->and($b->fresh()->updated_at->toDateString())->toBe('2026-01-01');
});

test('a user who cannot manage the structure is refused and sees no arrange mode', function (): void {
    $a = reorderOrg('A-1', 1);
    $b = reorderOrg('B-2', 2);
    $viewer = reorderUser('Reorder Viewer');

    $this->actingAs($viewer)
        ->post(route('organizations.reorder'), ['ids' => [$b->id, $a->id]])
        ->assertForbidden();

    $list = reorderList($viewer, ['arrange' => 1]);

    expect($a->fresh()->sort_order)->toBe(1)
        ->and($list['props']['arranging'])->toBeFalse()
        ->and($list['props']['can']['manageHierarchy'])->toBeFalse();
});

test('a manager cannot place an organization outside their scope', function (): void {
    $mine = reorderOrg('MINE', 1);
    $other = reorderOrg('OTHER', 2);
    $manager = reorderUser('Reorder Manager', OrganizationScopeType::Self, $mine);

    $this->actingAs($manager)
        ->post(route('organizations.reorder'), ['ids' => [$other->id, $mine->id]])
        ->assertForbidden();

    expect($mine->fresh()->sort_order)->toBe(1)
        ->and($other->fresh()->sort_order)->toBe(2);
});

test('unknown, repeated and archived organizations are rejected', function (): void {
    $a = reorderOrg('A-1', 1);
    $archived = reorderOrg('OLD', 2, 'archived');
    $deleted = reorderOrg('GONE', 3);
    $deleted->delete();
    $manager = reorderUser('Reorder Manager');

    foreach ([
        [$a->id, (string) Str::uuid()],
        [$a->id, $a->id],
        [$a->id, $archived->id],
        [$a->id, $deleted->id],
        ['not-a-uuid'],
        [],
    ] as $ids) {
        $this->actingAs($manager)
            ->post(route('organizations.reorder'), ['ids' => $ids])
            ->assertSessionHasErrors();
    }

    expect($a->fresh()->sort_order)->toBe(1);
});

test('arranging lists every organization on one page and ignores filters', function (): void {
    foreach (range(1, 25) as $number) {
        reorderOrg(sprintf('ORG-%02d', $number));
    }
    $manager = reorderUser('Reorder Manager');

    $arranged = reorderList($manager, ['arrange' => 1, 'search' => 'ORG-01', 'sort' => 'code', 'direction' => 'desc']);
    $normal = reorderList($manager);

    expect($arranged['codes'])->toHaveCount(25)
        ->and($arranged['props']['arranging'])->toBeTrue()
        ->and($arranged['props']['sort']['column'])->toBe('manual')
        ->and($arranged['props']['filters']['search'])->toBe('')
        ->and($normal['codes'])->toHaveCount(20)
        ->and($normal['props']['arranging'])->toBeFalse();
});

test('rows with no position follow the ordered ones, and a chosen column still wins', function (): void {
    reorderOrg('A-1');
    reorderOrg('B-2', 2);
    reorderOrg('C-3', 1);
    reorderOrg('D-4');
    $manager = reorderUser('Reorder Manager');

    expect(reorderList($manager)['codes'])->toBe(['C-3', 'B-2', 'A-1', 'D-4'])
        ->and(reorderList($manager, ['sort' => 'code', 'direction' => 'desc'])['codes'])->toBe(['D-4', 'C-3', 'B-2', 'A-1']);
});

test('with no saved order the list still defaults to the name', function (): void {
    reorderOrg('B-2');
    reorderOrg('A-1');

    $list = reorderList(reorderUser('Reorder Manager'));

    expect($list['codes'])->toBe(['A-1', 'B-2'])
        ->and($list['props']['sort']['column'])->toBe('name')
        ->and($list['props']['hasManualOrder'])->toBeFalse();
});
