<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\User;
use App\Models\WorkforcePlanningMethodology;
use App\Support\Math\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workforce planning (brief §45–57): methodologies (city-level, versioned),
 * manpower plans and their lines, the plan gate, and the roll-up into
 * position requirements. Calculation is delegated to
 * ManpowerCalculationService; implementation of requirements to
 * PositionDesignService.
 */
class WorkforcePlanningService
{
    public const METHODOLOGY_FIELDS = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'calculation_type', 'parameters', 'rounding_rule', 'effective_from', 'effective_to'];

    public const PLAN_FIELDS = ['hierarchy_version_id', 'study_id', 'methodology_id', 'title_en', 'title_am', 'notes', 'planning_period_start', 'planning_period_end'];

    public const LINE_FIELDS = ['organization_unit_id', 'unit_design_proposal_id', 'activity_id', 'task_id', 'occupation_id', 'profession_id', 'methodology_id', 'inputs', 'notes', 'sequence', 'workload_source_type', 'workload_source_reference'];

    private const PREFIX = 'MPP';

    public function __construct(private readonly OrganizationDesignAudit $audit, private readonly ManpowerCalculationService $calculator) {}

    // ── Methodologies ────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createMethodology(User $actor, array $data): WorkforcePlanningMethodology
    {
        $this->assertParameters($data['calculation_type'], $data['parameters'] ?? [], complete: false);
        $methodology = new WorkforcePlanningMethodology([
            ...array_intersect_key($data, array_flip(self::METHODOLOGY_FIELDS)),
            'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => OrganizationDesignStatus::Draft,
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
        ]);
        $methodology->save();
        $this->audit->record(AuditEventType::MethodologyCreated, $actor, $methodology, $methodology->only(self::METHODOLOGY_FIELDS));
        $this->audit->transition($actor, $methodology, null, OrganizationDesignStatus::Draft->value);

        return $methodology;
    }

    /** @param array<string, mixed> $data */
    public function updateMethodology(User $actor, WorkforcePlanningMethodology $methodology, array $data): WorkforcePlanningMethodology
    {
        if (! $methodology->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.methodology_not_draft')]);
        }
        $type = $data['calculation_type'] ?? $methodology->calculation_type;
        if (array_key_exists('parameters', $data)) {
            $this->assertParameters($type, $data['parameters'] ?? [], complete: false);
        }
        $old = $methodology->only(self::METHODOLOGY_FIELDS);
        $methodology->fill([...array_intersect_key($data, array_flip(self::METHODOLOGY_FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::MethodologyUpdated, $actor, $methodology, $methodology->only(self::METHODOLOGY_FIELDS), $old);

        return $methodology;
    }

    /** @param array<string, mixed> $changes */
    public function newMethodologyVersion(User $actor, WorkforcePlanningMethodology $published, array $changes): WorkforcePlanningMethodology
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.methodology_version_from_published_only')]);
        }

        return DB::transaction(function () use ($actor, $published, $changes): WorkforcePlanningMethodology {
            if (WorkforcePlanningMethodology::query()->where('lineage_key', $published->lineage_key)->where('status', OrganizationDesignStatus::Draft)->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.methodology_draft_exists')]);
            }
            $next = (int) WorkforcePlanningMethodology::query()->where('lineage_key', $published->lineage_key)->max('version_no') + 1;
            $data = [...$published->only(self::METHODOLOGY_FIELDS), ...array_intersect_key($changes, array_flip(self::METHODOLOGY_FIELDS))];
            $this->assertParameters($data['calculation_type'], $data['parameters'] ?? [], complete: false);
            $draft = new WorkforcePlanningMethodology([
                ...$data, 'lineage_key' => $published->lineage_key, 'version_no' => $next, 'status' => OrganizationDesignStatus::Draft, 'effective_to' => null,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $draft->save();
            $this->audit->record(AuditEventType::MethodologyCreated, $actor, $draft, ['version_no' => $next, 'supersedes' => $published->getKey()]);
            $this->audit->transition($actor, $draft, null, OrganizationDesignStatus::Draft->value);

            return $draft;
        });
    }

    public function publishMethodology(User $actor, WorkforcePlanningMethodology $methodology): WorkforcePlanningMethodology
    {
        if (! $methodology->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.methodology_not_draft')]);
        }
        if ($methodology->created_by !== null && (int) $methodology->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.methodology_publish_same_person')]);
        }
        // D-6: every parameter the strategy uses must be set before the methodology is in force.
        $errors = $this->calculator->parameterErrors($methodology->calculation_type, $methodology->parameters ?? [], complete: true);
        if ($errors !== []) {
            throw ValidationException::withMessages(['parameters' => array_values($errors), ...$errors]);
        }

        return DB::transaction(function () use ($actor, $methodology): WorkforcePlanningMethodology {
            $inForce = WorkforcePlanningMethodology::query()->where('lineage_key', $methodology->lineage_key)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->first();
            $from = Carbon::parse($methodology->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.methodology_version_must_start_later')]);
            }
            if ($inForce !== null) {
                $inForce->forceFill(['status' => OrganizationDesignStatus::Superseded, 'effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }
            $methodology->forceFill(['status' => OrganizationDesignStatus::Published, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::MethodologyPublished, $actor, $methodology, ['version_no' => $methodology->version_no]);
            $this->audit->transition($actor, $methodology, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value);

            return $methodology;
        });
    }

    // ── Plans ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createPlan(User $actor, string $organizationId, array $data): ManpowerPlan
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): ManpowerPlan {
            $plan = new ManpowerPlan([
                ...array_intersect_key($data, array_flip(self::PLAN_FIELDS)),
                'organization_id' => $organizationId, 'reference_number' => $this->nextReference(), 'status' => OrganizationDesignStatus::Draft,
                'prepared_by' => $actor->getKey(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $plan->save();
            $this->audit->record(AuditEventType::ManpowerPlanCreated, $actor, $plan, $plan->only([...self::PLAN_FIELDS, 'reference_number']));
            $this->audit->transition($actor, $plan, null, OrganizationDesignStatus::Draft->value);

            return $plan;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePlan(User $actor, ManpowerPlan $plan, array $data): ManpowerPlan
    {
        $this->assertEditable($plan);
        $old = $plan->only(self::PLAN_FIELDS);
        $plan->fill([...array_intersect_key($data, array_flip(self::PLAN_FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::ManpowerPlanUpdated, $actor, $plan, $plan->only(self::PLAN_FIELDS), $old);

        return $plan;
    }

    public function submitPlan(User $actor, ManpowerPlan $plan): ManpowerPlan
    {
        $this->assertEditable($plan);
        $this->assertGate($plan);
        $plan->forceFill(['status' => OrganizationDesignStatus::UnderReview, 'submitted_at' => now(), 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $plan, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value);

        return $plan;
    }

    public function returnPlan(User $actor, ManpowerPlan $plan, ?string $reason): ManpowerPlan
    {
        if ($plan->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_not_under_review')]);
        }
        $plan->forceFill(['status' => OrganizationDesignStatus::Draft, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_comment' => $reason, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $plan, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value, $reason);

        return $plan;
    }

    /** Approve and roll the lines up into position requirements (§56). */
    public function approvePlan(User $actor, ManpowerPlan $plan): ManpowerPlan
    {
        if ($plan->status !== OrganizationDesignStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_not_under_review')]);
        }
        if ($plan->prepared_by !== null && (int) $plan->prepared_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_approve_same_person')]);
        }
        $this->assertGate($plan);

        return DB::transaction(function () use ($actor, $plan): ManpowerPlan {
            $plan->forceFill(['status' => OrganizationDesignStatus::Approved, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->rollUp($actor, $plan);
            $this->audit->record(AuditEventType::ManpowerPlanApproved, $actor, $plan, ['requirements' => $plan->requirements()->count()]);
            $this->audit->transition($actor, $plan, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value);

            return $plan;
        });
    }

    public function cancelPlan(User $actor, ManpowerPlan $plan, ?string $reason): ManpowerPlan
    {
        if (! in_array($plan->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_not_cancellable')]);
        }
        $from = $plan->status->value;
        $plan->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
        $this->audit->transition($actor, $plan, $from, OrganizationDesignStatus::Cancelled->value, $reason);

        return $plan;
    }

    // ── Lines ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createLine(User $actor, ManpowerPlan $plan, array $data): ManpowerPlanLine
    {
        $this->assertEditable($plan);
        $line = new ManpowerPlanLine([
            ...array_intersect_key($data, array_flip(self::LINE_FIELDS)),
            'plan_id' => $plan->getKey(), 'organization_id' => $plan->organization_id, 'inputs' => $data['inputs'] ?? [],
        ]);
        $line->save();
        $this->audit->record(AuditEventType::ManpowerLineSaved, $actor, $line, $line->only(self::LINE_FIELDS));

        return $line;
    }

    /**
     * Edit a line. A headcount override needs a reason and is recorded with
     * its author; changing inputs clears the calculation.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLine(User $actor, ManpowerPlanLine $line, array $data): ManpowerPlanLine
    {
        $this->assertEditable($line->plan);
        $old = $line->only([...self::LINE_FIELDS, 'approved_headcount', 'adjustment_reason', 'adjustment_reference']);
        $fields = array_intersect_key($data, array_flip(self::LINE_FIELDS));
        if (array_key_exists('approved_headcount', $data)) {
            if ($data['approved_headcount'] !== null && $data['approved_headcount'] !== $line->recommended_headcount && blank($data['adjustment_reason'] ?? $line->adjustment_reason)) {
                throw ValidationException::withMessages(['adjustment_reason' => __('organization-design.line_adjustment_reason_required')]);
            }
            $fields['approved_headcount'] = $data['approved_headcount'];
            $fields['adjustment_reason'] = $data['adjustment_reason'] ?? $line->adjustment_reason;
            $fields['adjustment_reference'] = $data['adjustment_reference'] ?? $line->adjustment_reference;
            // Who decided and when; the calculated FTE beside it is never touched.
            $changed = $data['approved_headcount'] !== $line->approved_headcount;
            $fields['adjusted_by'] = $data['approved_headcount'] === null ? null : ($changed ? $actor->getKey() : $line->adjusted_by);
            $fields['adjusted_at'] = $data['approved_headcount'] === null ? null : ($changed ? now() : $line->adjusted_at);
        }
        if (array_key_exists('inputs', $data) || array_key_exists('task_id', $data) || array_key_exists('methodology_id', $data)) {
            $fields = [...$fields, 'calculated_fte' => null, 'recommended_headcount' => null, 'calculation_trace' => null, 'calculated_at' => null, 'standard_id' => null, 'methodology_version_no' => null];
        }
        $line->fill($fields)->save();
        $this->audit->record(AuditEventType::ManpowerLineSaved, $actor, $line, $line->only([...self::LINE_FIELDS, 'approved_headcount', 'adjustment_reason', 'adjustment_reference', 'calculated_fte']), $old);

        return $line;
    }

    public function removeLine(User $actor, ManpowerPlanLine $line): void
    {
        $this->assertEditable($line->plan);
        $this->audit->record(AuditEventType::ManpowerLineRemoved, $actor, $line, [], $line->only(self::LINE_FIELDS));
        $line->delete();
    }

    /**
     * Calculate one line with the methodology version in force and, for a
     * task line, the standard in force on the planning period's first day
     * (§51–53). Everything used is recorded on the line.
     */
    public function calculateLine(User $actor, ManpowerPlanLine $line): ManpowerPlanLine
    {
        $plan = $line->plan;
        $this->assertEditable($plan);
        $methodology = ($line->methodology ?? $plan->methodology)->inForce();
        $inputs = $line->inputs ?? [];
        $standardId = null;

        if ($methodology->calculation_type === 'WORKLOAD_STANDARD_TIME' && blank($inputs['standard_time_minutes'] ?? null)) {
            $standard = $this->standardInForce($line, $plan->planning_period_start ?? now());
            if ($standard === null || $standard->planned_time_minutes === null) {
                throw ValidationException::withMessages(['inputs.standard_time_minutes' => __('organization-design.line_standard_missing')]);
            }
            $inputs['standard_time_minutes'] = (string) $standard->planned_time_minutes;
            $standardId = $standard->getKey();
        }

        $result = $this->calculator->calculate($methodology->calculation_type, $methodology->parameters ?? [], $inputs, $methodology->rounding_rule);
        $line->forceFill([
            'methodology_id' => $methodology->getKey(), 'methodology_version_no' => $methodology->version_no, 'standard_id' => $standardId,
            'calculated_fte' => $result['calculated_fte'], 'recommended_headcount' => $result['recommended_headcount'],
            'calculation_trace' => [...$result['trace'], 'methodology' => ['id' => $methodology->getKey(), 'code' => $methodology->code, 'version_no' => $methodology->version_no], 'standard_id' => $standardId],
            'calculated_at' => now(),
        ])->save();
        $this->audit->record(AuditEventType::ManpowerLineCalculated, $actor, $line, ['calculated_fte' => $result['calculated_fte'], 'recommended_headcount' => $result['recommended_headcount'], 'methodology_version_no' => $methodology->version_no]);

        return $line;
    }

    /** @return array{calculated: int, failed: array<string, string>} */
    public function calculateAll(User $actor, ManpowerPlan $plan): array
    {
        $calculated = 0;
        $failed = [];
        foreach ($plan->lines()->get() as $line) {
            try {
                $this->calculateLine($actor, $line);
                $calculated++;
            } catch (ValidationException $e) {
                $failed[$line->getKey()] = collect($e->errors())->flatten()->first() ?? 'error';
            }
        }

        return ['calculated' => $calculated, 'failed' => $failed];
    }

    // ── Gate and roll-up ─────────────────────────────────────────────────

    /**
     * ERROR when a line is not calculated or has no headcount (a manual
     * rounding rule needs the planner's decision); INFO totals.
     *
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    public function validatePlan(ManpowerPlan $plan): array
    {
        $findings = [];
        $lines = $plan->lines()->with(['unit:id,code', 'occupation:id,code,name_en'])->get();
        foreach ($lines as $line) {
            $subject = ['type' => 'line', 'id' => $line->id, 'code' => $line->unit?->code, 'name' => $line->occupation?->name_en];
            if ($line->calculated_at === null) {
                $findings[] = $this->finding('error', 'line_not_calculated', $subject);
            } elseif ($line->headcount() === null) {
                $findings[] = $this->finding('error', 'line_without_headcount', $subject);
            }
            // Brief §19: an approved plan never carries an unexplained workload figure.
            if ($line->workload_source_type === null || $line->workload_source_type === '') {
                $findings[] = $this->finding('error', 'line_without_workload_source', $subject);
            }
            if ($line->approved_headcount !== null && $line->recommended_headcount !== null && $line->approved_headcount !== $line->recommended_headcount) {
                $findings[] = $this->finding('info', 'line_adjusted', $subject, ['from' => $line->recommended_headcount, 'to' => $line->approved_headcount]);
            }
        }
        if ($lines->isEmpty()) {
            $findings[] = $this->finding('error', 'plan_without_lines', ['type' => 'plan', 'id' => $plan->id, 'code' => $plan->reference_number, 'name' => $plan->title_en]);
        }
        $totals = $this->totals($plan);
        $findings[] = $this->finding('info', 'plan_totals', ['type' => 'plan', 'id' => $plan->id, 'code' => $plan->reference_number, 'name' => $plan->title_en], ['fte' => $totals['calculated_fte'], 'headcount' => $totals['headcount']]);

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, fn ($a, $b) => [$order[$a['severity']], $a['code']] <=> [$order[$b['severity']], $b['code']]);
        $counts = collect($findings)->countBy('severity');

        return ['ok' => ($counts['error'] ?? 0) === 0, 'summary' => ['errors' => $counts['error'] ?? 0, 'warnings' => $counts['warning'] ?? 0, 'infos' => $counts['info'] ?? 0], 'findings' => $findings, 'generated_at' => now()->toISOString()];
    }

    /** @return array{calculated_fte: string, headcount: int, lines: int} */
    public function totals(ManpowerPlan $plan): array
    {
        $lines = $plan->lines()->get();

        return [
            'calculated_fte' => Dec::str(Dec::sum($lines->map(fn ($l) => Dec::of($l->calculated_fte) ?? Dec::zero()))),
            'headcount' => (int) $lines->sum(fn ($l) => $l->headcount() ?? 0),
            'lines' => $lines->count(),
        ];
    }

    private function rollUp(User $actor, ManpowerPlan $plan): void
    {
        PositionRequirement::query()->where('plan_id', $plan->getKey())->where('status', 'proposed')->delete();
        $groups = $plan->lines()->with(['occupation', 'unit', 'proposal'])->get()->groupBy(fn (ManpowerPlanLine $l) => ($l->organization_unit_id ?? 'p:'.$l->unit_design_proposal_id).'|'.$l->occupation_id.'|'.($l->profession_id ?? ''));
        $sequence = 0;
        foreach ($groups as $lines) {
            $first = $lines->first();
            $requirement = PositionRequirement::query()->create([
                'plan_id' => $plan->getKey(), 'organization_id' => $plan->organization_id,
                'organization_unit_id' => $first->organization_unit_id, 'unit_design_proposal_id' => $first->unit_design_proposal_id,
                'occupation_id' => $first->occupation_id, 'profession_id' => $first->profession_id,
                'position_title_en' => $first->occupation?->name_en ?? $first->occupation?->code ?? 'Position',
                'position_title_am' => $first->occupation?->name_am,
                'required_fte' => Dec::str(Dec::sum($lines->map(fn ($l) => Dec::of($l->calculated_fte) ?? Dec::zero()))),
                'approved_headcount' => (int) $lines->sum(fn ($l) => $l->headcount() ?? 0),
                'status' => 'approved', 'sequence' => ++$sequence,
            ]);
            ManpowerPlanLine::query()->whereIn('id', $lines->pluck('id'))->update(['position_requirement_id' => $requirement->getKey()]);
            $this->audit->record(AuditEventType::PositionRequirementGenerated, $actor, $requirement, $requirement->only(['organization_unit_id', 'unit_design_proposal_id', 'occupation_id', 'required_fte', 'approved_headcount']));
        }
    }

    private function standardInForce(ManpowerPlanLine $line, $date): ?PositionServiceTaskStandard
    {
        $on = Carbon::parse($date)->max(now())->toDateString();
        $query = PositionServiceTaskStandard::query()->inForceOn($on)->orderByDesc('effective_from')->orderByDesc('version_no');
        if ($line->task_id !== null) {
            return $query->where('master_task_id', $line->task_id)->first();
        }
        if ($line->activity_id !== null) {
            return $query->where('activity_id', $line->activity_id)->first();
        }

        return null;
    }

    private function assertGate(ManpowerPlan $plan): void
    {
        $report = $this->validatePlan($plan);
        if (! $report['ok']) {
            throw ValidationException::withMessages(['validation' => collect($report['findings'])->where('severity', 'error')->pluck('message')->all()]);
        }
    }

    private function assertParameters(string $type, array $parameters, bool $complete): void
    {
        $errors = $this->calculator->parameterErrors($type, $parameters, $complete);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertEditable(ManpowerPlan $plan): void
    {
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.plan_not_draft')]);
        }
    }

    private function nextReference(): string
    {
        $prefix = self::PREFIX.'-'.now()->format('Y').'-';
        $last = ManpowerPlan::query()->withTrashed()->where('reference_number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('reference_number')->value('reference_number');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function finding(string $severity, string $code, array $subject, array $params = []): array
    {
        $label = trim(implode(' ', array_filter([$subject['code'] ?? null, $subject['name'] ?? null])));

        return ['severity' => $severity, 'code' => $code, 'message' => __('organization-design.validation.'.$code, ['subject' => $label !== '' ? $label : '—', ...$params]), 'subject' => $subject, 'params' => $params];
    }
}
