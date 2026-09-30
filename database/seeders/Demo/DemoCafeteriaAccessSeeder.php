<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CafeteriaGrantStatus;
use App\Models\CafeteriaServiceAssignment;
use App\Models\OrganizationCafeteriaAccess;
use App\Services\Cafeteria\Network\CafeteriaAccessService;
use App\Services\Cafeteria\Network\CafeteriaAssignmentService;
use App\Support\Demo\DemoDataset;
use RuntimeException;

/**
 * Organization cafeteria access (who may eat where) and service assignments
 * (which provider may serve whom). Each is drafted by the DEMO City Admin
 * and approved by a second person, as in production. Access is explicit per
 * organization; nothing is inherited from a location or a similar policy.
 */
class DemoCafeteriaAccessSeeder extends DemoSeeder
{
    public function run(CafeteriaAccessService $accessService, CafeteriaAssignmentService $assignmentService): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $checker = DemoDataset::requireUser(DemoDataset::CHECKER_EMAIL);
        $epoch = DemoDataset::epoch()->toDateString();

        foreach (DemoDataset::access() as $organizationKey => $grants) {
            $organization = DemoDataset::requireOrganization($organizationKey);

            foreach ($grants as $grant) {
                $network = DemoDataset::network($grant['network']) ?? throw new RuntimeException("Demo network {$grant['network']} is missing.");
                $existing = OrganizationCafeteriaAccess::query()->where('organization_id', $organization->id)
                    ->where('cafeteria_service_network_id', $network->id)->first();
                if ($existing !== null) {
                    continue;
                }

                $access = $accessService->create([
                    'organization_id' => $organization->id,
                    'cafeteria_service_network_id' => $network->id,
                    'primary_cafeteria_id' => DemoDataset::cafeteria($grant['network'], $grant['primary'])->id,
                    'allow_cross_location_usage' => $grant['cross_location'],
                    'effective_from' => $epoch,
                    'notes' => 'Synthetic demo access',
                    'location_exceptions' => collect($grant['exceptions'])->map(fn (bool $allowed, string $location): array => [
                        'cafeteria_id' => DemoDataset::cafeteria($grant['network'], $location)->id,
                        'is_allowed' => $allowed,
                    ])->values()->all(),
                ], $maker);
                $accessService->approve($access, $checker);
            }
        }

        foreach (DemoDataset::serviceAssignments() as $organizationKey => $definition) {
            $organization = DemoDataset::requireOrganization($organizationKey);
            $provider = DemoDataset::provider($definition['provider']) ?? throw new RuntimeException("Demo provider {$definition['provider']} is missing.");
            $networkId = $definition['network'] !== null ? DemoDataset::network($definition['network'])->id : null;

            $exists = CafeteriaServiceAssignment::query()->where('organization_id', $organization->id)
                ->where('provider_id', $provider->id)
                ->where(fn ($q) => $networkId === null ? $q->whereNull('cafeteria_service_network_id') : $q->where('cafeteria_service_network_id', $networkId))
                ->whereIn('status', [CafeteriaGrantStatus::PendingApproval->value, CafeteriaGrantStatus::Active->value])
                ->exists();
            if ($exists) {
                continue;
            }

            $assignment = $assignmentService->create([
                'organization_id' => $organization->id,
                'provider_id' => $provider->id,
                'cafeteria_service_network_id' => $networkId,
                'effective_from' => $epoch,
                'notes' => 'Synthetic demo service assignment',
            ], $maker);
            $assignmentService->approve($assignment, $checker);
        }
    }
}
