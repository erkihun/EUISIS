<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\User;

/**
 * Everything one Situation Room computation may depend on: the viewer, the
 * scope already resolved for them, the cleaned filters and the display
 * settings. Analytics read only this, so a cache key built from it is
 * complete.
 */
final readonly class SituationContext
{
    public function __construct(
        public User $user,
        public SituationScope $scope,
        public SituationFilters $filters,
        public int $topN = 10,
    ) {}

    public function period(): SituationPeriod
    {
        return $this->filters->period;
    }

    public function can(string $permission): bool
    {
        return $this->user->can($permission);
    }

    /** What changes the payload besides scope and filters: the detail grants that add or hide columns. */
    public function grantsFingerprint(): string
    {
        return implode('', array_map(fn (string $p): string => $this->can($p) ? '1' : '0', SituationPermissions::DETAIL_GRANTS));
    }
}
