<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\Performance\CycleStatus;
use App\Models\DailyActivityLog;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\Kpi;
use App\Models\KpiTarget;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Services\Performance\PerformanceCycleService;
use App\Services\Performance\StrategicPlanningService;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal EPMS planning data for Organization 5, through the EPMS services:
 * an organization cycle in planning and three draft strategic goals whose
 * weights total exactly 100%, each fully allocated to units with one lead.
 * Goal SG-1 (30%) is shared by three units: 15 + 10 + 5.
 *
 * Published synthetic plans, a reusable service, a draft agreement and daily
 * evidence complete the example. Fixture publication does not impersonate
 * real approvals or grant privileges to any user.
 * SYNTHETIC DEMO PLANNING DATA ONLY.
 */
class DemoPerformanceSeeder extends DemoSeeder
{
    public const ORGANIZATION = 'ORG-5';

    /** code => [weight, name_en, name_am, [unit key => percent, first = lead]] */
    private const GOALS = [
        'DEMO-SG-1' => ['30', 'Improve service turnaround time', 'የአገልግሎት አሰጣጥ ጊዜን ማሻሻል', ['PLAN' => '15', 'SVC' => '10', 'ICT' => '5']],
        'DEMO-SG-2' => ['40', 'Digitize archive records', 'የመዛግብት መረጃዎችን ዲጂታል ማድረግ', ['SVC' => '40']],
        'DEMO-SG-3' => ['30', 'Strengthen staff capacity', 'የሰራተኞችን አቅም ማጠናከር', ['HR' => '30']],
    ];

    public function run(PerformanceCycleService $cycles, StrategicPlanningService $planning): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $organization = DemoDataset::requireOrganization(self::ORGANIZATION);
        $year = DemoDataset::anchor()->year;
        $code = "DEMO-EPMS-{$year}";

        $cycle = PerformanceCycle::query()->where('organization_id', $organization->id)->where('code', $code)->first();
        if ($cycle === null) {
            $cycle = $cycles->create([
                'code' => $code,
                'name_en' => "DEMO Performance Cycle {$year}",
                'name_am' => "ማሳያ የአፈጻጸም ዑደት {$year}",
                'organization_id' => $organization->id,
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
            ], $maker);
            $cycle = $cycles->transition($cycle, CycleStatus::Planning, $maker);
        }

