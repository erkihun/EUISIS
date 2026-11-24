<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Contracts\OrganizationLookup;
use App\Backbone\Contracts\UnitDirectory;
use App\Backbone\Data\OrganizationData;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\OrganizationSummary;
use Modules\Reporting\Application\Data\UnitSummary;
use Modules\Reporting\Application\Ports\OrganizationDirectory;

/**
 * Organizations through the Core's published contracts (App\Backbone). This
 * adapter is the only place Reporting touches them; it uses no Core model and
 * no Core table.
 */
final readonly class CoreOrganizationDirectory implements OrganizationDirectory
{
    public function __construct(private OrganizationLookup $lookup, private OrganizationHierarchy $hierarchy, private UnitDirectory $unitDirectory) {}

    public function find(string $organizationId): ?OrganizationSummary
    {
        return $this->findMany([$organizationId])[$organizationId] ?? null;
    }

    public function findMany(array $organizationIds): array
    {
        return array_map(self::summary(...), $this->lookup->findMany($organizationIds));
    }

    public function hierarchySnapshot(string $organizationId): array
    {
        $version = $this->lookup->currentHierarchyVersionUuid();
        $ancestorIds = $this->hierarchy->ancestorIds($organizationId, $version);
        $ancestors = $this->lookup->findMany($ancestorIds);

        return [
            'version' => $version,
            // Nearest first, with the names in force when the snapshot is taken.
            'ancestors' => array_values(array_filter(array_map(
                fn (string $id): ?array => isset($ancestors[$id]) ? self::summary($ancestors[$id])->toArray() : null,
                $ancestorIds,
            ))),
        ];
    }

    public function subtreeIds(string $organizationId): array
    {
        return $this->hierarchy->subtreeIds($organizationId, $this->lookup->currentHierarchyVersionUuid());
    }

    public function search(ActorContext $actor, string $term, int $limit = 20): array
    {
        return array_map(self::summary(...), $this->lookup->search($term, $actor->organizationFilter(), $limit));
    }

    public function page(ActorContext $actor, int $page = 1, int $perPage = 50): array
    {
        $result = $this->lookup->page($actor->organizationFilter(), $page, $perPage);

        return ['items' => array_map(self::summary(...), $result['items']), 'total' => $result['total']];
    }

    public function units(string $organizationId): array
    {
        $units = [];
        foreach ($this->unitDirectory->forOrganization($organizationId) as $unit) {
            $units[$unit->unitUuid] = new UnitSummary($unit->unitUuid, $unit->organizationUuid, $unit->code, $unit->nameEn, $unit->nameAm);
        }

        return $units;
    }

    private static function summary(OrganizationData $organization): OrganizationSummary
    {
        return new OrganizationSummary($organization->organizationUuid, $organization->code, $organization->nameEn, $organization->nameAm, $organization->status === 'active');
    }
}
