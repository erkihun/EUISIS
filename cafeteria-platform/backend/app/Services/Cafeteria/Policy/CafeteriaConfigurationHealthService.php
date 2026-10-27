<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\OrganizationCafeteriaAccess;
use Illuminate\Support\Carbon;

/**
 * Configuration errors that would block or mis-bill scans, for the dashboard.
 * Nothing here renews or changes a policy.
 *
 *  - missing_policy          active service assignment, no binding policy today
 *  - access_without_policy   organization may eat in a network, but no policy prices it
 *  - policy_without_access   a binding policy, but the organization has no access to use it
 *  - expiring                a binding policy ends soon and no successor is approved
 *  - pending                 access, assignments and policies awaiting a decision
 */
class CafeteriaConfigurationHealthService
{
    /**
     * @param  list<string>|null  $organizationIds  null = every organization
     * @return array<string, mixed>
     */
    public function summary(?array $organizationIds = null, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $scoped = fn ($query) => $organizationIds === null ? $query : $query->whereIn('organization_id', $organizationIds);

        $bindingToday = $scoped(CafeteriaServicePolicy::query()->bindingOn($today))
            ->get(['id', 'organization_id', 'provider_id', 'cafeteria_service_network_id', 'cafeteria_id', 'cafeteria_service_assignment_id', 'effective_to', 'policy_group_id', 'version_no']);

        $networkProviders = CafeteriaServiceNetwork::query()->pluck('provider_id', 'id');

        $missingPolicy = $scoped(CafeteriaServiceAssignment::query()->effectiveOn($today))
            ->with(['organization:id,name_en,name_am,code', 'provider:id,name_en,name_am,provider_code'])
            ->get()
            ->filter(fn (CafeteriaServiceAssignment $assignment): bool => ! $bindingToday->contains('cafeteria_service_assignment_id', $assignment->id))
            ->map(fn (CafeteriaServiceAssignment $a): array => [
                'id' => $a->id,
                'organization' => $a->organization?->only(['id', 'name_en', 'name_am', 'code']),
                'provider' => $a->provider?->only(['id', 'name_en', 'name_am', 'provider_code']),
            ])->values()->all();

        $accessToday = $scoped(OrganizationCafeteriaAccess::query()->effectiveOn($today))
            ->with(['organization:id,name_en,name_am,code', 'network:id,name_en,name_am,code,provider_id'])
            ->get();

        $accessWithoutPolicy = $accessToday
            ->filter(fn (OrganizationCafeteriaAccess $access): bool => ! $bindingToday->contains(
                fn (CafeteriaServicePolicy $p): bool => $p->organization_id === $access->organization_id
                    && $p->provider_id === $access->network?->provider_id
                    && ($p->cafeteria_service_network_id === null || $p->cafeteria_service_network_id === $access->cafeteria_service_network_id),
            ))
            ->map(fn (OrganizationCafeteriaAccess $a): array => [
                'id' => $a->id,
                'organization' => $a->organization?->only(['id', 'name_en', 'name_am', 'code']),
                'network' => $a->network?->only(['id', 'name_en', 'name_am', 'code']),
            ])->values()->all();

        $policyWithoutAccess = $bindingToday
            ->filter(fn (CafeteriaServicePolicy $policy): bool => ! $accessToday->contains(
                fn (OrganizationCafeteriaAccess $a): bool => $a->organization_id === $policy->organization_id
                    && ($policy->cafeteria_service_network_id === null
                        ? ($networkProviders[$a->cafeteria_service_network_id] ?? null) === $policy->provider_id
                        : $a->cafeteria_service_network_id === $policy->cafeteria_service_network_id),
            ))
            ->map(fn (CafeteriaServicePolicy $p): array => ['id' => $p->id, 'organization_id' => $p->organization_id, 'version_no' => $p->version_no])
            ->values()->all();

        $horizon = $today->copy()->addDays((int) config('cafeteria.policy_expiry_warning_days', 30));
        $expiring = $bindingToday
            ->filter(fn (CafeteriaServicePolicy $p): bool => $p->effective_to !== null && $p->effective_to->lte($horizon))
            ->filter(fn (CafeteriaServicePolicy $p): bool => ! CafeteriaServicePolicy::query()
                ->where('policy_group_id', $p->policy_group_id)
                ->where('version_no', '>', $p->version_no)
                ->whereIn('status', CafeteriaPolicyStatus::binding())
                ->exists())
            ->map(fn (CafeteriaServicePolicy $p): array => ['id' => $p->id, 'organization_id' => $p->organization_id, 'effective_to' => $p->effective_to->toDateString()])
            ->values()->all();

        return [
            'missing_policy' => $missingPolicy,
            'access_without_policy' => $accessWithoutPolicy,
            'policy_without_access' => $policyWithoutAccess,
            'expiring' => $expiring,
            'pending' => [
                'access' => $scoped(OrganizationCafeteriaAccess::query()->where('status', CafeteriaGrantStatus::PendingApproval->value))->count(),
                'assignments' => $scoped(CafeteriaServiceAssignment::query()->where('status', CafeteriaGrantStatus::PendingApproval->value))->count(),
                'policies' => $scoped(CafeteriaServicePolicy::query()->where('status', CafeteriaPolicyStatus::UnderReview->value))->count(),
            ],
        ];
    }
}
