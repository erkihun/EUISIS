<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\DailyWork\Application\Services\DailyActivityService;
use Modules\DailyWork\Contracts\DailyWorkOutputs;
use Modules\DailyWork\Contracts\PerformanceItemSource;
use Modules\DailyWork\Domain\Models\DailyActivityItem;
use Modules\Performance\Domain\Models\EmployeePerformanceAgreement;
use Modules\Performance\Domain\Models\Kpi;
use Modules\Performance\Domain\Models\ObjectiveActivity;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\PerformancePlan;

/*
 * ADR 0019: a day's work names the main / detailed activity of its KPI's
 * objective, and time and completion roll up per activity.
 * "Today" is Wednesday 23 September 2026, 10:00 in Addis Ababa.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-23 10:00:00', 'Africa/Addis_Ababa'));
    $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, 'tracking_start_date');
    SystemSetting::query()->updateOrCreate(['group' => SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, 'key' => 'tracking_start_date'], ['value' => '2026-09-01', 'type' => $definition['type'], 'label_en' => $definition['label_en']]);
    app(SystemSettingsService::class)->clearCache();

    $type = OrganizationType::query()->create(['code' => 'DWL-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'DWL-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'DWL-U', 'name_en' => 'Head office', 'unit_type' => 'directorate', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'DWL-P', 'title_en' => 'Officer', 'is_active' => true]);
    $this->employee = Employee::query()->create(['employee_number' => 'DWL-E', 'first_name' => 'Daily', 'last_name' => 'Worker', 'full_name' => 'Daily Worker', 'email' => 'dwl@example.test', 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $this->employee->update(['current_assignment_id' => $assignment->id]);
    $this->user = User::factory()->create(['email' => 'dwl@example.test', 'status' => 'active']);

    $cycle = PerformanceCycle::query()->create(['code' => 'DWL-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $plan = fn (string $type, ?string $parent) => tap(new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $type === 'ORGANIZATION' ? null : $unit->id, 'position_id' => $type === 'POSITION' ? $position->id : null, 'parent_plan_id' => $parent, 'plan_type' => $type, 'title' => $type]),
        fn (PerformancePlan $p) => $p->forceFill(['status' => 'PUBLISHED', 'lineage_key' => (string) Str::uuid()])->save());
    // Activities are entered on the organization objective; the employee's KPI sits on the cascaded position objective.
    $orgPlan = $plan('ORGANIZATION', null);
    $orgObjective = $orgPlan->objectives()->create(['code' => 'SG-1-1-O1', 'title_en' => 'Put services online', 'objective_type' => 'STRATEGIC', 'weight' => 100]);
    $this->activities = collect([['Choose services', '30', 120], ['Build forms', '70', 480]])->map(fn ($row, $i) => ObjectiveActivity::query()->create(['objective_id' => $orgObjective->id, 'title' => $row[0], 'weight_percent' => $row[1], 'time_minutes' => $row[2], 'sort_order' => $i + 1]));
    $positionPlan = $plan('POSITION', $orgPlan->id);
    $positionObjective = $positionPlan->objectives()->create(['code' => 'SG-1-1-O1.1', 'title_en' => 'Put services online', 'objective_type' => 'INHERITED', 'weight' => 100, 'parent_objective_id' => $orgObjective->id]);
    $this->otherActivity = ObjectiveActivity::query()->create(['objective_id' => $positionPlan->objectives()->create(['code' => 'OTHER', 'title_en' => 'Other', 'objective_type' => 'LOCAL', 'weight' => 0])->id, 'title' => 'Unrelated', 'weight_percent' => '100']);

    $kpi = Kpi::query()->create(['code' => 'DWL-K', 'name_en' => 'Services online', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'DAILY_ACTIVITY', 'frequency' => 'ANNUAL']);
    $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $this->employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $positionPlan->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'effective_from' => '2026-09-01', 'effective_to' => '2026-12-31']);
    $agreement->forceFill(['status' => 'ACTIVE'])->save();
    $this->item = $agreement->items()->create(['objective_id' => $positionObjective->id, 'kpi_id' => $kpi->id, 'expected_output' => 'Services online', 'target_value' => 10, 'weight' => 100, 'data_source_type' => 'DAILY_ACTIVITY']);
});

function dwlWork(array $overrides = []): array
{
    return [['title' => 'Online forms', 'description' => 'Built two forms', 'output_result' => 'Two forms', 'progress_status' => 'completed',
        'started_at' => '08:00', 'ended_at' => '09:30', 'employee_performance_item_id' => test()->item->id, ...$overrides]];
}

it('offers the activities of the objective the KPI was cascaded from', function (): void {
    $items = app(PerformanceItemSource::class)->agreedItems((string) $this->employee->id, Carbon::parse('2026-09-23'));

    expect($items)->toHaveCount(1)
        ->and(array_column($items[0]['activities'], 'title'))->toBe(['Choose services', 'Build forms'])
        ->and($items[0]['activities'][1]['time_minutes'])->toBe(480);
});

it('saves the activity of a daily entry and refuses one from another objective', function (): void {
    $service = app(DailyActivityService::class);
    $date = Carbon::parse('2026-09-23');

    expect(fn () => $service->save($this->user, $this->employee, $date, ['items' => dwlWork(['objective_activity_id' => $this->otherActivity->id])], false))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->save($this->user, $this->employee, $date, ['items' => dwlWork(['employee_performance_item_id' => null, 'objective_activity_id' => $this->activities[1]->id])], false))
        ->toThrow(ValidationException::class);

    $service->save($this->user, $this->employee, $date, ['items' => dwlWork(['objective_activity_id' => $this->activities[1]->id])], true);

    expect(DailyActivityItem::query()->sole()->objective_activity_id)->toBe($this->activities[1]->id);
});

it('adds up minutes and completion per activity from final daily logs only', function (): void {
    $service = app(DailyActivityService::class);
    $outputs = app(DailyWorkOutputs::class);
    $ids = $this->activities->pluck('id')->map(fn ($id) => (string) $id)->all();

    // A draft is not final yet.
    $service->save($this->user, $this->employee, Carbon::parse('2026-09-22'), ['items' => dwlWork(['objective_activity_id' => $this->activities[1]->id, 'progress_status' => 'in_progress'])], false);
    expect($outputs->activityProgress($ids, $this->org->id))->toBe([]);

    // Submitted, but the city default has managers review every day: it counts once approved.
    $log = $service->save($this->user, $this->employee, Carbon::parse('2026-09-23'), ['items' => dwlWork(['objective_activity_id' => $this->activities[1]->id])], true);
    expect($outputs->activityProgress($ids, $this->org->id))->toBe([]);
    $log->forceFill(['status' => 'approved'])->save();

    expect($outputs->activityProgress($ids, $this->org->id))->toBe([(string) $this->activities[1]->id => ['entries' => 1, 'minutes' => 90, 'completed' => true]]);
});
