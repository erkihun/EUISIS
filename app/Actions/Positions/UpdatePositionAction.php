<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Positions\PositionCapacityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class UpdatePositionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private PositionCapacityService $capacity,
        private OrganizationScopeService $organizationScope,
        private EnsureSingleUnitHead $ensureSingleUnitHead,
    ) {}

    public function execute(Position $position, array $attributes, User $actor): Position
    {
        return DB::transaction(function () use ($position, $attributes, $actor): Position {
            // The same row registration and transfers lock, so a seat cannot be
            // taken while the position is being deactivated or moved.
            $locked = Position::query()->lockForUpdate()->findOrFail($position->getKey());
            $oldValues = $locked->toArray();

            $attributes = $this->fillOrganizationFromUnit($locked, $attributes, $actor);
            $this->refuseStrandingHolders($locked, $attributes);

            if ((bool) ($attributes['is_unit_head'] ?? $locked->is_unit_head)) {
                $this->ensureSingleUnitHead->execute(
                    (string) ($attributes['organization_unit_id'] ?? $locked->organization_unit_id),
                    (string) $locked->getKey(),
                );
            }

            $locked->update($attributes);
            $fresh = $locked->fresh();

            $this->writeAuditLogAction->execute(
                AuditEventType::PositionUpdated,
                $actor,
                $fresh,
                $fresh->organization_id,
                oldValues: $oldValues,
                newValues: $fresh->toArray(),
            );

            return $fresh;
        });
    }

    /**
     * Same rule as CreatePositionAction: a blank organization with a unit is the
     * unit's organization. A position must not silently become citywide (visible
     * and editable everywhere) unless the actor is unrestricted.
     */
    private function fillOrganizationFromUnit(Position $position, array $attributes, User $actor): array
    {
        if (! array_key_exists('organization_id', $attributes) || filled($attributes['organization_id'])) {
            return $attributes;
        }

        $unitId = array_key_exists('organization_unit_id', $attributes)
            ? $attributes['organization_unit_id']
            : $position->organization_unit_id;
        if (filled($unitId)) {
            $attributes['organization_id'] = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');

            return $attributes;
        }

        if ($position->organization_id !== null && ! $this->organizationScope->isUnrestricted($actor)) {
            throw ValidationException::withMessages(['organization_id' => __('positions.organization_required')]);
        }

        return $attributes;
    }

    /**
     * While employees hold the position it may not be deactivated, ended or moved
     * here: a move goes through MovePositionAction, an exit through the holders'
     * transfer or separation first.
     */
    private function refuseStrandingHolders(Position $position, array $attributes): void
    {
        $holders = $this->capacity->currentOccupants((string) $position->getKey());
        if ($holders === 0) {
            return;
        }

        $changes = fn (string $key): bool => array_key_exists($key, $attributes)
            && (string) ($attributes[$key] ?? '') !== (string) ($position->getRawOriginal($key) ?? '');

        if ($changes('organization_id') || $changes('organization_unit_id')) {
            throw ValidationException::withMessages(['organization_unit_id' => __('positions.occupied_cannot_move')]);
        }

        if (array_key_exists('is_active', $attributes) && ! filter_var($attributes['is_active'], FILTER_VALIDATE_BOOL) && $position->is_active) {
            throw ValidationException::withMessages([
                'is_active' => __('positions.archive_blocked_active_assignments', ['count' => $holders]),
            ]);
        }

        $endsOn = $attributes['effective_to'] ?? null;
        $currentEnd = $position->effective_to?->toDateString();
        if (filled($endsOn) && Carbon::parse($endsOn)->toDateString() !== $currentEnd
            && Carbon::parse($endsOn)->startOfDay()->lte(today())) {
            throw ValidationException::withMessages([
                'effective_to' => __('positions.archive_blocked_active_assignments', ['count' => $holders]),
            ]);
        }
    }
}
