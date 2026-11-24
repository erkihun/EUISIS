<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\OrganizationLookup;
use App\Backbone\Data\OrganizationData;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Services\Hierarchy\HierarchyVersionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Batched organization reads from the existing table. */
final readonly class EloquentOrganizationLookup implements OrganizationLookup
{
    public function __construct(private HierarchyVersionResolver $versions) {}

    public function findMany(array $organizationUuids): array
    {
        $ids = array_values(array_unique(array_filter($organizationUuids, static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Organization::query()->whereKey($chunk)->get() as $organization) {
                $found[(string) $organization->getKey()] = OrganizationData::fromModel($organization);
            }
        }

        return $found;
    }

    public function search(string $term, ?array $withinUuids = null, int $limit = 20): array
    {
        $like = ci_like_operator();
        $escaped = addcslashes(trim($term), '%_\\');

        return $this->active($withinUuids)
            ->when($escaped !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('code', $like, $escaped.'%')
                ->orWhere('name_en', $like, '%'.$escaped.'%')
                ->orWhere('name_am', $like, '%'.$escaped.'%')))
            ->orderBy('name_en')->limit(max(1, min($limit, 100)))->get()
            ->map(fn (Organization $organization): OrganizationData => OrganizationData::fromModel($organization))->values()->all();
    }

    public function page(?array $withinUuids, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min($perPage, 200));
        $query = $this->active($withinUuids);

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('code')->forPage(max(1, $page), $perPage)->get()
                ->map(fn (Organization $organization): OrganizationData => OrganizationData::fromModel($organization))->values()->all(),
        ];
    }

    public function currentHierarchyVersionUuid(): ?string
    {
        $version = $this->versions->resolveDefault();

        return $version === null ? null : (string) $version->getKey();
    }

    /**
     * @param  list<string>|null  $withinUuids
     * @return Builder<Organization>
     */
    private function active(?array $withinUuids): Builder
    {
        return Organization::query()->where('status', OrganizationStatus::Active->value)
            ->when($withinUuids !== null, fn (Builder $q) => $q->whereIn('id', $withinUuids));
    }
}
