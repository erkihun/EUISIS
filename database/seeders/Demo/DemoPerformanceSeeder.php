<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\Performance\CycleStatus;
use App\Models\PerformanceCycle;
use App\Models\StrategicGoal;
use App\Services\Performance\PerformanceCycleService;
use App\Services\Performance\StrategicPlanningService;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;

/**
 * Minimal EPMS planning data for Organization 5, through the EPMS services:
 * an organization cycle in planning and three draft strategic goals whose
 * weights total exactly 100%, each fully allocated to units with one lead.
 * Goal SG-1 (30%) is shared by three units: 15 + 10 + 5.
 *
 * Plans, objectives, KPIs and targets are not seeded: they come from the
 * plan and cascade workflow, and the goals stay drafts until then.
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
    }
}
