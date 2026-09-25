<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\ResultStatus;
use App\Models\EmployeePerformanceAgreement;
use App\Models\IndividualDevelopmentPlan;
use App\Models\KpiActual;
use App\Models\PerformanceImprovementPlan;
use App\Models\PerformancePlan;
use App\Models\PerformanceResult;
use App\Models\User;

/**
 * Shapes EPMS records for pages. The single place deciding what a viewer may
 * see of confidential HR data (docs/epms-permissions.md §4):
 *
 *   employee   own agreement; manager comments once a review is completed;
 *              never private notes; the result only once RELEASED
 *   manager    team agreements incl. private notes and the working result
 */
final class PerformancePresenter
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EmployeeScoreCalculator $calculator,
    ) {}

    /** @return array<string, mixed> */
    public function agreement(EmployeePerformanceAgreement $agreement, User $viewer): array
    {
        $own = $this->access->isOwn($viewer, $agreement);
        $agreement->loadMissing(['employee', 'organization', 'organizationUnit', 'position', 'manager', 'cycle', 'plan']);

        $result = $agreement->results()->where('is_current', true)->first();
        $resultVisible = $result !== null && (! $own || $result->status === ResultStatus::Released);

        // Live progress (not the official score) for the items table.
        $progress = collect($this->calculator->trace($agreement)['items'])->keyBy('item_id');

        return [
            'id' => $agreement->getKey(),
            'status' => $agreement->status->value,
            'version' => $agreement->agreement_version,
            'is_temporary' => $agreement->is_temporary,
            'effective_from' => $agreement->effective_from?->toDateString(),
            'effective_to' => $agreement->effective_to?->toDateString(),
            'return_reason' => $agreement->return_reason,
            'employee' => ['id' => $agreement->employee_id, 'name' => $agreement->employee?->full_name, 'name_en' => $agreement->employee?->name_en, 'number' => $agreement->employee?->employee_number],
            'organization' => ['id' => $agreement->organization_id, 'name_en' => $agreement->organization?->name_en, 'name_am' => $agreement->organization?->name_am],
            'unit' => $agreement->organizationUnit ? ['name_en' => $agreement->organizationUnit->name_en, 'name_am' => $agreement->organizationUnit->name_am] : null,
            'position' => $agreement->position ? ['title_en' => $agreement->position->title_en ?? $agreement->position->name_en ?? null, 'title_am' => $agreement->position->title_am ?? $agreement->position->name_am ?? null] : null,
            'manager' => $agreement->manager?->name,
            'cycle' => ['id' => $agreement->cycle_id, 'name_en' => $agreement->cycle?->name_en, 'name_am' => $agreement->cycle?->name_am, 'status' => $agreement->cycle?->status->value],
            'plan' => $agreement->plan ? ['id' => $agreement->plan->getKey(), 'title' => $agreement->plan->title, 'version' => $agreement->plan->version_no] : null,
            'items' => $agreement->items()->with(['kpi', 'objective'])->get()->map(fn ($item) => [
                'id' => $item->getKey(),
                'objective' => $item->objective ? ['code' => $item->objective->code, 'title_en' => $item->objective->title_en, 'title_am' => $item->objective->title_am] : null,
                'kpi' => ['id' => $item->kpi_id, 'code' => $item->kpi->code, 'name_en' => $item->kpi->name_en, 'name_am' => $item->kpi->name_am, 'direction' => $item->kpi->direction->value, 'unit' => $item->kpi->unit_of_measure, 'milestones' => $item->kpi->milestones],
                'expected_output' => $item->expected_output,
                'weight' => $item->weight,
                'target_value' => $item->target_value,
                'target_numerator' => $item->target_numerator,
                'target_denominator' => $item->target_denominator,
                'data_source' => $item->data_source_type->value,
                'is_mandatory' => $item->is_mandatory,
                'is_additional' => $item->is_additional,
                'source' => $item->position_target_id !== null ? 'POSITION_PLAN' : 'ADDITIONAL',
                'progress' => $progress->get($item->getKey()),
                'actuals' => KpiActual::query()->where('employee_performance_item_id', $item->getKey())->orderByDesc('period_end')->limit(24)->get()
                    ->map(fn (KpiActual $a) => [
                        'id' => $a->getKey(), 'period_start' => $a->period_start->toDateString(), 'period_end' => $a->period_end->toDateString(),
                        'value' => $a->actual_value, 'numerator' => $a->actual_numerator, 'denominator' => $a->actual_denominator,
                        'milestone_key' => $a->milestone_key, 'source' => $a->source_type->value, 'verified' => $a->verified, 'comment' => $a->comment,
                    ])->all(),
            ])->all(),
            'evidence' => $agreement->evidence()->latest()->get()->map(fn ($e) => [
                'id' => $e->getKey(), 'item_id' => $e->employee_performance_item_id, 'type' => $e->evidence_type->value, 'title' => $e->title,
                'description' => $e->description, 'has_file' => $e->file_path !== null, 'original_name' => $e->original_name,
                'verified' => $e->verified, 'submitted_at' => $e->submitted_at?->toIso8601String(),
            ])->all(),
            'checkins' => $agreement->checkins()->get()->map(fn ($c) => [
                'id' => $c->getKey(), 'date' => $c->checkin_date->toDateString(), 'progress_status' => $c->progress_status->value,
                'employee_summary' => $c->employee_summary, 'manager_comment' => $c->manager_comment,
                'manager_private_note' => $own ? null : $c->manager_private_note,
                'blockers' => $c->blockers, 'support_required' => $c->support_required, 'learning_needs' => $c->learning_needs, 'next_actions' => $c->next_actions,
            ])->all(),
            'reviews' => $agreement->reviews()->get()->mapWithKeys(fn ($r) => [$r->review_type->value => [
                'status' => $r->status->value,
                'employee_self_assessment' => $r->employee_self_assessment, 'achievements' => $r->achievements, 'challenges' => $r->challenges,
                'contributions' => $r->contributions, 'development_needs' => $r->development_needs,
                'manager_comment' => ! $own || $r->status->value === 'COMPLETED' ? $r->manager_comment : null,
                'manager_private_note' => $own ? null : $r->manager_private_note,
                'improvement_actions' => ! $own || $r->status->value === 'COMPLETED' ? $r->improvement_actions : null,
                'at_risk_item_ids' => $r->at_risk_item_ids ?? [],
                'return_reason' => $r->return_reason,
            ]])->all(),
            'competencies' => $agreement->competencyAssessments()->with('competency')->get()->map(fn ($a) => [
                'competency_id' => $a->competency_id, 'code' => $a->competency?->code, 'name_en' => $a->competency?->name_en, 'name_am' => $a->competency?->name_am,
                'weight' => $a->weight, 'self_rating' => $a->self_rating,
                'manager_rating' => $own && ! $resultVisible ? null : $a->manager_rating,
            ])->all(),
            'result' => $resultVisible ? $this->result($result, $own) : null,
            'result_hidden' => $result !== null && ! $resultVisible,
            // PIPs are confidential HR records: managers/HR only.
            'improvement_plans' => $own ? [] : PerformanceImprovementPlan::query()->where('agreement_id', $agreement->getKey())->get(['id', 'identified_gap', 'required_improvement', 'support_action', 'start_date', 'end_date', 'status'])->toArray(),
            'development_plans' => IndividualDevelopmentPlan::query()->where('agreement_id', $agreement->getKey())->get(['id', 'development_objective', 'training', 'coaching', 'expected_outcome', 'due_date', 'status'])->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function result(PerformanceResult $result, bool $forEmployee): array
    {
        return [
            'id' => $result->getKey(),
            'status' => $result->status->value,
            'revision' => $result->revision_no,
            'results_score' => $result->results_score,
            'competency_score' => $result->competency_score,
            'results_weight' => $result->results_weight,
            'competency_weight' => $result->competency_weight,
            'calculated_score' => $result->calculated_score,
            'adjusted_score' => $forEmployee ? null : $result->adjusted_score,
            'calibrated_score' => $result->calibrated_score,
            'final_score' => $result->final_score,
            'rating_en' => $result->rating_label_en,
            'rating_am' => $result->rating_label_am,
            'released_at' => $result->released_at?->toIso8601String(),
            // "How this score was calculated" — the frozen trace.
            'trace' => $result->snapshot_json,
            'adjustments' => $forEmployee ? [] : $result->adjustments()->latest()->get(['id', 'adjustment_type', 'original_score', 'adjusted_score', 'reason', 'status', 'created_at'])->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function planSummary(PerformancePlan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'title' => $plan->title,
            'type' => $plan->plan_type->value,
            'status' => $plan->status->value,
            'version' => $plan->version_no,
            'organization' => ['name_en' => $plan->organization?->name_en, 'name_am' => $plan->organization?->name_am],
            'unit' => $plan->organizationUnit ? ['name_en' => $plan->organizationUnit->name_en, 'name_am' => $plan->organizationUnit->name_am] : null,
            'position' => $plan->position ? ['title_en' => $plan->position->title_en ?? null, 'title_am' => $plan->position->title_am ?? null] : null,
            'cycle' => ['name_en' => $plan->cycle?->name_en, 'name_am' => $plan->cycle?->name_am],
            'published_at' => $plan->published_at?->toIso8601String(),
        ];
    }
}
