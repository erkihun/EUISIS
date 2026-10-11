<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Actions\Positions\CreatePositionAction;
use App\Actions\Vacancy\StorePositionEstablishmentAction;
use App\Enums\AuditEventType;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationDesignStatus;
use App\Models\ManpowerPlan;
use App\Models\OrganizationUnit;
use App\Models\PositionRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns an approved plan's requirements into positions and approved
 * establishments (brief §57–58, 100). Never an assignment: people are
 * placed through the existing assignment flows. Idempotent; a requirement
 * on a proposed unit waits until the change-request pipeline has created
 * the unit.
 */
class PositionDesignService
{
    public function __construct(
        private readonly CreatePositionAction $positions,
        private readonly StorePositionEstablishmentAction $establishments,
        private readonly OrganizationDesignAudit $audit,
    ) {}

    /** @return array{implemented: int, waiting: int} */
    public function implement(User $actor, ManpowerPlan $plan): array
    {
        if ($plan->status !== OrganizationDesignStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_not_approved')]);
        }
        $implemented = 0;
        $waiting = 0;
        $requirements = PositionRequirement::query()->where('plan_id', $plan->getKey())->where('status', 'approved')->with(['proposal', 'occupation'])->orderBy('sequence')->get();

        foreach ($requirements as $requirement) {
            $unitId = $requirement->organization_unit_id ?? $requirement->proposal?->resulting_unit_id;
            if ($unitId === null || ! OrganizationUnit::query()->whereKey($unitId)->where('organization_id', $plan->organization_id)->exists()) {
                $waiting++;

                continue;
            }
            if (($requirement->approved_headcount ?? 0) < 1) {
                $waiting++;

                continue;
            }
            DB::transaction(function () use ($actor, $plan, $requirement, $unitId, &$implemented): void {
                $position = $this->positions->execute([
                    'organization_id' => $plan->organization_id,
                    'organization_unit_id' => $unitId,
                    'occupation_id' => $requirement->occupation_id,
                    'job_position_code' => $plan->reference_number.'-'.str_pad((string) $requirement->sequence, 2, '0', STR_PAD_LEFT),
                    'title_en' => $requirement->position_title_en,
                    'title_am' => $requirement->position_title_am,
                    'grade_level' => $requirement->grade_level,
                    'is_active' => true,
                    'effective_from' => $plan->planning_period_start?->toDateString(),
                ], $actor);
                $establishment = $this->establishments->execute([
                    'organization_id' => $plan->organization_id,
                    'organization_unit_id' => $unitId,
                    'position_id' => $position->getKey(),
                    'occupation_id' => $requirement->occupation_id,
                    'approved_slots' => $requirement->approved_headcount,
                    'effective_from' => $plan->planning_period_start?->toDateString() ?? now()->toDateString(),
                    'effective_to' => $plan->planning_period_end?->toDateString(),
                    'approval_reference' => $plan->reference_number,
                    'notes' => __('organization-design.establishment_note', ['reference' => $plan->reference_number]),
                ], $actor);
                $establishment->forceFill(['status' => EstablishmentStatus::Approved->value, 'approved_by' => $plan->approved_by, 'approved_at' => $plan->approved_at ?? now()])->save();
                $requirement->forceFill(['organization_unit_id' => $unitId, 'status' => 'implemented', 'position_id' => $position->getKey(), 'establishment_id' => $establishment->getKey(), 'implemented_at' => now()])->save();
                $this->audit->record(AuditEventType::PositionRequirementImplemented, $actor, $requirement, ['position_id' => $position->getKey(), 'establishment_id' => $establishment->getKey(), 'approved_slots' => $requirement->approved_headcount]);
                $implemented++;
            });
        }
        if ($implemented > 0 && PositionRequirement::query()->where('plan_id', $plan->getKey())->where('status', 'approved')->doesntExist()) {
            $plan->forceFill(['implemented_at' => now()])->save();
        }

        return ['implemented' => $implemented, 'waiting' => $waiting];
    }
}
