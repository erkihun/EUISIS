<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardScopeService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScopeService,
    ) {}

    public function resolve(User $user, array $filters): array
    {
        $preset = in_array($filters['date_range'] ?? null, ['today', '7d', '30d', '90d', 'custom'], true)
            ? $filters['date_range']
            : '30d';

        $dateTo = isset($filters['date_to'])
            ? CarbonImmutable::parse((string) $filters['date_to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $dateFrom = match ($preset) {
            'today' => $dateTo->startOfDay(),
            '7d' => $dateTo->subDays(6)->startOfDay(),
            '90d' => $dateTo->subDays(89)->startOfDay(),
            'custom' => isset($filters['date_from'])
                ? CarbonImmutable::parse((string) $filters['date_from'])->startOfDay()
                : $dateTo->subDays(29)->startOfDay(),
            default => $dateTo->subDays(29)->startOfDay(),
        };

        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->startOfDay(), $dateFrom->endOfDay()];
        }

        $periodDays = max($dateFrom->diffInDays($dateTo) + 1, 1);
        $previousTo = $dateFrom->subSecond();
        $previousFrom = $previousTo->subDays($periodDays - 1)->startOfDay();

        $globalAccess = $user->hasRole('Super Admin') || $user->hasRole('City Admin');
        $baseOrganizationIds = $globalAccess
            ? Organization::query()->pluck('id')
            : $this->organizationScopeService->accessibleOrganizationIds($user);

        $publishedHierarchyVersion = HierarchyVersion::query()
            ->where('status', 'published')
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        $selectedOrganizationId = isset($filters['organization_id']) && $baseOrganizationIds->contains($filters['organization_id'])
            ? (string) $filters['organization_id']
            : null;

        $organizationIds = $selectedOrganizationId !== null
            ? $this->organizationScopeService
                ->descendantsForOrganization($selectedOrganizationId, $publishedHierarchyVersion?->id)
                ->pluck('descendant_organization_id')
            : $baseOrganizationIds;

        $providerScopeRows = $user->organizationScopes()
            ->where('scope_type', OrganizationScopeType::ServiceProvider->value)
            ->get(['service_provider_id', 'service_type_id']);

        $providerIds = $providerScopeRows
            ->pluck('service_provider_id')
            ->filter()
            ->unique()
            ->values();

        $serviceTypeIds = $providerScopeRows
            ->pluck('service_type_id')
            ->filter()
            ->unique()
            ->values();

        if (isset($filters['provider_id']) && $providerIds->isNotEmpty()) {
            $providerIds = $providerIds->contains($filters['provider_id'])
                ? collect([(string) $filters['provider_id']])
                : collect();
        }

        if (isset($filters['service_type_id']) && $serviceTypeIds->isNotEmpty()) {
            $serviceTypeIds = $serviceTypeIds->contains($filters['service_type_id'])
                ? collect([(string) $filters['service_type_id']])
                : collect();
        }

        return [
            'global_access' => $globalAccess,
            'provider_only' => $user->hasRole('Service Provider User'),
            'organization_ids' => $organizationIds->values()->all(),
            'selected_organization_id' => $selectedOrganizationId,
            'provider_ids' => $providerIds->all(),
            'service_type_ids' => $serviceTypeIds->all(),
            'date_range' => $preset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'previous_from' => $previousFrom,
            'previous_to' => $previousTo,
            'top_limit' => 10,
            'activity_limit' => 8,
            'published_hierarchy_version_id' => $publishedHierarchyVersion?->id,
            'organization_options' => $this->organizationOptionsForFilter($baseOrganizationIds),
        ];
    }

    private function organizationOptionsForFilter(Collection $accessibleOrganizationIds): array
    {
        if ($accessibleOrganizationIds->isEmpty()) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', $accessibleOrganizationIds)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name_en')
            ->limit(50)
            ->get(['id', 'name_en', 'code'])
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name_en,
                'code' => $organization->code,
            ])
            ->all();
    }
}
