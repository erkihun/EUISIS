<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * WHERE a Situation Room viewer may look: the organizations every aggregate
 * is confined to BEFORE it is computed.
 *
 * Built only by SituationScopeResolver from OrganizationScopeService (self,
 * subtree on the published hierarchy, or citywide) and, when the viewer
 * focuses one organization, that organization and its descendants — always
 * intersected with what the viewer may see. A parent therefore sees its
 * descendants rolled up, and nobody sees a sibling.
 *
 * `organizationIds === null` means "no organization filter": an unrestricted
 * viewer with no focus.
 */
final readonly class SituationScope
{
    /**
     * @param  list<string>|null  $organizationIds
     */
    public function __construct(
        public ?array $organizationIds,
        public ?string $focusOrganizationId,
        public bool $unrestricted,
    ) {}

    public function isEmpty(): bool
    {
        return $this->organizationIds === [];
    }

    /**
     * Confine a query to the scope on the given organization column.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function apply(Builder|QueryBuilder|QueryBuilderContract $query, string $column): Builder|QueryBuilder|QueryBuilderContract
    {
        if ($this->organizationIds === null) {
            return $query;
        }

        if ($this->organizationIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $this->organizationIds);
    }

    /** Stable identity of what this scope can see, for cache keys. Never the user's id alone. */
    public function fingerprint(): string
    {
        if ($this->organizationIds === null) {
            return 'all';
        }

        $ids = $this->organizationIds;
        sort($ids);

        return 'o:'.hash('sha256', implode(',', $ids));
    }
}
