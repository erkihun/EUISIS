<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\OrganizationScopeType;
use App\Models\IdCard;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Nfc\NfcAccess;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\GrievanceScenario;

use function Pest\Laravel\actingAs;

/*
 * Production risk register SEC-5: a scoped user whose scope rows have all
 * expired resolves to an empty organization set. That must mean "nothing",
 * never "every organization in the city".
 */
beforeEach(function (): void {
    foreach (['cards.view', 'id-cards.viewAny', 'transfers.announcements.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $type = OrganizationType::query()->firstOrCreate(['code' => 'ESC'], ['name_en' => 'Empty scope test']);
    $this->org = GrievanceScenario::org($type, 'Some Office');
    $employee = GrievanceScenario::employee($this->org, null);
    IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'CARD-'.Str::upper(Str::random(8)),
        'status' => CardStatus::Active, 'token_hash' => hash('sha256', Str::random(32)), 'is_current' => true,
    ]);

    // Scoped, but the only scope row has expired.
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['cards.view', 'id-cards.viewAny', 'transfers.announcements.view']);
    UserOrganizationScope::query()->create([
        'user_id' => $this->user->id, 'organization_id' => $this->org->id,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => now()->subDay()->toDateString(),
    ]);
    $this->user = $this->user->fresh();
});

it('shows no ID cards to a scoped user whose scope has expired', function (): void {
    actingAs($this->user)->get(route('id-cards.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 0));
});

it('shows no organizations or units to a scoped user whose scope has expired', function (): void {
    $this->user->givePermissionTo(Permission::findOrCreate('organization-units.viewAny', 'web'));
    OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'ESC-U1', 'name_en' => 'Unit', 'unit_type' => 'department', 'status' => 'active']);

    actingAs($this->user)->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total', 0)->where('organizations', []));
    actingAs($this->user)->get(route('organization-units.index', ['organization_id' => $this->org->id]))->assertForbidden();
});

it('gives the NFC pages an empty organization set, not an unfiltered one, for a scoped user whose scope has expired', function (): void {
    $access = app(NfcAccess::class);
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'web'));

    expect($access->organizationScope($this->user))->not->toBeNull()->toBeEmpty()
        ->and($access->organizationScope($admin))->toBeNull();
});
