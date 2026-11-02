<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Support\Database\HeadPositionIndexes;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A unit has at most one head position, and so has an organization (a head
 * position placed in no unit leads the organization itself: ADR 0025).
 * Refuses marking a second one, naming the position that already leads.
 *
 * Two requests racing for the same unit are serialised on the unit (or
 * organization) row when the caller runs inside a transaction, and the
 * partial unique index on positions (HeadPositionIndexes) is the backstop
 * that turns the loser's save into the same validation error.
 */
class EnsureSingleUnitHead
{
    public function execute(?string $organizationUnitId, ?string $exceptPositionId = null, ?string $organizationId = null): void
    {
        $unitId = $organizationUnitId === '' ? null : $organizationUnitId;

        if ($unitId !== null) {
            $this->lockRow(OrganizationUnit::query()->whereKey($unitId));
            $current = Position::query()
                ->where('organization_unit_id', $unitId)
                ->where('is_unit_head', true)
                ->when($exceptPositionId !== null, fn ($query) => $query->whereKeyNot($exceptPositionId))
                ->first(['id', 'title_en', 'title_am', 'job_position_code']);
            $message = 'positions.unit_head_taken';
        } else {
            if ($organizationId === null || $organizationId === '') {
                return;
            }
            $this->lockRow(Organization::query()->whereKey($organizationId));
            $current = Position::query()
                ->where('organization_id', $organizationId)
                ->whereNull('organization_unit_id')
                ->where('is_unit_head', true)
                ->when($exceptPositionId !== null, fn ($query) => $query->whereKeyNot($exceptPositionId))
                ->first(['id', 'title_en', 'title_am', 'job_position_code']);
            $message = 'positions.organization_head_taken';
        }

        if ($current === null) {
            return;
        }

        throw ValidationException::withMessages([
            'is_unit_head' => __($message, ['position' => self::describe($current)]),
        ]);
    }

    /**
     * Run a save that may trip the one-head index and report the collision
     * as the same validation error a check would have raised.
     *
     * @template T
     *
     * @param  Closure(): T  $save
     * @return T
     */
    public function guard(Closure $save, ?string $organizationUnitId = null)
    {
        try {
            return $save();
        } catch (QueryException $e) {
            if (! HeadPositionIndexes::isViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages([
                'is_unit_head' => __($organizationUnitId ? 'positions.unit_head_taken_concurrently' : 'positions.organization_head_taken_concurrently'),
            ]);
        }
    }

    public static function describe(Position $position): string
    {
        $title = app()->getLocale() === 'am'
            ? ($position->title_am ?: $position->title_en)
            : ($position->title_en ?: $position->title_am);

        return trim($title.' ('.$position->job_position_code.')');
    }

    /** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query */
    private function lockRow($query): void
    {
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate()->first(['id']);
        }
    }
}
