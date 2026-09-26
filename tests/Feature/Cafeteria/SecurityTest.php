<?php

declare(strict_types=1);

use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\ProviderUser;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $this->s = CafeteriaScenario::make();
    $this->org1 = $this->s->organization('Organization 1');
    $this->org2 = $this->s->organization('Organization 2');
    $this->policy1 = $this->s->enroll($this->org1, '120.00', $this->s->main);
    $this->policy2 = $this->s->enroll($this->org2, '150.00', $this->s->branch, crossLocation: true);
    [, $this->card] = $this->s->employee($this->org2);

    $this->userWith = function (array $permissions): User {
        $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    };
});

test('42 a client-supplied amount is ignored by the scan and rejected by the scan form', function (): void {
    // Service level: whatever amount rides along, the policy prices the meal.
    $result = app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, Carbon::parse('2026-09-21 12:00'),
        options: ['meal_amount' => 1, 'subsidy_amount_applied' => 9999, 'provider_price' => 1]);
    expect($result['subsidy_applied'])->toBe(150.0);

    // HTTP level: the scan form refuses money and times outright.
    $operator = ($this->userWith)(['cafeteria_transactions.scan', 'cafeteria_providers.viewAll']);
    $this->actingAs($operator)->post(route('cafeteria.scan.process'), [
        'provider_id' => $this->s->main->id, 'qr_token' => $this->card->public_card_uuid, 'scan_nonce' => (string) Str::uuid(),
        'usage_mode' => 'single_day', 'meal_amount' => '5.00', 'scanned_at' => '2026-01-01 12:00:00',
    ])->assertSessionHasErrors(['meal_amount', 'scanned_at']);
    expect(CafeteriaTransaction::query()->count())->toBe(1);
});

test('43 44 a scan cannot choose its policy or billing organization', function (): void {
    // Org 1's cheaper policy and org id are injected for an Org 2 employee.
    $result = app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, Carbon::parse('2026-09-21 12:00'), options: [
        'cafeteria_service_policy_id' => $this->policy1->id,
        'organization_id' => $this->org1->id,
        'employee_organization_id' => $this->org1->id,
    ]);

    $transaction = CafeteriaTransaction::query()->sole();
    expect($result['allowed'])->toBeTrue()
        ->and($transaction->cafeteria_service_policy_id)->toBe($this->policy2->id)
        ->and($transaction->employee_organization_id)->toBe($this->org2->id);
});

test('44 46 an organization-scoped admin cannot act on another organization', function (): void {
    $admin = ($this->userWith)(['cafeteria_policies.view', 'cafeteria_policies.update_draft', 'cafeteria_access.manage', 'cafeteria_access.view']);
    UserOrganizationScope::query()->create(['user_id' => $admin->id, 'organization_id' => $this->org1->id, 'scope_type' => 'self', 'is_active' => true]);
    CafeteriaServicePolicy::query()->whereKey($this->policy2->id)->update(['status' => 'draft']);

    // Org 2's policy: not viewable, not editable.
    $this->actingAs($admin)->get(route('cafeteria.policies.show', $this->policy2))->assertForbidden();
    $this->actingAs($admin)->patch(route('cafeteria.policies.update', $this->policy2), [
        'daily_subsidy_amount' => '1.00', 'employee_contribution_amount' => '0', 'provider_price' => '1.00', 'currency_code' => 'ETB',
        'max_daily_uses' => 1, 'extra_scan_policy' => 'block', 'monday_enabled' => true, 'effective_from' => '2026-01-01',
    ])->assertForbidden();

    // Granting access to an organization outside the scope, by changing the posted id.
    $this->actingAs($admin)->post(route('cafeteria.access.store'), [
        'organization_id' => $this->org2->id, 'cafeteria_service_network_id' => $this->s->network->id,
        'primary_cafeteria_id' => $this->s->main->id, 'effective_from' => '2026-10-01',
    ])->assertForbidden();

    // The listing shows only the admin's own organization.
    $this->actingAs($admin)->get(route('cafeteria.policies.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.id', $this->policy1->id));
});

test('45 a provider portal user cannot see another provider\'s transactions', function (): void {
    app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, Carbon::parse('2026-09-21 12:00'));
    $transaction = CafeteriaTransaction::query()->sole();

    $other = CafeteriaScenario::make('B');
    $portalUser = ProviderUser::query()->create([
        'provider_id' => $other->provider->id, 'name' => 'B Operator', 'email' => 'b-op@example.test', 'username' => 'b-op',
        'password' => Hash::make('password'), 'provider_role' => 'operator', 'status' => 'active', 'portal_enabled' => true,
    ]);

    $this->actingAs($portalUser, 'provider')->get(route('provider.portal.transactions.show', $transaction))->assertNotFound();

    // Choosing A's cafeteria in B's portal switcher is ignored.
    $this->actingAs($portalUser, 'provider')->get(route('provider.portal.transactions.index', ['provider_id' => $this->s->main->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected_provider_id', $other->main->id));
});

test('47 the scanner role cannot manage policies, access, assignments or settlements', function (): void {
    Role::findOrCreate('Cafeteria Operator', 'web');
    foreach (DefaultRoleMatrix::permissionsFor('Cafeteria Operator') as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $scanner = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    $scanner->assignRole(Role::findByName('Cafeteria Operator', 'web')->givePermissionTo(DefaultRoleMatrix::permissionsFor('Cafeteria Operator')));

    $this->actingAs($scanner)->get(route('cafeteria.policies.index'))->assertForbidden();
    $this->actingAs($scanner)->post(route('cafeteria.policies.store'), [])->assertForbidden();
    $this->actingAs($scanner)->post(route('cafeteria.policies.approve', $this->policy1))->assertForbidden();
    $this->actingAs($scanner)->get(route('cafeteria.access.create'))->assertForbidden();
    $this->actingAs($scanner)->get(route('cafeteria.assignments.index'))->assertForbidden();
    $this->actingAs($scanner)->get(route('cafeteria.settlements.create'))->assertForbidden();
    $this->actingAs($scanner)->get(route('cafeteria.networks.create'))->assertForbidden();
});

test('an authorized administrator can open every cafeteria management page', function (): void {
    $admin = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));

    foreach ([
        route('cafeteria.payees.index'), route('cafeteria.networks.index'), route('cafeteria.networks.show', $this->s->network),
        route('cafeteria.providers.create'), route('cafeteria.providers.edit', $this->s->branch),
        route('cafeteria.access.index'), route('cafeteria.access.create'), route('cafeteria.assignments.index'), route('cafeteria.assignments.create'),
        route('cafeteria.policies.index'), route('cafeteria.policies.create'), route('cafeteria.policies.show', $this->policy1),
        route('cafeteria.settlements.index'), route('cafeteria.settlements.create'),
        route('cafeteria.analytics.index'), route('cafeteria.analytics.index', ['type' => 'organization_liability', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']),
        route('cafeteria.dashboard'),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});
