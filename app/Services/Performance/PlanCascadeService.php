<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\CascadeMode;
use App\Enums\Performance\CascadeType;
use App\Enums\Performance\ObjectiveType;
use App\Enums\Performance\PlanStatus;
use App\Models\PerformanceCascade;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Objective cascading (docs/epms-cascade-rules.md §2).
 *
 * A parent objective flows only into a DIRECT child plan (the plan whose
 * parent_plan_id is the objective's plan), so cascades follow the real
 * structure and can never loop. The child is not forced to copy:
 *
 *   ACCEPT      same wording, optionally its KPI targets (linked as
 *               contribution: child target.parent_target_id = parent target)
 *   CUSTOMIZE   own wording, still linked
 *   SPLIT       several child objectives, each linked
 *   CONTRIBUTE  a child objective that contributes a share
 *
 * A mandatory parent objective cannot be declined; an optional one can, with
 * a reason (recorded as a REJECTED child objective, so the decision is visible).
 */
final class PlanCascadeService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $options  title_en, title_am, description_en, code, weight,
     *                                         contribution_weight, copy_targets (bool), parts (SPLIT: list of {code,title_en,title_am,weight})
     * @return list<PerformanceObjective>
     */
    public function cascade(PerformanceObjective $parentObjective, PerformancePlan $childPlan, CascadeMode $mode, array $options, User $actor): array
    {
        $this->assertCascadable($parentObjective, $childPlan, $actor);

        if ($mode === CascadeMode::LocalOnly) {
            throw ValidationException::withMessages(['mode' => __('performance.errors.local_is_not_cascade')]);
        }

        if ($mode !== CascadeMode::Split && $childPlan->objectives()->where('parent_objective_id', $parentObjective->getKey())->exists()) {
            throw ValidationException::withMessages(['objective' => __('performance.errors.already_cascaded')]);
        }

        return DB::transaction(function () use ($parentObjective, $childPlan, $mode, $options, $actor): array {
            $parts = $mode === CascadeMode::Split
                ? array_values((array) ($options['parts'] ?? []))
                : [$options];

            if ($parts === []) {
                throw ValidationException::withMessages(['parts' => __('performance.errors.split_needs_parts')]);
            }

            $created = [];
            foreach ($parts as $index => $part) {
                $customWording = in_array($mode, [CascadeMode::Customize, CascadeMode::Split, CascadeMode::Contribute], true);
                if ($customWording && empty($part['title_en'])) {
                    throw ValidationException::withMessages(['title_en' => __('performance.errors.title_required')]);
                }

                $code = $part['code'] ?? $this->nextCode($childPlan, $parentObjective->code, $index);
                if ($childPlan->objectives()->where('code', $code)->exists()) {
                    throw ValidationException::withMessages(['code' => __('performance.errors.code_taken')]);
                }

                $child = $childPlan->objectives()->create([
                    'parent_objective_id' => $parentObjective->getKey(),
                    'code' => $code,
                    'title_en' => $customWording ? $part['title_en'] : $parentObjective->title_en,
                    'title_am' => $customWording ? ($part['title_am'] ?? null) : $parentObjective->title_am,
                    'description_en' => $customWording ? ($part['description_en'] ?? null) : $parentObjective->description_en,
                    'description_am' => $customWording ? ($part['description_am'] ?? null) : $parentObjective->description_am,
                    'objective_type' => ObjectiveType::Inherited->value,
                    'cascade_mode' => $mode->value,
                    'is_mandatory' => $parentObjective->is_mandatory,
                    'weight' => $part['weight'] ?? $parentObjective->weight,
                    'priority' => $parentObjective->priority,
                    'sort_order' => (int) $childPlan->objectives()->max('sort_order') + 1,
                ]);

                PerformanceCascade::query()->create([
                    'cycle_id' => $childPlan->cycle_id,
                    'parent_plan_id' => $parentObjective->performance_plan_id,
                    'child_plan_id' => $childPlan->getKey(),
                    'parent_objective_id' => $parentObjective->getKey(),
                    'child_objective_id' => $child->getKey(),
                    'source_level' => $parentObjective->plan->plan_type->value,
                    'target_level' => $childPlan->plan_type->value,
                    'organization_id' => $childPlan->organization_id,
                    'organization_unit_id' => $childPlan->organization_unit_id,
                    'position_id' => $childPlan->position_id,
                    'cascade_type' => match ($mode) {
                        CascadeMode::Accept => CascadeType::Inherited,
                        CascadeMode::Customize => CascadeType::Customized,
                        CascadeMode::Split => CascadeType::Split,
                        default => CascadeType::Contribution,
                    },
                    'contribution_weight' => $part['contribution_weight'] ?? null,
                    'created_by' => $actor->getKey(),
                ]);

                if (($options['copy_targets'] ?? true) && in_array($mode, [CascadeMode::Accept, CascadeMode::Customize], true)) {
                    $this->copyTargets($parentObjective, $child, $childPlan);
                }

                $created[] = $child;
            }

            $this->audit->record(AuditEventType::PerformanceCascaded, $actor, $childPlan, [
                'parent_objective' => $parentObjective->code,
                'mode' => $mode->value,
                'child_objectives' => array_map(fn (PerformanceObjective $o): string => $o->code, $created),
            ]);

            return $created;
        });
    }

    /** Decline an optional parent objective, with a recorded reason. */
    public function decline(PerformanceObjective $parentObjective, PerformancePlan $childPlan, string $reason, User $actor): PerformanceObjective
    {
        $this->assertCascadable($parentObjective, $childPlan, $actor);

        if ($parentObjective->is_mandatory) {
            throw ValidationException::withMessages(['objective' => __('performance.errors.mandatory_cannot_be_declined')]);
        }
        if ($childPlan->objectives()->where('parent_objective_id', $parentObjective->getKey())->exists()) {
            throw ValidationException::withMessages(['objective' => __('performance.errors.already_cascaded')]);
        }

        $declined = $childPlan->objectives()->create([
            'parent_objective_id' => $parentObjective->getKey(),
            'code' => $this->nextCode($childPlan, $parentObjective->code, 0),
            'title_en' => $parentObjective->title_en,
            'title_am' => $parentObjective->title_am,
            'objective_type' => ObjectiveType::Inherited->value,
            'cascade_mode' => CascadeMode::Accept->value,
            'weight' => 0,
            'rejection_reason' => $reason,
        ]);
        $declined->forceFill(['status' => 'REJECTED'])->save();

        $this->audit->record(AuditEventType::PerformanceCascaded, $actor, $childPlan, ['declined_objective' => $parentObjective->code], null, $reason);

        return $declined;
    }

    private function assertCascadable(PerformanceObjective $parentObjective, PerformancePlan $childPlan, User $actor): void
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_objectives.manage', $childPlan->organization_id));

        $parentPlan = $parentObjective->plan;
        if ($parentPlan->getKey() === $childPlan->getKey() || $this->isAncestor($childPlan, $parentPlan)) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.circular_cascade')]);
        }
        if ($childPlan->parent_plan_id !== $parentPlan->getKey()) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.not_direct_child')]);
        }
        if ($parentPlan->status !== PlanStatus::Published || $parentObjective->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['plan' => __('performance.errors.parent_not_published')]);
        }
        if (! PerformancePlanService::isEditable($childPlan)) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.plan_locked')]);
        }
    }

    /** True when $candidate is $plan or one of $plan's ancestors (walks parent_plan_id). */
    private function isAncestor(PerformancePlan $candidate, PerformancePlan $plan): bool
    {
        $seen = [];
        $current = $plan->parentPlan;
        while ($current !== null && ! in_array($current->getKey(), $seen, true)) {
            if ($current->getKey() === $candidate->getKey()) {
                return true;
            }
            $seen[] = $current->getKey();
            $current = $current->parentPlan;
        }

        return false;
    }

    private function copyTargets(PerformanceObjective $parent, PerformanceObjective $child, PerformancePlan $childPlan): void
    {
        foreach ($parent->targets()->get() as $target) {
            $copy = $target->replicate(['amended_from_id', 'version_no', 'is_current', 'amendment_reason']);
            $copy->forceFill([
                'performance_plan_id' => $childPlan->getKey(),
                'objective_id' => $child->getKey(),
                // Contribution lineage: this target's actuals roll up into the parent's.
                'parent_target_id' => $target->getKey(),
                'version_no' => 1,
                'is_current' => true,
            ])->save();
        }
    }

    private function nextCode(PerformancePlan $plan, string $base, int $index): string
    {
        $suffix = $index + 1;
        do {
            $code = "{$base}.{$suffix}";
            $suffix++;
        } while ($plan->objectives()->where('code', $code)->exists());

        return $code;
    }
}
