<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ProviderUserServicePermission;
use App\Models\ServiceType;
use App\Models\TransportContract;
use App\Models\TransportDevice;
use App\Models\TransportEligibility;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use App\Models\TransportRouteStop;
use App\Models\TransportSchedule;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\IdCards\CardQrPayloadService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * A city for transport tests: Bureau → Subcity A (Woreda A1) / Subcity B on a
 * published hierarchy, transport providers with portal users, and employees
 * with printed ID cards.
 */
final class TransportScenario
{
    private static int $sequence = 0;

    /** @var array<string, Organization> */
    public array $org = [];

    public HierarchyVersion $version;

    public static function make(): self
    {
        Cache::flush();
        config(['features.transport' => true, 'security.mfa_enforce' => false]);
        foreach (self::permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'TR-TYPE'], ['name_en' => 'Office']);
        $s->version = HierarchyVersion::query()->create(['version_name' => 'tr-published', 'status' => HierarchyVersionStatus::Published, 'effective_from' => now()->subYear()->toDateString(), 'approval_date' => now()->subYear()]);

        foreach (['bureau' => 'Bureau', 'subcity_a' => 'Subcity A', 'woreda_a1' => 'Woreda A1', 'subcity_b' => 'Subcity B'] as $key => $name) {
            $s->org[$key] = Organization::query()->create([
                'organization_type_id' => $type->id, 'code' => 'TR-'.strtoupper($key), 'name_en' => $name, 'name_am' => $name.' (am)',
                'status' => 'active', 'effective_from' => now()->subYear()->toDateString(),
            ]);
            OrganizationClosurePath::query()->create(['hierarchy_version_id' => $s->version->id, 'ancestor_organization_id' => $s->org[$key]->id, 'descendant_organization_id' => $s->org[$key]->id, 'depth' => 0]);
        }

        $s->attach('bureau', 'subcity_a');
        $s->attach('bureau', 'subcity_b');
        $s->attach('subcity_a', 'woreda_a1');

