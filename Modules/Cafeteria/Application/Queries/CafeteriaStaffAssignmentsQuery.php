<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\Cafeteria\Contracts\CafeteriaStaffAssignments;
use Modules\Cafeteria\Domain\Models\CafeteriaProviderAssignment;

final class CafeteriaStaffAssignmentsQuery implements CafeteriaStaffAssignments
{
    public function hasActiveAssignment(int|string $userId): bool
    {
        $today = Carbon::today()->toDateString();

        return CafeteriaProviderAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            // Archived locations do not count, as with the old belongs-to-many join.
            ->whereHas('provider')
            ->exists();
    }
}
