<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts;

use App\Models\User;

/**
 * Performance plans as the position screens show them (ADR 0017).
 */
interface PositionPerformance
{
    /**
     * The plans of the position (in its organization) the user may see:
     * can_view, then current and historical lists of plans (id, title,
     * status, version, organization, unit, cycle, effective_from,
     * effective_to, is_current, goals).
     *
     * @return array{can_view: bool, current: list<array<string, mixed>>, historical: list<array<string, mixed>>}
     */
    public function positionPlans(User $user, string $positionId, ?string $organizationId): array;

    /**
     * Active plan items in published plans of running cycles, per position service.
     *
     * @param  list<string>  $positionServiceIds
     * @return array<string, int> by position service id; services without items are left out
     */
    public function currentPlanItemCounts(array $positionServiceIds): array;

    /** Whether any plan item, of any plan, cites the position service. */
    public function positionServiceInUse(string $positionServiceId): bool;
}
