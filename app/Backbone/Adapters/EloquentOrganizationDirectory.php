<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Data\OrganizationData;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Reads organizations from the existing table; scope comes from the one canonical OrganizationScopeService. */
final readonly class EloquentOrganizationDirectory implements OrganizationDirectory
{
    public function __construct(private OrganizationScopeService $scope) {}

    public function find(string $organizationUuid): ?OrganizationData
    {
        if (! Str::isUuid($organizationUuid)) {
            return null;
        }
        $organization = Organization::query()->whereKey($organizationUuid)->first();

        return $organization === null ? null : OrganizationData::fromModel($organization);
    }

    public function findByCode(string $code): ?OrganizationData
    {
        $organization = Organization::query()->where('code', $code)->first();

        return $organization === null ? null : OrganizationData::fromModel($organization);
    }

    public function findFor(User $viewer, string $organizationUuid): ?OrganizationData
    {
        $organization = $this->find($organizationUuid);

        return $organization !== null && $this->scope->canAccessOrganization($viewer, $organization->organizationUuid) ? $organization : null;
    }

    public function activeFor(User $viewer, int $limit = 100): array
    {
        $query = Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->limit(max(1, $limit));

        return $this->scope->applyOrganizationScope($query, $viewer, 'id')
            ->get()
            ->map(fn (Organization $organization): OrganizationData => OrganizationData::fromModel($organization))
            ->values()
            ->all();
    }

    public function searchActiveFor(User $viewer, string $term, int $limit = 20): array
    {
        $like = ci_like_operator();
        $escaped = addcslashes($term, '%_\\');

        $query = Organization::query()
            ->where('status', OrganizationStatus::Active->value)
            ->where(fn (Builder $q) => $q
                ->where('code', $like, $escaped.'%')
                ->orWhere('name_en', $like, '%'.$escaped.'%')
                ->orWhere('name_am', $like, '%'.$escaped.'%'))
            ->orderBy('name_en')
            ->limit(max(1, $limit));

        return $this->scope->applyOrganizationScope($query, $viewer, 'id')
            ->get()
            ->map(fn (Organization $organization): OrganizationData => OrganizationData::fromModel($organization))
            ->values()
            ->all();
    }
}
