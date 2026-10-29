<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Models\Position;
use Illuminate\Validation\ValidationException;

/**
 * A unit has at most one head position. Refuses marking a second one, naming
 * the position that already leads the unit.
 */
class EnsureSingleUnitHead
{
    public function execute(?string $organizationUnitId, ?string $exceptPositionId = null): void
    {
        if ($organizationUnitId === null || $organizationUnitId === '') {
            return;
        }

        $current = Position::query()
            ->where('organization_unit_id', $organizationUnitId)
            ->where('is_unit_head', true)
            ->when($exceptPositionId !== null, fn ($query) => $query->whereKeyNot($exceptPositionId))
            ->first(['id', 'title_en', 'title_am', 'job_position_code']);

        if ($current === null) {
            return;
        }

        $title = app()->getLocale() === 'am'
            ? ($current->title_am ?: $current->title_en)
            : ($current->title_en ?: $current->title_am);

        throw ValidationException::withMessages([
            'is_unit_head' => __('positions.unit_head_taken', ['position' => trim($title.' ('.$current->job_position_code.')')]),
        ]);
    }
}