        $sort = 0;
        foreach (self::GOALS as $goalCode => [$weight, $nameEn, $nameAm, $units]) {
            $sort++;
            if (StrategicGoal::query()->where('cycle_id', $cycle->id)->where('organization_id', $organization->id)->where('code', $goalCode)->exists()) {
                continue;
            }

            DB::transaction(function () use ($planning, $maker, $cycle, $organization, $goalCode, $weight, $nameEn, $nameAm, $units, $sort): void {
                $goal = $planning->createGoal([
                    'cycle_id' => $cycle->id,
                    'organization_id' => $organization->id,
                    'code' => $goalCode,
                    'name_en' => $nameEn,
                    'name_am' => $nameAm,
                    'description_en' => 'Synthetic demo planning data — not an official plan.',
                    'weight_percent' => $weight,
                    'is_shared' => count($units) > 1,
                    'sort_order' => $sort,
                ], $maker);

                $lead = true;
                foreach ($units as $unitKey => $percent) {
                    $planning->addAllocation($goal, [
                        'organization_unit_id' => DemoDataset::unit($organization, $unitKey)->id,
                        'organization_contribution_percent' => $percent,
                        'allocation_type' => count($units) > 1 ? 'SHARED' : 'PRIMARY',
                        'is_lead' => $lead,
                    ], $maker);
                    $lead = false;
                }
            });
        }
        $this->completeCascade($cycle);

    }

    private function completeCascade(PerformanceCycle $cycle): void
    {
        self::assertAllowed();
        $org = DemoDataset::requireOrganization(self::ORGANIZATION);
        $unit = DemoDataset::unit($org, 'PLAN');
        $position = DemoDataset::position($org, 'P-PLAN');
        $employee = DemoDataset::requireEmployee('E-5-5');
        $assignment = $employee->currentAssignment;
        if ($unit === null || $position === null || $assignment === null || $assignment->position_id !== $position->id) {
            throw new \RuntimeException('DEMO planning employee must occupy ORG-5 P-PLAN.');
        }
        if (PerformancePlan::query()->where('cycle_id', $cycle->id)->where('position_id', $position->id)->where('title', 'DEMO Position Performance Plan')->exists()) {
            return; // Preserve published fixtures and subsequent amendments on repeat runs.
        }
        // Explicit isolated DEMO fixture writes; no real approval identities/rights.
        DB::transaction(function () use ($cycle, $org, $unit, $position, $employee, $assignment): void {
            $orgPlan = $this->demoPlan($cycle, $org->id, 'ORGANIZATION', 'DEMO Organization Performance Plan');
            $roots = [];
            foreach (StrategicGoal::query()->where('cycle_id', $cycle->id)->where('organization_id', $org->id)->whereIn('code', array_keys(self::GOALS))->orderBy('sort_order')->get() as $goal) {
                $kpi = Kpi::query()->firstOrCreate(['organization_id' => $org->id, 'code' => 'DEMO-KPI-'.$goal->code], [
                    'name_en' => 'DEMO completed service requests', 'description_en' => 'Synthetic DEMO measure, not official reference data.',
                    'measurement_type' => 'COUNT', 'unit_of_measure' => 'requests', 'direction' => 'HIGHER_IS_BETTER',
                    'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL', 'frequency' => 'ANNUAL', 'is_active' => true,
                ]);
                $objective = PerformanceObjective::query()->create(['performance_plan_id' => $orgPlan->id, 'strategic_goal_id' => $goal->id,
                    'code' => 'DEMO-OBJ-'.$goal->code, 'title_en' => 'DEMO '.$goal->name_en, 'objective_type' => 'STRATEGIC',
                    'weight' => $goal->weight_percent, 'absolute_weight_percent' => $goal->weight_percent, 'local_weight_percent' => $goal->weight_percent]);
                $roots[$goal->code] = [$objective, $this->demoTarget($cycle, $orgPlan, $objective, $kpi, '100'), $kpi];
                if ($goal->status->value === 'DRAFT') {
                    $goal->forceFill(['status' => 'PUBLISHED', 'published_at' => now()])->save();
                }
            }
            [$root, $rootTarget, $kpi] = $roots['DEMO-SG-1'];
            $allocation = $root->strategicGoal->allocations()->where('organization_unit_id', $unit->id)->firstOrFail();
            $unitPlan = $this->demoPlan($cycle, $org->id, 'UNIT', 'DEMO Planning Unit Performance Plan', $unit->id, null, $orgPlan->id);
            $unitObjective = PerformanceObjective::query()->create(['performance_plan_id' => $unitPlan->id, 'parent_objective_id' => $root->id,
                'strategic_goal_id' => $root->strategic_goal_id, 'strategic_goal_allocation_id' => $allocation->id, 'code' => 'DEMO-UNIT-DELIVERY',
                'title_en' => 'DEMO planning service turnaround', 'objective_type' => 'INHERITED', 'cascade_mode' => 'CONTRIBUTE', 'weight' => 100, 'local_weight_percent' => 100]);
            $unitTarget = $this->demoTarget($cycle, $unitPlan, $unitObjective, $kpi, '50', $rootTarget->id);
            $service = PositionService::query()->firstOrCreate(['position_id' => $position->id, 'service_no' => 'DEMO-PLAN-REPORTING'], [
                'organization_id' => $org->id, 'name_en' => 'DEMO Planning and Performance Reporting', 'is_active' => true]);
            $posPlan = $this->demoPlan($cycle, $org->id, 'POSITION', 'DEMO Position Performance Plan', $unit->id, $position->id, $unitPlan->id);
            $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $employee->id,
                'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $posPlan->id, 'organization_id' => $org->id,
                'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'effective_from' => $cycle->start_date, 'effective_to' => $cycle->end_date]);
            foreach ([1 => 'Prepare planning reports', 2 => 'Validate performance reporting data'] as $number => $title) {
                $objective = PerformanceObjective::query()->create(['performance_plan_id' => $posPlan->id, 'parent_objective_id' => $unitObjective->id,
                    'strategic_goal_id' => $root->strategic_goal_id, 'strategic_goal_allocation_id' => $allocation->id, 'position_service_id' => $service->id,
                    'code' => 'DEMO-POS-'.$number, 'title_en' => 'DEMO '.$title, 'objective_type' => 'INHERITED',
                    'cascade_mode' => 'CONTRIBUTE', 'weight' => 50, 'local_weight_percent' => 50]);
                $target = $this->demoTarget($cycle, $posPlan, $objective, $kpi, '25', $unitTarget->id);
                $item = EmployeePerformanceItem::query()->create(['agreement_id' => $agreement->id, 'objective_id' => $objective->id,
                    'kpi_id' => $kpi->id, 'position_target_id' => $target->id, 'expected_output' => 'DEMO '.$title, 'weight' => 50, 'target_value' => 25]);
            }
            $log = new DailyActivityLog(['late_reason' => 'Synthetic DEMO evidence, not an official score.']);
            $log->forceFill(['employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'organization_id' => $org->id,
                'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'activity_date' => DemoDataset::anchor()->toDateString(), 'status' => 'draft'])->save();
            $log->items()->create(['employee_performance_item_id' => $item->id, 'position_service_id' => $service->id,
                'activity_category' => 'report_preparation', 'title' => 'DEMO validated one reporting request',
                'description' => 'Synthetic evidence for a draft agreement, never automatically an official score.',
                'progress_status' => 'completed', 'quantity' => '1.00', 'unit_of_measure' => 'requests']);
        });
    }

    private function demoPlan(PerformanceCycle $cycle, string $orgId, string $type, string $title, ?string $unitId = null, ?string $positionId = null, ?string $parentId = null): PerformancePlan
    {
        $plan = new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $orgId, 'plan_type' => $type, 'title' => $title,
            'organization_unit_id' => $unitId, 'position_id' => $positionId, 'parent_plan_id' => $parentId,
            'effective_from' => $cycle->start_date, 'effective_to' => $cycle->end_date]);
        $plan->forceFill(['lineage_key' => (string) Str::uuid(), 'live_key' => null, 'version_no' => 1, 'status' => 'PUBLISHED', 'published_at' => now()])->save();
        $plan->forceFill(['live_key' => $plan->lineage_key])->save();

        return $plan;
    }

    private function demoTarget(PerformanceCycle $cycle, PerformancePlan $plan, PerformanceObjective $objective, Kpi $kpi, string $target, ?string $parent = null): KpiTarget
    {
        return KpiTarget::query()->create(['performance_plan_id' => $plan->id, 'objective_id' => $objective->id, 'kpi_id' => $kpi->id,
            'parent_target_id' => $parent, 'period_type' => 'ANNUAL', 'period_start' => $cycle->start_date, 'period_end' => $cycle->end_date,
            'weight' => 100, 'target_value' => $target]);
    }
}
