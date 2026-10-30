<?php

declare(strict_types=1);

namespace App\Console\Commands\Transport;

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ServiceType;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Domain\Models\TransportVehicle;

/**
 * Synthetic riders, cards, buses, scanners and today's trips for the k6
 * boarding load test (loadtest/k6/transport-boarding.js). Everything it
 * creates is prefixed LOAD- and lives in its own synthetic organization and
 * provider; it refuses to run in production and never touches a real
 * employee or card (brief §207).
 */
class SeedTransportLoadTest extends Command
{
    protected $signature = 'transport:seed-loadtest
        {path=storage/app/loadtest/transport-loadtest.json : Where to write the k6 input}
        {--employees=600 : Synthetic riders}
        {--routes=6 : Routes, each with two buses and two scanners}
        {--capacity=60 : Seats per bus}
        {--purge : Remove everything a previous run seeded and stop}';

    protected $description = 'Seed synthetic transport data for the k6 boarding load test (never production).';

    public function handle(CardQrPayloadService $qr): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed load-test data in production.');

            return self::FAILURE;
        }
        if ($this->option('purge')) {
            $this->purge();
            $this->info('Load-test data removed.');

            return self::SUCCESS;
        }
        $employees = max(10, (int) $this->option('employees'));
        $routeCount = max(1, (int) $this->option('routes'));
        $capacity = max(10, (int) $this->option('capacity'));

        $out = DB::transaction(function () use ($employees, $routeCount, $capacity, $qr): array {
            $type = OrganizationType::query()->firstOrCreate(['code' => 'LOAD-TYPE'], ['name_en' => 'Load test office']);
            $organization = Organization::query()->firstOrCreate(['code' => 'LOAD-ORG'], [
                'organization_type_id' => $type->id, 'name_en' => 'Load Test Organization', 'name_am' => 'የጫና ሙከራ ተቋም', 'status' => 'active', 'effective_from' => now()->subYear()->toDateString(),
            ]);
            $providerType = ProviderType::query()->firstOrCreate(['code' => 'TRANSPORT'], ['name_en' => 'Transport', 'is_active' => true]);
            $provider = Provider::query()->firstOrCreate(['provider_code' => 'LOAD-TRP'], [
                'provider_type_id' => $providerType->id, 'name_en' => 'Load Test Lines', 'name_am' => 'የጫና ሙከራ መስመሮች', 'status' => 'active', 'assigned_scope_type' => 'citywide',
            ]);
            $serviceTypeId = ServiceType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport', 'is_active' => true])->id;
            if (! DB::table('provider_services')->where('provider_id', $provider->id)->where('service_type_id', $serviceTypeId)->exists()) {
                DB::table('provider_services')->insert(['id' => (string) Str::uuid7(), 'provider_id' => $provider->id, 'service_type_id' => $serviceTypeId, 'status' => 'active', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            $devices = [];
            $routes = [];
            foreach (range(1, $routeCount) as $r) {
                $route = TransportRoute::query()->firstOrCreate(['provider_id' => $provider->id, 'route_code' => sprintf('LOAD-R%02d', $r)], [
                    'name_en' => "Load route {$r}", 'origin_en' => 'Depot', 'destination_en' => 'City Hall', 'assigned_scope_type' => 'citywide', 'is_active' => true,
                ]);
                $routes[] = $route;
                foreach ([1, 2] as $b) {
                    $vehicle = TransportVehicle::query()->firstOrCreate(['provider_id' => $provider->id, 'vehicle_code' => sprintf('LOAD-V%02d-%d', $r, $b)], [
                        'plate_number' => sprintf('LOAD-%02d%d', $r, $b), 'vehicle_type' => 'bus', 'capacity' => $capacity, 'status' => 'active',
                    ]);
                    $plain = TransportDevice::CREDENTIAL_PREFIX.Str::random(40);
                    TransportDevice::query()->where('provider_id', $provider->id)->where('device_code', sprintf('LOAD-D%02d-%d', $r, $b))->delete();
                    TransportDevice::query()->create([
                        'provider_id' => $provider->id, 'transport_vehicle_id' => $vehicle->id, 'device_code' => sprintf('LOAD-D%02d-%d', $r, $b), 'name' => 'Load scanner',
                        'status' => 'active', 'credential_hash' => TransportDevice::hashCredential($plain), 'credential_prefix' => substr($plain, 0, 12), 'platform' => 'k6',
                    ]);
                    $trips = [];
                    foreach (['inbound' => '07:00', 'outbound' => '17:30'] as $direction => $time) {
                        $trips[$direction] = TransportTrip::query()->create([
                            'provider_id' => $provider->id, 'transport_route_id' => $route->id, 'transport_vehicle_id' => $vehicle->id,
                            'trip_number' => 'LOAD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)), 'direction' => $direction,
                            'trip_date' => today()->toDateString(), 'departure_time' => $time, 'status' => 'assigned', 'capacity' => $capacity,
                        ])->id;
                    }
                    $devices[] = ['credential' => $plain, 'route' => $r - 1, 'bus' => $b - 1, 'trips' => $trips];
                }
            }

            $riders = [];
            foreach (range(1, $employees) as $i) {
                $employee = Employee::query()->create([
                    'employee_number' => sprintf('LOAD-%06d', $i), 'first_name' => 'Load', 'last_name' => "Rider {$i}", 'full_name' => "Load Rider {$i}", 'status' => EmployeeStatus::Active,
                ]);
                $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'assignment_status' => 'active', 'effective_from' => now()->subYear()->toDateString(), 'is_current' => true]);
                $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
                $card = IdCard::query()->create([
                    'employee_id' => $employee->id, 'card_number' => sprintf('LOAD-C%06d', $i), 'status' => CardStatus::Active, 'expires_at' => now()->addYears(2),
                    'activated_at' => now(), 'is_current' => true, 'qr_status' => 'active', 'public_card_uuid' => (string) Str::uuid(),
                ]);
                $route = $routes[$i % $routeCount];
                TransportPass::query()->create(['employee_id' => $employee->id, 'provider_id' => $provider->id, 'transport_route_id' => $route->id, 'valid_from' => today()->subMonth()->toDateString(), 'valid_until' => today()->addMonth()->toDateString(), 'status' => 'active']);
                $riders[] = ['qr' => $qr->buildStableQrUrl($card), 'route' => $i % $routeCount];
            }

            return ['generated_at' => now()->toIso8601String(), 'trip_date' => today()->toDateString(), 'capacity' => $capacity, 'devices' => $devices, 'riders' => $riders];
        });

        $path = (string) $this->argument('path');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info(sprintf('Seeded %d riders, %d scanners and %d trips; k6 input at %s', count($out['riders']), count($out['devices']), count($out['devices']) * 2, $path));

        return self::SUCCESS;
    }

    /** Everything is prefixed LOAD-; the synthetic provider's rows go first, then the riders and their cards. */
    private function purge(): void
    {
        DB::transaction(function (): void {
            $provider = Provider::query()->where('provider_code', 'LOAD-TRP')->first();
            if ($provider !== null) {
                DB::table('transport_transactions')->where('provider_id', $provider->id)->delete();
                DB::table('transport_exceptions')->where('provider_id', $provider->id)->delete();
                DB::table('transport_trip_positions')->whereIn('transport_trip_id', DB::table('transport_trips')->select('id')->where('provider_id', $provider->id))->delete();
                DB::table('transport_trip_vehicle_changes')->whereIn('transport_trip_id', DB::table('transport_trips')->select('id')->where('provider_id', $provider->id))->delete();
                DB::table('transport_trips')->where('provider_id', $provider->id)->delete();
                DB::table('transport_passes')->where('provider_id', $provider->id)->delete();
                DB::table('transport_devices')->where('provider_id', $provider->id)->delete();
                DB::table('transport_vehicles')->where('provider_id', $provider->id)->delete();
                DB::table('transport_routes')->where('provider_id', $provider->id)->delete();
                DB::table('provider_services')->where('provider_id', $provider->id)->delete();
                $provider->forceDelete();
            }
            $employeeIds = Employee::query()->where('employee_number', 'like', 'LOAD-%')->pluck('id');
            // Pointer and lineage are foreign keys on server databases: unlink before deleting.
            DB::table('employees')->whereIn('id', $employeeIds)->update(['current_assignment_id' => null]);
            DB::table('id_cards')->whereIn('employee_id', $employeeIds)->update(['previous_card_id' => null]);
            DB::table('id_cards')->whereIn('employee_id', $employeeIds)->delete();
            DB::table('employee_assignments')->whereIn('employee_id', $employeeIds)->delete();
            Employee::query()->whereIn('id', $employeeIds)->delete();
            Organization::query()->where('code', 'LOAD-ORG')->forceDelete();
        });
    }
}
