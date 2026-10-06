<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\EmployeeServiceFeedback;
use App\Models\Kpi;
use App\Models\KpiActual;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\Position;
use App\Models\PositionService;
use App\Services\Performance\EmployeeScoreCalculator;
use App\Services\Performance\KpiActualService;
use App\Services\Performance\ServiceFeedbackPerformanceSync;
use App\Services\Performance\SystemKpiSourceRegistry;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * Client feedback → position service → position plan item → agreement item → KPI actual.
 */
beforeEach(function (): void {
    $this->travelTo('2026-10-05 10:00');
    $type = OrganizationType::query()->create(['code' => 'FB-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'FB-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'FB-U', 'name_en' => 'HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'FB-P', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->otherPosition = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'FB-P2', 'title_en' => 'Records Officer', 'is_active' => true]);
    $service = fn (string $no, string $name, bool $evaluated) => PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->position->id, 'service_no' => $no, 'name_en' => $name, 'is_active' => true, 'is_performance_evaluation_enabled' => $evaluated]);
    $this->recruitment = $service('FB-1', 'Recruitment', true);
    $this->records = $service('FB-2', 'Record Correction', true);
    $this->advice = $service('FB-3', 'Informal Advice', false);

    $this->employee = Employee::query()->create(['employee_number' => 'FB-E', 'first_name' => 'Test', 'last_name' => 'Officer', 'full_name' => 'Test Officer', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2026-01-01']);
    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->token = app(EmployeeFeedbackTokenService::class)->ensureActiveTokenFor($this->employee);

    $cycle = PerformanceCycle::query()->create(['code' => 'FB-FY', 'name_en' => 'FY 2026', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $plan = new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'plan_type' => 'POSITION', 'title' => 'HR Officer plan', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $plan->forceFill(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => 'PUBLISHED'])->save();
    $serviceItem = PerformanceObjective::query()->create(['performance_plan_id' => $plan->id, 'position_service_id' => $this->recruitment->id, 'code' => 'FB-O1', 'title_en' => 'Recruitment satisfaction', 'objective_type' => 'LOCAL', 'weight' => 50]);
    $generalItem = PerformanceObjective::query()->create(['performance_plan_id' => $plan->id, 'code' => 'FB-O2', 'title_en' => 'Overall client satisfaction', 'objective_type' => 'LOCAL', 'weight' => 50]);
    $kpi = Kpi::query()->create(['code' => 'FB-K', 'name_en' => 'Client rating', 'organization_id' => $this->org->id, 'measurement_type' => 'SCORE', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'RATIO_FROM_TOTALS', 'data_source_type' => 'SYSTEM_TRANSACTION', 'system_source_key' => SystemKpiSourceRegistry::SERVICE_FEEDBACK]);
    $this->agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $this->employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $plan->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $this->agreement->forceFill(['status' => 'ACTIVE'])->save();
    $item = fn (PerformanceObjective $objective) => EmployeePerformanceItem::query()->create(['agreement_id' => $this->agreement->id, 'objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'expected_output' => $objective->title_en, 'weight' => 50, 'target_value' => 4, 'data_source_type' => 'SYSTEM_TRANSACTION']);
    $this->serviceItem = $item($serviceItem);
    $this->generalItem = $item($generalItem);
});

function clientRates($test, PositionService $service, int $rating): void
{
    RateLimiter::clear('sf-submit:'.$test->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $test->post('/service-feedback/'.$test->token->token, ['position_service_id' => $service->id, 'rating' => $rating])->assertSessionHasNoErrors();
}

function storedFeedback($test, PositionService $service, int $rating, string $at, ?Position $position = null): EmployeeServiceFeedback
{
    $feedback = EmployeeServiceFeedback::query()->create(['employee_id' => $test->employee->id, 'organization_id' => $test->org->id, 'organization_unit_id' => $test->unit->id, 'position_id' => ($position ?? $test->position)->id, 'position_service_id' => $service->id, 'rating' => $rating, 'status' => 'pending']);
    $feedback->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $feedback;
}

it('turns a client rating into the actual of the agreement items for that service', function (): void {
    clientRates($this, $this->recruitment, 5);
    clientRates($this, $this->recruitment, 4);
    clientRates($this, $this->records, 2);
    clientRates($this, $this->advice, 1); // not marked for performance evaluation

    $serviceActual = KpiActual::query()->where('employee_performance_item_id', $this->serviceItem->id)->sole();
    expect($serviceActual->only(['actual_value', 'actual_numerator', 'actual_denominator']))->toBe(['actual_value' => '4.5000', 'actual_numerator' => '9.0000', 'actual_denominator' => '2.0000'])
        ->and($serviceActual->period_start->toDateString())->toBe('2026-10-01')
        ->and($serviceActual->period_end->toDateString())->toBe('2026-10-31')
        ->and($serviceActual->verified)->toBeTrue();

    // An item without a service takes every evaluated service of the position: (5 + 4 + 2) ÷ 3.
    $generalActual = KpiActual::query()->where('employee_performance_item_id', $this->generalItem->id)->sole();
    expect($generalActual->actual_value)->toBe('3.6667')->and($generalActual->actual_denominator)->toBe('3.0000');

    $trace = collect(app(EmployeeScoreCalculator::class)->trace($this->agreement->fresh())['items'])->keyBy('item_id');
    expect($trace[$this->serviceItem->id]['actual'])->toBe('4.5000');
});

it('keeps one non overlapping row per month however often or widely it is re-synced', function (): void {
    storedFeedback($this, $this->recruitment, 3, '2026-03-10 09:00');
    storedFeedback($this, $this->recruitment, 5, '2026-10-02 09:00');
    $actuals = app(KpiActualService::class);

    $actuals->syncSystem($this->serviceItem, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    $actuals->syncSystem($this->serviceItem, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    $actuals->syncSystem($this->serviceItem, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-15'));

    $rows = KpiActual::query()->where('employee_performance_item_id', $this->serviceItem->id)->orderBy('period_start')->get();
    // Months without feedback store nothing; no rating is not a rating of zero.
    expect($rows->map(fn ($row) => $row->period_start->toDateString().'..'.$row->period_end->toDateString())->all())->toBe(['2026-03-01..2026-03-31', '2026-10-01..2026-10-31']);

    $trace = collect(app(EmployeeScoreCalculator::class)->trace($this->agreement->fresh())['items'])->keyBy('item_id');
    expect($trace[$this->serviceItem->id]['actual'])->toBe('4.0000');
});

it('never moves ratings to another position or into a finalized agreement', function (): void {
    $sync = app(ServiceFeedbackPerformanceSync::class);
    // Given after a transfer: the snapshot names the new position, not this agreement's.
    expect($sync->sync(storedFeedback($this, $this->recruitment, 5, '2026-10-05 09:00', $this->otherPosition)))->toBe(0);
    expect(KpiActual::query()->count())->toBe(0);

    $this->agreement->forceFill(['status' => 'FINALIZED'])->save();
    clientRates($this, $this->recruitment, 5);
    expect(KpiActual::query()->count())->toBe(0)
        ->and(EmployeeServiceFeedback::query()->count())->toBe(2);
});
