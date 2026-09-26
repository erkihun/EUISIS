<?php

declare(strict_types=1);

use App\Enums\CafeteriaLocationType;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    Role::findOrCreate('Super Admin', 'web');
    $this->maker = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');
    $this->checker = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');
});

test('the cafeteria create page starts a provider, its network and its main cafeteria in one step', function (): void {
    $this->actingAs($this->maker)->get(route('cafeteria.providers.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Cafeteria/Providers/Create')->has('payees')->has('networks')->has('parents'));

    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [
        'provider_mode' => 'new', 'provider_code' => 'prv-a', 'provider_name_en' => 'Provider A',
        'location_type' => 'main', 'network_mode' => 'new', 'network_code' => 'net-a', 'network_name_en' => 'Network A',
        'code' => 'CAF-1', 'name_en' => 'Cafeteria 1', 'operational_status' => 'open', 'opening_time' => '07:00', 'closing_time' => '15:00',
        'is_active' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $provider = Provider::query()->where('provider_code', 'PRV-A')->sole();
    $network = CafeteriaServiceNetwork::query()->where('code', 'NET-A')->sole();
    $main = CafeteriaProvider::query()->where('code', 'CAF-1')->sole();

    expect($provider->hasService('cafeteria'))->toBeTrue()                 // the portal can open its cafeteria pages
        ->and($network->provider_id)->toBe($provider->id)
        ->and($main->provider_id)->toBe($provider->id)
        ->and($main->cafeteria_service_network_id)->toBe($network->id)
        ->and($main->location_type)->toBe(CafeteriaLocationType::Main)
        ->and($main->service_provider_id)->not->toBeNull();                  // terminals and service transactions still link

    // "Add Branch" from the network page arrives prefilled; the branch joins under the main cafeteria.
    $this->actingAs($this->maker)->get(route('cafeteria.providers.create', ['network_id' => $network->id, 'location_type' => 'branch']))
        ->assertInertia(fn ($page) => $page->where('defaults.cafeteria_service_network_id', $network->id)->where('defaults.location_type', 'branch'));
    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [
        'provider_mode' => 'existing', 'provider_id' => $provider->id, 'location_type' => 'branch',
        'network_mode' => 'existing', 'cafeteria_service_network_id' => $network->id,
        'code' => 'CAF-2', 'name_en' => 'Cafeteria 2', 'operational_status' => 'open', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(CafeteriaProvider::query()->where('code', 'CAF-2')->sole()->parent_cafeteria_id)->toBe($main->id);
});

test('the create page refuses a second main cafeteria, a branch without a network and another provider\'s network', function (): void {
    $a = CafeteriaScenario::make('A');
    $b = CafeteriaScenario::make('B');
    $base = ['code' => 'CAF-X', 'name_en' => 'X', 'operational_status' => 'open', 'provider_mode' => 'existing', 'is_active' => true];

    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [...$base, 'provider_id' => $a->provider->id, 'location_type' => 'main', 'network_mode' => 'existing', 'cafeteria_service_network_id' => $a->network->id])
        ->assertSessionHasErrors('location_type');
    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [...$base, 'provider_id' => $a->provider->id, 'location_type' => 'branch', 'network_mode' => 'new', 'network_code' => 'N', 'network_name_en' => 'N'])
        ->assertSessionHasErrors('network_mode');
    $this->actingAs($this->maker)->post(route('cafeteria.providers.store'), [...$base, 'provider_id' => $a->provider->id, 'location_type' => 'branch', 'network_mode' => 'existing', 'cafeteria_service_network_id' => $b->network->id])
        ->assertSessionHasErrors('cafeteria_service_network_id');
    expect(CafeteriaProvider::query()->where('code', 'CAF-X')->exists())->toBeFalse();
});

