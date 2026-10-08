<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use Illuminate\Database\Query\Builder;

/**
 * What one user may see in Assessment Oversight, resolved once on the server.
 *
 * organizationIds null means city-wide; an empty array means nothing.
 * unitIds null means no unit limit; otherwise only those units (a line
 * manager's own unit subtree).
 */
final class OversightScope
{
    /**
     * @param  array<int, string>|null  $organizationIds
     * @param  array<int, string>|null  $unitIds
     */
    public function __construct(public readonly ?array $organizationIds, public readonly ?array $unitIds = null) {}

    public function isCityWide(): bool
    {
        return $this->organizationIds === null && $this->unitIds === null;
    }

    public function isEmpty(): bool
    {
        return $this->organizationIds === [] || $this->unitIds === [];
    }

    public function allowsOrganization(?string $organizationId): bool
    {
        return $organizationId !== null && ($this->organizationIds === null || in_array($organizationId, $this->organizationIds, true));
    }

    public function allowsUnit(?string $unitId): bool
    {
        return $this->unitIds === null || ($unitId !== null && in_array($unitId, $this->unitIds, true));
    }

    /** Restrict a query; the columns name where the row's organization and unit live. */
    public function apply(Builder $query, string $organizationColumn, ?string $unitColumn = null): Builder
    {
        if ($this->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->organizationIds !== null) {
            $query->whereIn($organizationColumn, $this->organizationIds);
        }
        if ($this->unitIds !== null) {
            $unitColumn === null ? $query->whereRaw('1 = 0') : $query->whereIn($unitColumn, $this->unitIds);
        }

        return $query;
    }

    /** Stable key part so cached aggregates never cross scopes. */
    public function cacheKey(): string
    {
        return hash('sha256', json_encode([$this->organizationIds === null ? null : array_values(array_unique($this->organizationIds)), $this->unitIds]));
    }
}
