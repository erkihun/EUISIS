<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Enums\OrganizationalChangeEntityType;
use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestItem;
use App\Models\OrganizationUnit;
use App\Models\Position;

/**
 * Re-checks master data immediately before an approved change is applied.
 *
 * A request can be approved today and implemented next month, by which time
 * the target may have been deleted, re-parented, or filled. Every conflict
 * found here blocks the whole request: partial application is never allowed.
 *
 * Each conflict is a machine-readable code plus the values that produced it,
 * so the UI can explain precisely what changed since approval.
 */
final readonly class ChangeRequestConflictDetector
{
    public function __construct(
        private ChangeRequestPayloadValidator $validator,
    ) {}

    /**
     * @return array<int, array{code: string, context: array<string, mixed>}>
     */
    public function detect(OrganizationalChangeRequest $request): array
    {
        $conflicts = [];

        foreach ($request->items as $item) {
            $conflicts = [...$conflicts, ...$this->detectForItem($request, $item)];
        }

        return $conflicts;
    }

    /**
     * @return array<int, array{code: string, context: array<string, mixed>}>
     */
    private function detectForItem(OrganizationalChangeRequest $request, OrganizationalChangeRequestItem $item): array
    {
        return match ($item->entity_type) {
            OrganizationalChangeEntityType::OrganizationUnit => $this->unitConflicts($request, $item),
            OrganizationalChangeEntityType::Position => $this->positionConflicts($request, $item),
            OrganizationalChangeEntityType::Narrative => [],
        };
    }

    /** @return array<int, array{code: string, context: array<string, mixed>}> */
    private function unitConflicts(OrganizationalChangeRequest $request, OrganizationalChangeRequestItem $item): array
    {
        $conflicts = [];
        $proposed = $item->proposed_data ?? [];
        $before = $item->before_data ?? [];

        // Proposed parent must still exist and still be usable.
        $parentId = $proposed['parent_unit_id'] ?? null;
        if ($parentId !== null) {
            $parent = OrganizationUnit::query()->find($parentId);

            if ($parent === null) {
                $conflicts[] = ['code' => 'parent_unit_missing', 'context' => ['parent_unit_id' => $parentId]];
            } elseif ($this->unitStatus($parent) !== 'active') {
                $conflicts[] = ['code' => 'parent_unit_inactive', 'context' => [
                    'parent_unit_id' => $parentId,
                    'status' => $this->unitStatus($parent),
                ]];
            }
        }

        if ($item->entity_id === null) {
            // Creating a unit: a sibling with the same name would now be a duplicate.
            $name = $proposed['name_en'] ?? null;
            if ($name !== null) {
                $duplicate = OrganizationUnit::query()
                    ->where('organization_id', $request->organization_id)
                    ->where('parent_unit_id', $parentId)
                    ->where('name_en', $name)
                    ->exists();

                if ($duplicate) {
                    $conflicts[] = ['code' => 'duplicate_unit_name', 'context' => ['name_en' => $name]];
                }
            }

            return $conflicts;
        }

        $unit = OrganizationUnit::withTrashed()->find($item->entity_id);

        if ($unit === null) {
            return [...$conflicts, ['code' => 'target_unit_missing', 'context' => ['entity_id' => $item->entity_id]]];
        }

        if ($unit->trashed()) {
            $conflicts[] = ['code' => 'target_unit_deleted', 'context' => ['entity_id' => $item->entity_id]];

            return $conflicts;
        }

        if ($this->unitStatus($unit) === 'archived') {
            $conflicts[] = ['code' => 'target_unit_archived', 'context' => ['entity_id' => $item->entity_id]];
        }

        // The unit moved since the snapshot was taken.
        if (array_key_exists('parent_unit_id', $before)
            && (string) ($before['parent_unit_id'] ?? '') !== (string) ($unit->parent_unit_id ?? '')) {
            $conflicts[] = ['code' => 'target_unit_reparented', 'context' => [
                'expected_parent_unit_id' => $before['parent_unit_id'] ?? null,
                'actual_parent_unit_id' => $unit->parent_unit_id,
            ]];
        }

        // A move approved earlier could now close a cycle.
        if ($parentId !== null && $request->request_type->affectsHierarchyVersion()) {
            $parent = OrganizationUnit::query()->find($parentId);

            if ($parent !== null && $this->validator->wouldCreateCycle($unit, $parent)) {
                $conflicts[] = ['code' => 'hierarchy_cycle_now_present', 'context' => [
                    'entity_id' => $item->entity_id,
                    'parent_unit_id' => $parentId,
                ]];
            }
        }

        return $conflicts;
    }

    /** @return array<int, array{code: string, context: array<string, mixed>}> */
    private function positionConflicts(OrganizationalChangeRequest $request, OrganizationalChangeRequestItem $item): array
    {
        $conflicts = [];
        $proposed = $item->proposed_data ?? [];
        $before = $item->before_data ?? [];

        $targetUnitId = $proposed['organization_unit_id'] ?? null;
        if ($targetUnitId !== null) {
            $targetUnit = OrganizationUnit::query()->find($targetUnitId);

            if ($targetUnit === null) {
                $conflicts[] = ['code' => 'target_unit_missing', 'context' => ['organization_unit_id' => $targetUnitId]];
            } elseif ($targetUnit->organization_id !== $request->organization_id) {
                $conflicts[] = ['code' => 'target_unit_other_organization', 'context' => [
                    'organization_unit_id' => $targetUnitId,
                ]];
            } elseif ($this->unitStatus($targetUnit) !== 'active') {
                $conflicts[] = ['code' => 'target_unit_inactive', 'context' => [
                    'organization_unit_id' => $targetUnitId,
                    'status' => $this->unitStatus($targetUnit),
                ]];
            }
        }

        if ($item->entity_id === null) {
            return $conflicts;
        }

        $position = Position::withTrashed()->find($item->entity_id);

        if ($position === null) {
            return [...$conflicts, ['code' => 'target_position_missing', 'context' => ['entity_id' => $item->entity_id]]];
        }

        if ($position->trashed()) {
            return [...$conflicts, ['code' => 'target_position_deleted', 'context' => ['entity_id' => $item->entity_id]]];
        }

        // The position moved units since approval.
        if (array_key_exists('organization_unit_id', $before)
            && (string) ($before['organization_unit_id'] ?? '') !== (string) ($position->organization_unit_id ?? '')) {
            $conflicts[] = ['code' => 'target_position_moved', 'context' => [
                'expected_organization_unit_id' => $before['organization_unit_id'] ?? null,
                'actual_organization_unit_id' => $position->organization_unit_id,
            ]];
        }

        /*
         * Someone filled the position between approval and implementation.
         * Only removal blocks: abolishing or deactivating an occupied position
         * would strand its holder. A move of an occupied position is legal and
         * is surfaced through impact analysis instead.
         */
        $occupied = $this->validator->activeAssignmentCount($position);
        $blocksOnOccupancy = $request->request_type === OrganizationalChangeRequestType::AbolishPosition
            || ($request->request_type === OrganizationalChangeRequestType::ChangePositionStatus
                && ($proposed['is_active'] ?? true) === false);

        if ($blocksOnOccupancy && $occupied > 0) {
            $conflicts[] = ['code' => 'position_now_occupied', 'context' => [
                'entity_id' => $item->entity_id,
                'occupied' => $occupied,
            ]];
        }

        return $conflicts;
    }

    private function unitStatus(OrganizationUnit $unit): string
    {
        return $unit->status instanceof \BackedEnum
            ? $unit->status->value
            : (string) ($unit->status ?? '');
    }
}
