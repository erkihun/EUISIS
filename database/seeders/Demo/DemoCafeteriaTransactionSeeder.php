<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Cafeteria\CreateEmployeeCafeteriaExclusionAction;
use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\EmployeeCafeteriaExclusion;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\WorkingDayCalendarService;
use App\Services\IdCards\CardQrPayloadService;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * A small set of historical meals, each a real scan through
 * CafeteriaQrScanService: policy resolution for the employee's organization,
 * the entitlement ledger, pricing from the policy and the transaction
 * snapshot — nothing is inserted beside the service. Scan nonces are derived
 * from the employee and date, so a re-run finds each meal instead of adding
 * one. Dates are past working days counted back from the first run.
 *
 * Also one approved leave (cafeteria exclusion) covering the first run's day,
 * so leave blocking can be tried at the counter.
 */
class DemoCafeteriaTransactionSeeder extends DemoSeeder
{
    public function run(
        CafeteriaQrScanService $scans,
        CardQrPayloadService $qr,
        WorkingDayCalendarService $calendar,
        CreateEmployeeCafeteriaExclusionAction $createExclusion,
    ): void {
        $operator = DemoDataset::requireUser(DemoDataset::CHECKER_EMAIL);
        $anchor = DemoDataset::anchor();

        foreach (DemoDataset::transactions() as $plan) {
            $employee = DemoDataset::requireEmployee($plan['employee']);
            $card = $employee->activeIdCard()->first()
                ?? throw new RuntimeException("Demo employee {$plan['employee']} has no active card.");
            $cafeteria = DemoDataset::cafeteria($plan['network'], $plan['location'])
                ?? throw new RuntimeException("Demo cafeteria {$plan['network']}-{$plan['location']} is missing.");

            $before = ($plan['window'] ?? null) === 'v1' ? $this->policyV2Start() : $anchor;

            foreach ($this->workingDaysBefore($before, $plan['days'], $cafeteria, $calendar) as $date) {
                $nonce = Uuid::uuid5(Uuid::NAMESPACE_URL, "https://example.test/euisis-demo/meal/{$plan['employee']}/{$date->toDateString()}")->toString();
                if (CafeteriaTransaction::query()->where('scan_nonce', $nonce)->exists()) {
                    continue;
                }

                $result = $scans->process(
                    $qr->buildStableQrUrl($card),
                    $cafeteria,
                    Carbon::parse($date->toDateString().' 12:30', config('app.timezone')),
                    $operator,
                    ['scan_nonce' => $nonce, 'usage_mode' => 'single_day'],
                );

                if (! ($result['allowed'] ?? false)) {
                    throw new RuntimeException("Demo scan for {$plan['employee']} on {$date->toDateString()} was refused: ".($result['denial_reason'] ?? $result['result_code'] ?? 'unknown'));
                }
            }
        }

        $this->ensureLeave($createExclusion, $anchor);
    }

    /**
     * The most recent weekdays strictly before $before on which the cafeteria
     * is open and that are not public holidays.
     *
     * @return list<Carbon>
     */
    private function workingDaysBefore(Carbon $before, int $count, CafeteriaProvider $cafeteria, WorkingDayCalendarService $calendar): array
    {
        $days = [];
        for ($day = $before->copy()->subDay(); count($days) < $count; $day->subDay()) {
            if ($day->isWeekday() && ! $calendar->isHoliday($day) && $calendar->isCafeteriaOpen($day, $cafeteria)) {
                $days[] = $day->copy();
            }
        }

        return $days;
    }

    private function policyV2Start(): Carbon
    {
        $organization = DemoDataset::requireOrganization('ORG-5');
        $v2 = CafeteriaServicePolicy::query()->where('organization_id', $organization->id)->where('version_no', 2)
            ->whereIn('status', CafeteriaPolicyStatus::binding())->first()
            ?? throw new RuntimeException('Organization 5 policy v2 is missing.');

        return $v2->effective_from->copy();
    }

    private function ensureLeave(CreateEmployeeCafeteriaExclusionAction $createExclusion, Carbon $anchor): void
    {
        $employee = DemoDataset::requireEmployee(DemoDataset::LEAVE_EMPLOYEE);
        if (EmployeeCafeteriaExclusion::query()->where('employee_id', $employee->id)->exists()) {
            return;
        }

        $createExclusion->execute([
            'employee_id' => $employee->id,
            'exclusion_type' => 'leave',
            'starts_on' => $anchor->toDateString(),
            'ends_on' => $anchor->copy()->addDays(13)->toDateString(),
            'reason_en' => 'Synthetic demo: approved annual leave',
            'reason_am' => 'ማሳያ፡ የጸደቀ ዓመታዊ ፈቃድ',
        ], DemoDataset::requireUser(DemoDataset::MAKER_EMAIL));
    }
}
