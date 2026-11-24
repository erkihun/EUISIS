<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\OrganizationSummary;
use Modules\Reporting\Application\Data\UnitSummary;

/**
 * What Reporting asks of whoever owns organizations. Reporting stores the ids
 * it gets back as references and the names as snapshots; it never reads the
 * owner's tables.
 */
interface OrganizationDirectory
{
    public function find(string $organizationId): ?OrganizationSummary;

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, OrganizationSummary> keyed by id
     */
    public function findMany(array $organizationIds): array;

    /**
     * The organization's place in the structure in force now: its ancestors,
     * nearest first, with the names of today, and the structure version.
     *
     * @return array{version: ?string, ancestors: list<array{id: string, code: string, name_en: ?string, name_am: ?string}>}
     */
    public function hierarchySnapshot(string $organizationId): array;

    /** @return list<string> the organization and everything under it in the structure in force */
    public function subtreeIds(string $organizationId): array;

    /**
     * Active organizations the actor reaches that match the term; bounded.
     *
     * @return list<OrganizationSummary>
     */
    public function search(ActorContext $actor, string $term, int $limit = 20): array;

    /**
     * One page of the active organizations the actor reaches.
     *
     * @return array{items: list<OrganizationSummary>, total: int}
     */
    public function page(ActorContext $actor, int $page = 1, int $perPage = 50): array;

    /**
     * The active work units of one institution, keyed by unit id.
     *
     * @return array<string, UnitSummary>
     */
    public function units(string $organizationId): array;
}
