<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaLocationType;
use App\Enums\CafeteriaPolicyStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\OrganizationType;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The brief's reference layout, built fresh for each test:
 *
 *   Provider A
 *   └── Network A
 *       ├── main   (Cafeteria 1, primarily serves Organization 1)
 *       └── branch (Cafeteria 2)
 *
 * Organizations, access, assignments, policies and employees are added
 * explicitly per test — nothing is granted by default.
 */
final class CafeteriaScenario
{
    public Provider $provider;

    public CafeteriaServiceNetwork $network;

    public CafeteriaProvider $main;

    public CafeteriaProvider $branch;

    private static int $sequence = 0;

    public static function make(string $label = 'A'): self
    {
        $scenario = new self;
        $scenario->provider = $scenario->createProvider($label);
        $scenario->network = CafeteriaServiceNetwork::query()->create([
            'provider_id' => $scenario->provider->id,
            'code' => 'NET-'.$label.'-'.self::next(),
            'name_en' => "Network {$label}",
            'status' => 'active',
        ]);
        $scenario->main = $scenario->location('Cafeteria 1', CafeteriaLocationType::Main);
        $scenario->branch = $scenario->location('Cafeteria 2', CafeteriaLocationType::Branch, $scenario->main);

        return $scenario;
    }

    public function location(string $name, CafeteriaLocationType $type, ?CafeteriaProvider $parent = null, array $overrides = []): CafeteriaProvider
    {
        $serviceProvider = ServiceProvider::query()->create([
            'service_type_id' => $this->cafeteriaServiceType()->id,
            'name' => $name,
            'code' => 'SP-'.self::next(),
            'status' => 'active',
        ]);

        return CafeteriaProvider::query()->create([
            'service_provider_id' => $serviceProvider->id,
            'provider_id' => $this->provider->id,
            'cafeteria_service_network_id' => $this->network->id,
            'parent_cafeteria_id' => $parent?->id,
            'location_type' => $type->value,
            'operational_status' => 'open',
            'code' => 'CAF-'.self::next(),
            'name_en' => $name,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    public function organization(string $name): Organization
    {
        $type = OrganizationType::query()->firstOrCreate(['code' => 'CAF-TYPE'], ['name_en' => 'Cafeteria Test Type']);

        return Organization::query()->create([
            'organization_type_id' => $type->id,
            'code' => 'ORG-'.self::next(),
            'name_en' => $name,
            'status' => 'active',
        ]);
    }

    public function grantAccess(Organization $organization, ?CafeteriaProvider $primary = null, bool $crossLocation = false, array $overrides = []): OrganizationCafeteriaAccess
    {
        return OrganizationCafeteriaAccess::query()->create([
            'organization_id' => $organization->id,
            'cafeteria_service_network_id' => $this->network->id,
            'primary_cafeteria_id' => $primary?->id,
            'allow_cross_location_usage' => $crossLocation,
            'effective_from' => '2026-01-01',
            'status' => CafeteriaGrantStatus::Active->value,
            ...$overrides,
        ]);
    }

    public function assign(Organization $organization, array $overrides = []): CafeteriaServiceAssignment
    {
        return CafeteriaServiceAssignment::query()->create([
            'organization_id' => $organization->id,
            'provider_id' => $this->provider->id,
            'cafeteria_service_network_id' => $this->network->id,
            'effective_from' => '2026-01-01',
            'status' => CafeteriaGrantStatus::Active->value,
            ...$overrides,
        ]);
    }

    /** An approved network-level policy: Mon–Fri, one meal a day, price = subsidy. */
    public function policy(Organization $organization, string $subsidy = '120.00', array $overrides = [], ?CafeteriaServiceAssignment $assignment = null): CafeteriaServicePolicy
    {
        $assignment ??= CafeteriaServiceAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('provider_id', $this->provider->id)
            ->first() ?? $this->assign($organization);

        $networkId = array_key_exists('cafeteria_service_network_id', $overrides) ? $overrides['cafeteria_service_network_id'] : $this->network->id;
        $cafeteriaId = $overrides['cafeteria_id'] ?? null;
        $contribution = $overrides['employee_contribution_amount'] ?? '0.00';
        $groupId = (string) Str::uuid7();

        return CafeteriaServicePolicy::query()->create([
            'policy_group_id' => $groupId,
            'version_no' => 1,
            'cafeteria_service_assignment_id' => $assignment->id,
            'organization_id' => $organization->id,
            'provider_id' => $this->provider->id,
            'scope_key' => CafeteriaServicePolicy::scopeKeyFor($organization->id, $this->provider->id, $networkId, $cafeteriaId),
            'daily_subsidy_amount' => $subsidy,
            'employee_contribution_amount' => $contribution,
            'provider_price' => bcadd($subsidy, (string) $contribution, 2),
            'currency_code' => 'ETB',
            'max_daily_uses' => 1,
            'allow_advance_usage' => true,
            'extra_scan_policy' => 'block',
            'effective_from' => '2026-01-01',
            'status' => CafeteriaPolicyStatus::Approved->value,
            ...$overrides,
            'cafeteria_service_network_id' => $networkId,
            'cafeteria_id' => $cafeteriaId,
        ]);
    }

    /** Everything an organization needs to eat in the network: access, assignment and a policy. */
    public function enroll(Organization $organization, string $subsidy = '120.00', ?CafeteriaProvider $primary = null, bool $crossLocation = true, array $policy = []): CafeteriaServicePolicy
    {
        $this->grantAccess($organization, $primary ?? $this->main, $crossLocation);

        return $this->policy($organization, $subsidy, $policy);
    }

    /** @return array{0: Employee, 1: IdCard, 2: EmployeeAssignment} */
    public function employee(Organization $organization, string $from = '2026-01-01', ?string $to = null): array
    {
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-'.self::next(),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'full_name' => 'Test Employee',
            'status' => EmployeeStatus::Active,
        ]);

        $assignment = $this->assignEmployee($employee, $organization, $from, $to, current: true);

        $card = IdCard::query()->create([
            'employee_id' => $employee->id,
            'card_number' => 'CARD-'.self::next(),
            'status' => CardStatus::Active,
            'expires_at' => now()->addYears(2),
            'activated_at' => now(),
            'is_current' => true,
            'qr_status' => 'active',
            'public_card_uuid' => (string) Str::uuid(),
        ]);

        return [$employee->fresh(), $card, $assignment];
    }

    public function assignEmployee(Employee $employee, Organization $organization, string $from, ?string $to = null, bool $current = true): EmployeeAssignment
    {
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $organization->id,
            'assignment_status' => $to === null ? 'active' : 'closed',
            'effective_from' => $from,
            'effective_to' => $to,
            'is_current' => $current,
        ]);

        if ($current) {
            $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
        }

        return $assignment;
    }

    private function createProvider(string $label): Provider
    {
        $type = ProviderType::query()->firstOrCreate(['code' => 'CAFETERIA'], ['name_en' => 'Cafeteria', 'is_active' => true]);

        $provider = Provider::query()->create([
            'provider_code' => 'PRV-'.$label.'-'.self::next(),
            'provider_type_id' => $type->id,
            'name_en' => "Provider {$label}",
            'status' => 'active',
        ]);

        DB::table('provider_services')->insert([
            'id' => (string) Str::uuid7(),
            'provider_id' => $provider->id,
            'service_type_id' => $this->cafeteriaServiceType()->id,
            'status' => 'active',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $provider;
    }

    private function cafeteriaServiceType(): ServiceType
    {
        return ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    }

    private static function next(): string
    {
        return (string) ++self::$sequence.Str::upper(Str::random(4));
    }
}