        return $s;
    }

    /** @return list<string> every staff transport permission in the catalog */
    public static function permissions(): array
    {
        return array_values(array_filter(PermissionCatalog::names(), fn (string $name): bool => str_starts_with($name, 'transport-')));
    }

    /** @param  list<string>  $services */
    public function provider(string $name = 'Blue Line', array $services = ['transport']): Provider
    {
        $type = ProviderType::query()->firstOrCreate(['code' => 'TRANSPORT'], ['name_en' => 'Transport', 'is_active' => true]);
        $provider = Provider::query()->create([
            'provider_code' => 'TRP-'.self::next(),
            'provider_type_id' => $type->id,
            'name_en' => $name,
            'status' => 'active',
            'assigned_scope_type' => 'citywide',
        ]);

        foreach ($services as $code) {
            DB::table('provider_services')->insert([
                'id' => (string) Str::uuid7(),
                'provider_id' => $provider->id,
                'service_type_id' => ServiceType::query()->firstOrCreate(['code' => $code], ['name_en' => Str::title($code), 'is_active' => true])->id,
                'status' => 'active',
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $provider->fresh();
    }

    /** @param  list<string>  $keys  provider-user permission keys (operators hold only these) */
    public function portalUser(Provider $provider, string $role = 'operator', array $keys = []): ProviderUser
    {
        $user = ProviderUser::query()->create([
            'provider_id' => $provider->id, 'name' => 'Portal '.$role, 'email' => strtolower($role).'-'.self::next().'@example.test',
            'username' => strtolower($role).'_'.strtolower(self::next()), 'password' => Hash::make('password'),
            'provider_role' => $role, 'status' => 'active', 'portal_enabled' => true,
        ]);

        $serviceTypeId = ServiceType::query()->where('code', 'transport')->value('id');
        foreach ($keys as $key) {
            ProviderUserServicePermission::query()->create([
                'provider_user_id' => $user->id, 'service_type_id' => $serviceTypeId, 'permission_key' => $key, 'is_allowed' => true,
            ]);
        }

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    public function route(Provider $provider, array $overrides = []): TransportRoute
    {
        return TransportRoute::query()->create($overrides + [
            'provider_id' => $provider->id, 'route_code' => 'R-'.self::next(), 'name_en' => 'Route '.self::$sequence,
            'origin_en' => 'Piassa', 'destination_en' => 'Bole', 'assigned_scope_type' => 'citywide', 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public function vehicle(Provider $provider, array $overrides = []): TransportVehicle
    {
        return TransportVehicle::query()->create($overrides + [
            'provider_id' => $provider->id, 'vehicle_code' => 'V-'.self::next(), 'plate_number' => 'AA-'.self::next(),
            'vehicle_type' => 'bus', 'capacity' => 50, 'status' => 'active',
        ]);
    }

    /**
     * A trip that is boarding today on a vehicle, in the inbound (morning)
     * direction unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function trip(TransportRoute $route, array $overrides = []): TransportTrip
    {
        $vehicleId = array_key_exists('transport_vehicle_id', $overrides) ? $overrides['transport_vehicle_id'] : $this->vehicle($route->provider)->id;

        return TransportTrip::query()->create($overrides + [
            'provider_id' => $route->provider_id, 'transport_route_id' => $route->id, 'transport_vehicle_id' => $vehicleId,
            'trip_number' => 'TRP-T-'.self::next(), 'direction' => 'inbound',
            'trip_date' => today()->toDateString(), 'departure_time' => '07:30', 'status' => 'boarding',
            'actual_started_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public function stop(TransportRoute $route, int $sequence, array $overrides = []): TransportRouteStop
    {
        return TransportRouteStop::query()->create($overrides + [
            'transport_route_id' => $route->id, 'stop_code' => 'S-'.$sequence.'-'.self::next(), 'name_en' => 'Stop '.$sequence,
            'sequence' => $sequence, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public function schedule(TransportRoute $route, array $overrides = []): TransportSchedule
    {
        return TransportSchedule::query()->create($overrides + [
            'provider_id' => $route->provider_id, 'transport_route_id' => $route->id, 'direction' => 'inbound',
            'service_days' => [1, 2, 3, 4, 5], 'planned_departure' => '06:30', 'planned_arrival' => '07:30',
            'effective_from' => today()->subMonth()->toDateString(), 'is_active' => true,
        ]);
    }

    /** A registered scanner; returns the device and its plain credential. @param  array<string, mixed>  $overrides @return array{0: TransportDevice, 1: string} */
    public function device(Provider $provider, ?TransportVehicle $vehicle = null, array $overrides = []): array
    {
        $plain = TransportDevice::CREDENTIAL_PREFIX.Str::random(40);
        $device = TransportDevice::query()->create($overrides + [
            'provider_id' => $provider->id, 'transport_vehicle_id' => $vehicle?->id, 'device_code' => 'DEV-'.self::next(), 'name' => 'Scanner',
            'status' => 'active', 'credential_hash' => TransportDevice::hashCredential($plain), 'credential_prefix' => substr($plain, 0, 12),
        ]);

        return [$device, $plain];
    }

    /** An organization-level eligibility grant. @param  array<string, mixed>  $overrides */
    public function grant(string $orgKey, string $scope = 'subtree', array $overrides = []): TransportEligibility
    {
        return TransportEligibility::query()->create($overrides + [
            'subject_type' => 'organization', 'organization_id' => $this->org[$orgKey]->id, 'scope_type' => $scope,
            'status' => 'active', 'effective_from' => today()->subMonth()->toDateString(), 'authorization_reference' => 'TEST-AUTH',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public function contract(Provider $provider, string $model = 'per_verified_boarding', array $terms = ['rate_per_boarding' => '12.50'], array $overrides = []): TransportContract
    {
        return TransportContract::query()->create($overrides + [
            'contract_reference' => 'CTR-'.self::next(), 'provider_id' => $provider->id, 'version_no' => 1, 'status' => 'active',
            'currency_code' => 'ETB', 'settlement_model' => $model, 'settlement_period' => 'monthly', 'terms' => $terms,
            'effective_from' => today()->subYear()->toDateString(), 'approved_at' => now()->subYear(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public function pass(Employee $employee, Provider $provider, ?TransportRoute $route = null, array $overrides = []): TransportPass
    {
        return TransportPass::query()->create($overrides + [
            'employee_id' => $employee->id, 'provider_id' => $provider->id, 'transport_route_id' => $route?->id,
            'valid_from' => today()->subMonth()->toDateString(), 'valid_until' => today()->addMonth()->toDateString(), 'status' => 'active',
        ]);
    }

    /** @return array{0: Employee, 1: IdCard} */
    public function employee(string $orgKey = 'woreda_a1', string $name = 'Abebe Kebede'): array
    {
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-'.self::next(), 'first_name' => 'Test', 'last_name' => 'Employee', 'full_name' => $name,
            'status' => EmployeeStatus::Active,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $this->org[$orgKey]->id, 'assignment_status' => 'active',
            'effective_from' => now()->subYear()->toDateString(), 'is_current' => true,
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

        $card = IdCard::query()->create([
            'employee_id' => $employee->id, 'card_number' => 'CARD-'.self::next(), 'status' => CardStatus::Active,
            'expires_at' => now()->addYears(2), 'activated_at' => now(), 'is_current' => true, 'qr_status' => 'active',
            'public_card_uuid' => (string) Str::uuid(),
        ]);

        return [$employee->fresh(), $card];
    }

    public function qr(IdCard $card): string
    {
        return app(CardQrPayloadService::class)->buildStableQrUrl($card);
    }

    /** @param  list<string>  $permissions */
    public function staff(array $permissions, ?string $orgKey = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        if ($orgKey !== null) {
            UserOrganizationScope::query()->create([
                'user_id' => $user->id, 'organization_id' => $this->org[$orgKey]->id, 'scope_type' => OrganizationScopeType::Subtree,
                'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
            ]);
        }

        return $user->fresh();
    }

    private function attach(string $parent, string $child): void
    {
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => $this->version->id, 'parent_organization_id' => $this->org[$parent]->id, 'child_organization_id' => $this->org[$child]->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo, 'effective_from' => now()->subYear()->toDateString(),
        ]);
        $ancestors = OrganizationClosurePath::query()->where('hierarchy_version_id', $this->version->id)->where('descendant_organization_id', $this->org[$parent]->id)->get();
        foreach ($ancestors as $path) {
            OrganizationClosurePath::query()->create(['hierarchy_version_id' => $this->version->id, 'ancestor_organization_id' => $path->ancestor_organization_id, 'descendant_organization_id' => $this->org[$child]->id, 'depth' => $path->depth + 1]);
        }
    }

    private static function next(): string
    {
        return ++self::$sequence.Str::upper(Str::random(4));
    }
}