test('access, assignment and a two-person approved policy built through the pages let employees eat', function (): void {
    $this->travelTo(Carbon::parse('2026-09-18 10:00'));
    $s = CafeteriaScenario::make();
    $org = $s->organization('Organization 2');
    [, $card] = $s->employee($org);

    $this->actingAs($this->maker)->post(route('cafeteria.access.store'), [
        'organization_id' => $org->id, 'cafeteria_service_network_id' => $s->network->id,
        'primary_cafeteria_id' => $s->branch->id, 'allow_cross_location_usage' => true, 'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $access = OrganizationCafeteriaAccess::query()->sole();
    $this->actingAs($this->checker)->post(route('cafeteria.access.approve', $access))->assertSessionHasNoErrors();

    $this->actingAs($this->maker)->post(route('cafeteria.assignments.store'), [
        'organization_id' => $org->id, 'provider_id' => $s->provider->id, 'cafeteria_service_network_id' => $s->network->id, 'effective_from' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $assignment = CafeteriaServiceAssignment::query()->sole();
    $this->actingAs($this->checker)->post(route('cafeteria.assignments.approve', $assignment))->assertSessionHasNoErrors();

    $this->actingAs($this->maker)->get(route('cafeteria.policies.create', ['assignment_id' => $assignment->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('defaults.cafeteria_service_assignment_id', $assignment->id)->missing('defaults.daily_subsidy_amount'));
    $this->actingAs($this->maker)->post(route('cafeteria.policies.store'), [
        'cafeteria_service_assignment_id' => $assignment->id,
        'daily_subsidy_amount' => '150.00', 'employee_contribution_amount' => '10.00', 'provider_price' => '160.00', 'currency_code' => 'ETB',
        'max_daily_uses' => 1, 'allow_advance_usage' => true, 'extra_scan_policy' => 'block',
        'monday_enabled' => true, 'tuesday_enabled' => true, 'wednesday_enabled' => true, 'thursday_enabled' => true, 'friday_enabled' => true,
        'exclude_public_holidays' => true, 'block_employee_leave' => true, 'effective_from' => '2026-09-21',
    ])->assertSessionHasNoErrors();
    $policy = CafeteriaServicePolicy::query()->sole();

    $this->actingAs($this->maker)->post(route('cafeteria.policies.submit', $policy))->assertSessionHasNoErrors();
    // Maker-checker: the drafter cannot approve.
    $this->actingAs($this->maker)->post(route('cafeteria.policies.approve', $policy))->assertSessionHasErrors('status');
    $this->actingAs($this->checker)->post(route('cafeteria.policies.approve', $policy))->assertSessionHasNoErrors();
    $this->actingAs($this->checker)->get(route('cafeteria.policies.show', $policy))->assertOk()
        ->assertInertia(fn ($page) => $page->where('policy.status', 'approved')->where('preview.warnings', []));

    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    $scan = app(CafeteriaQrScanService::class)->process($card, $s->main, now());

    expect($scan['allowed'])->toBeTrue()
        ->and($scan['subsidy_applied'])->toBe(150.0)
        ->and($scan['employee_payable'])->toBe(10.0);

    // The dashboard health panel has nothing to flag for this organization.
    $this->actingAs($this->checker)->get(route('cafeteria.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('health.missing_policy', [])->where('health.access_without_policy', []));
});

test('a new policy version is drafted from the approved terms and edited before review', function (): void {
    $this->travelTo(Carbon::parse('2026-09-18 10:00'));
    $s = CafeteriaScenario::make();
    $org = $s->organization('Organization 2');
    $policy = $s->enroll($org, '120.00', $s->main);

    $this->actingAs($this->maker)->post(route('cafeteria.policies.new-version', $policy))->assertRedirect();
    $draft = CafeteriaServicePolicy::query()->where('version_no', 2)->sole();

    $this->actingAs($this->maker)->get(route('cafeteria.policies.edit', $draft))->assertOk()
        ->assertInertia(fn ($page) => $page->where('policy.daily_subsidy_amount', '120.00')->where('policy.version_no', 2));
    // Approved v1 is not editable through the form route: it redirects to its page.
    $this->actingAs($this->maker)->get(route('cafeteria.policies.edit', $policy))->assertRedirect(route('cafeteria.policies.show', $policy));
});
