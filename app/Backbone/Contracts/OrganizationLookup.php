<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\OrganizationData;

/**
 * Organizations in batches, for modules that hold organization references:
 * many by id in one query, and bounded search or pages inside a given set.
 * The caller supplies the set it is authorized for (AccessScopeReader); this
 * contract never widens it.
 */
interface OrganizationLookup
{
    /**
     * @param  list<string>  $organizationUuids
     * @return array<string, OrganizationData> keyed by organization uuid; unknown ids are absent
     */
    public function findMany(array $organizationUuids): array;

    /**
     * Active organizations matching the term (code prefix, or part of either name), by English name.
     *
     * @param  list<string>|null  $withinUuids  null: every organization
     * @return list<OrganizationData>
     */
    public function search(string $term, ?array $withinUuids = null, int $limit = 20): array;

    /**
     * One page of active organizations, by code.
     *
     * @param  list<string>|null  $withinUuids  null: every organization
     * @return array{items: list<OrganizationData>, total: int}
     */
    public function page(?array $withinUuids, int $page = 1, int $perPage = 50): array;

    /** The structure version in force, as a reference to store with a snapshot; null when none exists. */
    public function currentHierarchyVersionUuid(): ?string;
}
