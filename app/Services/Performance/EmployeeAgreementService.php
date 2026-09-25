<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\CycleStatus;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\Competency;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\Kpi;
use App\Models\KpiTarget;
use App\Models\PerformanceCycle;
use App\Models\PerformancePlan;
use App\Models\PositionCompetency;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\Performance\Calculation\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee performance agreements (docs/epms-workflow.md §3).
 *
 * Position plan → agreement: each position target becomes an item weighted
 * objective weight × target weight ÷ 100, so the items total 100. The
 * manager may adapt targets/weights and add approved extra objectives while
 * the agreement is a draft; lineage stays in position_target_id.
 *
 * Draft → employee review (acknowledge/return) → manager approval → Agreed
 * (Active once the cycle is active). The organization/unit/position are a
 * snapshot, so a transfer closes this agreement and starts a new one.
 */
final class EmployeeAgreementService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
        private readonly DailyActivityReviewerResolver $reviewers,
        private readonly PerformanceNotifier $notifier,
    ) {}

    public function canManageAssignment(User $user, EmployeeAssignment $assignment): bool
    {
        $own = $this->access->employeeOf($user)?->getKey() === $assignment->employee_id;
        if ($own || ! $user->can('employee_performance_agreements.manage')) {
            return false;
        }

        return $this->reviewers->coverage($user)->matchesAssignment($assignment)
            || $this->access->inScope($user, 'employee_performance_agreements.manage', $assignment->organization_id)
                && $user->can('performance_plans.approve');
    }

    public function create(Employee $employee, EmployeeAssignment $assignment, PerformanceCycle $cycle, User $actor, ?User $manager = null, bool $temporary = false): EmployeePerformanceAgreement
    {
        $this->access->authorize($assignment->employee_id === $employee->getKey() && $this->canManageAssignment($actor, $assignment));

        if (PerformanceCycleService::isReadOnly($cycle) || in_array($cycle->status, [CycleStatus::Finalized, CycleStatus::Draft], true)) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_not_open')]);
        }
        if ($employee->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages(['employee_id' => __('performance.errors.employee_inactive')]);
        }
        if ($cycle->organization_id !== null && $cycle->organization_id !== $assignment->organization_id) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_other_organization')]);
        }

        // Never evaluate a period the employee was not in this assignment.
        $from = Carbon::parse($cycle->start_date)->max($assignment->effective_from ?? $cycle->start_date);
        $to = Carbon::parse($cycle->end_date)->min($assignment->effective_to ?? $cycle->end_date);
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['employee_assignment_id' => __('performance.errors.assignment_outside_cycle')]);
        }

        $plan = PerformancePlan::query()
            ->where('cycle_id', $cycle->getKey())->where('plan_type', PlanType::Position->value)
            ->where('position_id', $assignment->position_id)->where('status', PlanStatus::Published->value)
            ->first();

        return DB::transaction(function () use ($employee, $assignment, $cycle, $actor, $manager, $temporary, $from, $to, $plan): EmployeePerformanceAgreement {
            $activeKey = "{$employee->getKey()}:{$assignment->getKey()}:{$cycle->getKey()}";
            if (EmployeePerformanceAgreement::query()->where('active_key', $activeKey)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['employee_id' => __('performance.errors.agreement_exists')]);
            }

            $version = (int) EmployeePerformanceAgreement::query()
                ->where('employee_id', $employee->getKey())->where('employee_assignment_id', $assignment->getKey())
                ->where('cycle_id', $cycle->getKey())->max('agreement_version') + 1;

            $agreement = new EmployeePerformanceAgreement([
                'cycle_id' => $cycle->getKey(),
                'employee_id' => $employee->getKey(),
                'employee_assignment_id' => $assignment->getKey(),
                'performance_plan_id' => $plan?->getKey(),
                'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id,
                'manager_user_id' => ($manager ?? $actor)->getKey(),
                'is_temporary' => $temporary,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to->toDateString(),
            ]);
            $agreement->forceFill([
                'status' => AgreementStatus::Draft,
                'agreement_version' => $version,
                'active_key' => $activeKey,
                'created_by' => $actor->getKey(),
            ])->save();

            if ($plan !== null) {
                $this->itemsFromPlan($agreement, $plan);
            }
            $this->competenciesFor($agreement);

            $this->audit->record(AuditEventType::PerformanceAgreementStatusChanged, $actor, $agreement, ['status' => AgreementStatus::Draft->value, 'plan' => $plan?->getKey(), 'version' => $version]);

            return $agreement;
        });
    }

    private function itemsFromPlan(EmployeePerformanceAgreement $agreement, PerformancePlan $plan): void
    {
        $sort = 0;
        foreach ($plan->objectives()->where('status', 'ACTIVE')->with(['targets.kpi'])->get() as $objective) {
            foreach ($objective->targets as $target) {
                $weight = Dec::of($objective->weight)->multipliedBy(Dec::of($target->weight))->dividedBy(100, 4, RoundingMode::HALF_UP);
                $agreement->allItems()->create([
                    'objective_id' => $objective->getKey(),
                    'kpi_id' => $target->kpi_id,
                    'position_target_id' => $target->getKey(),
                    'expected_output' => $objective->title_en,
                    'weight' => (string) $weight,
                    'baseline_value' => $target->baseline_value,
                    'target_value' => $target->target_value,
                    'target_numerator' => $target->target_numerator,
                    'target_denominator' => $target->target_denominator,
                    'achievement_cap' => $target->achievement_cap,
                    'tolerance' => $target->tolerance,
                    'zero_score_deviation' => $target->zero_score_deviation,
                    'period_type' => $target->period_type?->value,
                    'data_source_type' => $target->kpi->data_source_type->value,
                    'is_mandatory' => (bool) $objective->is_mandatory,
                    'sort_order' => $sort++,
                ]);
            }
        }
    }

    /** Position competencies, or every active competency equally weighted. */
    private function competenciesFor(EmployeePerformanceAgreement $agreement): void
    {
        if ($this->settings->componentWeights()['competency'] === 0) {
            return;
        }

        $rows = $agreement->position_id === null ? collect() : PositionCompetency::query()->where('position_id', $agreement->position_id)->get();
        if ($rows->isNotEmpty()) {
            foreach ($rows as $row) {
                $agreement->competencyAssessments()->create(['competency_id' => $row->competency_id, 'weight' => $row->weight]);
            }

            return;
        }

        $competencies = Competency::query()->where('is_active', true)
            ->whereHas('framework', fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->get();
        $count = $competencies->count();
        foreach ($competencies->values() as $index => $competency) {
            // Equal shares that total exactly 100: the last one takes the remainder.
            $share = BigDecimal::of(100)->dividedBy($count, 4, RoundingMode::DOWN);
            $weight = $index === $count - 1 ? BigDecimal::of(100)->minus($share->multipliedBy($count - 1)) : $share;
            $agreement->competencyAssessments()->create(['competency_id' => $competency->getKey(), 'weight' => (string) $weight]);
        }
    }

    // ── Items (draft only) ───────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function addItem(EmployeePerformanceAgreement $agreement, array $data, User $actor): EmployeePerformanceItem
    {
        $this->assertEditable($agreement, $actor);

        /** @var Kpi $kpi */
        $kpi = Kpi::query()->where('is_active', true)->findOrFail($data['kpi_id']);
        if ($kpi->organization_id !== null && $kpi->organization_id !== $agreement->organization_id) {
            throw ValidationException::withMessages(['kpi_id' => __('performance.errors.kpi_other_organization')]);
        }

        if (! empty($data['position_target_id'])) {
            $target = KpiTarget::query()->with('plan')->find($data['position_target_id']);
            $allowedPlans = array_filter([$agreement->performance_plan_id, $agreement->plan?->parent_plan_id]);
            if ($target === null || ! in_array($target->performance_plan_id, $allowedPlans, true) || $target->kpi_id !== $kpi->getKey()) {
                throw ValidationException::withMessages(['position_target_id' => __('performance.errors.parent_target_invalid')]);
            }
        }

        $item = $agreement->allItems()->create([
            ...$data,
            'data_source_type' => $data['data_source_type'] ?? $kpi->data_source_type->value,
            'is_additional' => empty($data['position_target_id']),
            'sort_order' => (int) $agreement->allItems()->max('sort_order') + 1,
        ]);

        $this->audit->record(AuditEventType::PerformanceAgreementStatusChanged, $actor, $agreement, ['added_item' => $kpi->code, 'weight' => $item->weight]);

        return $item;
    }

    /** @param array<string, mixed> $data */
    public function updateItem(EmployeePerformanceItem $item, array $data, User $actor): EmployeePerformanceItem
    {
        $this->assertEditable($item->agreement, $actor);
        // The KPI and its plan lineage are fixed once the item exists.
        unset($data['agreement_id'], $data['kpi_id'], $data['position_target_id'], $data['objective_id'], $data['is_current'], $data['supersedes_item_id']);
        // A cleared data source keeps the current one (every item measures from some source).
        if (array_key_exists('data_source_type', $data) && $data['data_source_type'] === null) {
            unset($data['data_source_type']);
        }
        $old = $item->only(array_keys($data));
        $item->fill($data)->save();
        $this->audit->record(AuditEventType::PerformanceAgreementStatusChanged, $actor, $item->agreement, ['updated_item' => $item->getKey(), ...$data], $old);

        return $item;
    }

    public function removeItem(EmployeePerformanceItem $item, User $actor): void
    {
        $this->assertEditable($item->agreement, $actor);
        if ($item->is_mandatory) {
            throw ValidationException::withMessages(['item' => __('performance.errors.mandatory_cannot_be_removed')]);
        }
        $this->audit->record(AuditEventType::PerformanceAgreementStatusChanged, $actor, $item->agreement, ['removed_item' => $item->getKey()]);
        $item->delete();
    }

    /** @return list<string> */
    public function validate(EmployeePerformanceAgreement $agreement): array
    {
        $items = $agreement->items()->with('kpi')->get();
        $errors = [];
        if ($items->isEmpty()) {
            $errors[] = __('performance.validation.no_items');
        }
        $total = Dec::sum($items->map(fn ($i) => Dec::of($i->weight)));
        if ($items->isNotEmpty() && ! $total->isEqualTo(100)) {
            $errors[] = __('performance.validation.item_weights', ['total' => Dec::str($total, 2)]);
        }
        foreach ($items as $item) {
            $needsTarget = ! in_array($item->kpi->direction->value, ['BINARY', 'MILESTONE'], true);
            if ($needsTarget && $item->target_value === null && $item->target_numerator === null) {
                $errors[] = __('performance.validation.item_target_missing', ['kpi' => $item->kpi->code]);
            }
        }

        return $errors;
    }

    // ── Workflow ─────────────────────────────────────────────────────────

    public function submitToEmployee(EmployeePerformanceAgreement $agreement, User $actor): EmployeePerformanceAgreement
    {
        $this->access->authorize($this->access->canManageAgreement($actor, $agreement));
        $this->assertStatus($agreement, [AgreementStatus::Draft, AgreementStatus::Returned]);
        $errors = $this->validate($agreement);
        if ($errors !== []) {
            throw ValidationException::withMessages(['agreement' => $errors]);
        }

        $next = $this->settings->requireEmployeeAcknowledgement() ? AgreementStatus::PendingEmployeeReview : AgreementStatus::PendingManagerApproval;
        $this->move($agreement, $next, $actor, ['submitted_at' => now(), 'return_reason' => null]);
        if ($next === AgreementStatus::PendingEmployeeReview) {
            $this->notifier->toEmployee($agreement->employee, 'agreement_ready', $agreement);
        }

        return $agreement;
    }

    public function acknowledge(EmployeePerformanceAgreement $agreement, User $actor): EmployeePerformanceAgreement
    {
        $this->access->authorize($this->access->isOwn($actor, $agreement) && $actor->can('employee_performance_agreements.view_own'));
        $this->assertStatus($agreement, [AgreementStatus::PendingEmployeeReview]);

        return $this->move($agreement, AgreementStatus::PendingManagerApproval, $actor, ['employee_acknowledged_at' => now()]);
    }

    public function returnAgreement(EmployeePerformanceAgreement $agreement, string $reason, User $actor): EmployeePerformanceAgreement
    {
        $byEmployee = $this->access->isOwn($actor, $agreement);
        $this->access->authorize($byEmployee || $this->access->canManageAgreement($actor, $agreement));
        $this->assertStatus($agreement, $byEmployee ? [AgreementStatus::PendingEmployeeReview] : [AgreementStatus::PendingEmployeeReview, AgreementStatus::PendingManagerApproval]);

        $this->move($agreement, AgreementStatus::Returned, $actor, ['return_reason' => $reason], $reason);
        if (! $byEmployee) {
            $this->notifier->toEmployee($agreement->employee, 'agreement_returned', $agreement);
        }

        return $agreement;
    }

    public function approve(EmployeePerformanceAgreement $agreement, User $actor): EmployeePerformanceAgreement
    {
        $this->access->authorize($this->access->canManageAgreement($actor, $agreement) && $actor->can('employee_performance_agreements.approve'));
        $this->assertStatus($agreement, [AgreementStatus::PendingManagerApproval]);
        if ($this->validate($agreement) !== []) {
            throw ValidationException::withMessages(['agreement' => $this->validate($agreement)]);
        }

        $cycleRunning = in_array($agreement->cycle->status, [CycleStatus::Active, CycleStatus::MidYearReview, CycleStatus::YearEndReview], true);
        $this->move($agreement, $cycleRunning ? AgreementStatus::Active : AgreementStatus::Agreed, $actor, [
            'manager_approved_at' => now(), 'approved_by' => $actor->getKey(), 'approved_at' => now(),
        ]);
        $this->notifier->toEmployee($agreement->employee, 'agreement_approved', $agreement);

        return $agreement;
    }

    /**
     * Transfer / exit: close this agreement at $endDate. Its items, actuals
     * and evidence stay with it (and roll up into the old unit only).
     */
    public function close(EmployeePerformanceAgreement $agreement, string $endDate, string $reason, User $actor): EmployeePerformanceAgreement
    {
        $this->access->authorize($this->access->canManageAgreement($actor, $agreement));
        if ($agreement->status === AgreementStatus::Closed) {
            return $agreement;
        }

        $end = Carbon::parse($endDate);
        if ($end->lt($agreement->effective_from)) {
            throw ValidationException::withMessages(['end_date' => __('performance.errors.end_before_start')]);
        }

        return $this->move($agreement, AgreementStatus::Closed, $actor, [
            'effective_to' => $end->min($agreement->effective_to)->toDateString(),
            'active_key' => null,
            'closed_at' => now(),
            'close_reason' => $reason,
        ], $reason);
    }

    /**
     * Employee moved to a new assignment mid-cycle: close the old agreement
     * the day before, and draft a new one for the new assignment. A temporary
     * (acting) assignment does not close the primary agreement.
     */
    public function transfer(EmployeePerformanceAgreement $agreement, EmployeeAssignment $newAssignment, User $actor, bool $temporary = false): EmployeePerformanceAgreement
    {
        return DB::transaction(function () use ($agreement, $newAssignment, $actor, $temporary): EmployeePerformanceAgreement {
            if (! $temporary) {
                $start = Carbon::parse($newAssignment->effective_from ?? now());
                $this->close($agreement, $start->copy()->subDay()->max($agreement->effective_from)->toDateString(), 'transfer', $actor);
            }

            return $this->create($agreement->employee, $newAssignment, $agreement->cycle, $actor, null, $temporary);
        });
    }

    private function assertEditable(EmployeePerformanceAgreement $agreement, User $actor): void
    {
        $this->access->authorize($this->access->canManageAgreement($actor, $agreement));
        $this->assertStatus($agreement, [AgreementStatus::Draft, AgreementStatus::Returned]);
    }

    /** @param list<AgreementStatus> $allowed */
    private function assertStatus(EmployeePerformanceAgreement $agreement, array $allowed): void
    {
        if (! in_array($agreement->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('performance.errors.invalid_agreement_status', ['status' => $agreement->status->value])]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function move(EmployeePerformanceAgreement $agreement, AgreementStatus $to, User $actor, array $attributes = [], ?string $reason = null): EmployeePerformanceAgreement
    {
        return DB::transaction(function () use ($agreement, $to, $actor, $attributes, $reason): EmployeePerformanceAgreement {
            /** @var EmployeePerformanceAgreement $locked */
            $locked = EmployeePerformanceAgreement::query()->whereKey($agreement->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== $agreement->status) {
                // Someone else moved it first (double approval, double submit).
                throw ValidationException::withMessages(['status' => __('performance.errors.stale')]);
            }
            $from = $locked->status;
            $locked->forceFill(['status' => $to, ...$attributes])->save();
            $agreement->setRawAttributes($locked->getAttributes(), true);
            $this->audit->record(AuditEventType::PerformanceAgreementStatusChanged, $actor, $locked, ['status' => $to->value], ['status' => $from->value], $reason);

            return $agreement;
        });
    }

    /** Data sources an employee item may use (system data is preferred when it exists). */
    public static function itemSources(): array
    {
        return [KpiDataSource::Manual->value, KpiDataSource::DailyActivity->value, KpiDataSource::SystemTransaction->value, KpiDataSource::DocumentEvidence->value, KpiDataSource::Survey->value];
    }
}
