<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionMovement;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class MovePositionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private OrganizationScopeService $organizationScopeService,
    ) {}

    public function execute(
        Position $position,
        string $targetOrganizationUnitId,
        string $reason,
        User $actor,
        ?Request $request = null,
    ): PositionMovement {
        return DB::transaction(function () use ($position, $targetOrganizationUnitId, $reason, $actor, $request): PositionMovement {
            $lockedPosition = Position::query()
                ->lockForUpdate()
                ->findOrFail($position->getKey());

            if (! $lockedPosition->is_active || $lockedPosition->trashed()) {
                throw ValidationException::withMessages([
                    'target_organization_unit_id' => __('positions.archived_position_cannot_move'),
                ]);
            }

            if (! $this->organizationScopeService->canExercisePermission(
                $actor,
                'positions.move',
                $lockedPosition->organization_id,
            )) {
                abort(403);
            }

            $targetUnit = OrganizationUnit::query()
                ->lockForUpdate()
                ->find($targetOrganizationUnitId);

            if ($targetUnit === null
                || $targetUnit->organization_id !== $lockedPosition->organization_id
                || $targetUnit->status !== OrganizationUnitStatus::Active) {
                throw ValidationException::withMessages([
                    'target_organization_unit_id' => __('positions.invalid_target_unit'),
                ]);
            }

            if ($targetUnit->getKey() === $lockedPosition->organization_unit_id) {
                throw ValidationException::withMessages([
                    'target_organization_unit_id' => __('positions.same_target_unit'),
                ]);
            }

            $hasActiveAssignment = $lockedPosition->assignments()
                ->where('assignment_status', AssignmentStatus::Active->value)
                ->where('is_current', true)
                ->lockForUpdate()
                ->get(['id'])
                ->isNotEmpty();

            if ($hasActiveAssignment) {
                throw ValidationException::withMessages([
                    'target_organization_unit_id' => __('positions.occupied_cannot_move'),
                ]);
            }

            $fromOrganizationUnitId = $lockedPosition->organization_unit_id;
            $movedAt = now();

            $lockedPosition->forceFill([
                'organization_unit_id' => $targetUnit->getKey(),
            ])->save();

            $movement = PositionMovement::query()->create([
                'position_id' => $lockedPosition->getKey(),
                'organization_id' => $lockedPosition->organization_id,
                'from_organization_unit_id' => $fromOrganizationUnitId,
                'to_organization_unit_id' => $targetUnit->getKey(),
                'moved_by' => $actor->getKey(),
                'reason' => $reason,
                'moved_at' => $movedAt,
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::PositionMoved,
                $actor,
                $lockedPosition,
                $lockedPosition->organization_id,
                oldValues: ['organization_unit_id' => $fromOrganizationUnitId],
                newValues: [
                    'organization_unit_id' => $targetUnit->getKey(),
                    'moved_by' => $actor->getKey(),
                    'moved_at' => $movedAt->toISOString(),
                ],
                reason: $reason,
                request: $request,
            );

            return $movement;
        });
    }
}
