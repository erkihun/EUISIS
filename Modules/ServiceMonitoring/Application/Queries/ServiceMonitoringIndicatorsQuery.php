<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringIndicatorSnapshot;
use Modules\ServiceMonitoring\Contracts\ServiceMonitoringIndicators;
use Modules\ServiceMonitoring\Domain\Enums\ActionItemStatus;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * The Situation Room's figures (D26), every one confined to the viewer's
 * organizations before it is counted:
 *
 *   compliance            the latest period opened by the end of the range
 *   accountability        people held accountable with an action date in the range,
 *                         each counted once (latest action), from accepted reports
 *   digital services      verified in the range
 *   standard performance  accepted reports of periods ending in the range
 *   overdue action items  open items past due today
 */
final class ServiceMonitoringIndicatorsQuery implements ServiceMonitoringIndicators
{
    public function __construct(
        private readonly ParticipantResolver $participants,
        private readonly ComplianceService $compliance,
        private readonly FiscalCalendar $calendar,
    ) {}

    public function snapshot(?array $organizationIds, CarbonImmutable $from, CarbonImmutable $to): ServiceMonitoringIndicatorSnapshot
    {
        $scope = fn ($query, string $column) => $organizationIds === null ? $query : $query->whereIn($column, $organizationIds);

        $period = ReportingPeriod::query()->with('fiscalYear')->where('opens_on', '<=', $to->toDateString())->orderByDesc('opens_on')->first();
        $compliance = ['on_time' => 0, 'late' => 0, 'missing' => 0, 'in_progress' => 0];
        if ($period !== null) {
            $expected = $scope($this->participants->expectedFor($period), 'organization_id')->get();
            $tally = ComplianceService::tally($this->compliance->forPeriod($period, $expected));
            $compliance = [
                'on_time' => $tally['on_time'],
                'late' => $tally['late_extension'],
                'missing' => $tally['missing'] + $tally['not_submitted'],
                'in_progress' => $tally['pending'] + $tally['in_review'] + $tally['returned'],
            ];
        }

        $entries = $scope(DB::table('service_monitoring_accountability_entries as e')
            ->join('service_monitoring_submissions as s', 's.id', '=', 'e.submission_id')
            ->where('s.status', SubmissionStatus::Accepted->value)
            ->whereBetween('e.action_date', [$from->toDateString(), $to->toDateString()]), 's.organization_id')
            // Each employee once, by the latest action in the range.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('service_monitoring_accountability_entries as later')
                ->join('service_monitoring_submissions as ls', 'ls.id', '=', 'later.submission_id')
                ->where('ls.status', SubmissionStatus::Accepted->value)
                ->whereColumn('later.employee_id', 'e.employee_id')
                ->whereBetween('later.action_date', [$from->toDateString(), $to->toDateString()])
                ->where(fn ($w) => $w->whereColumn('later.action_date', '>', 'e.action_date')
                    ->orWhere(fn ($t) => $t->whereColumn('later.action_date', 'e.action_date')->whereColumn('later.id', '>', 'e.id'))))
            ->groupBy('e.final_action')
            ->selectRaw('e.final_action AS bucket, COUNT(*) AS total')
            ->pluck('total', 'bucket');
        $accountability = [];
        foreach (FinalAction::cases() as $action) {
            $accountability[$action->value] = (int) ($entries[$action->value] ?? 0);
        }

        $digital = (int) $scope(DB::table('service_monitoring_digital_services as d')
            ->join('service_monitoring_submissions as s', 's.id', '=', 'd.submission_id')
            ->where('d.verification_status', VerificationStatus::Verified->value)
            ->whereBetween('d.verified_at', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()]), 's.organization_id')
            ->count();

        $performance = (array) $scope(DB::table('service_monitoring_standard_performance as sp')
            ->join('service_monitoring_submissions as s', 's.id', '=', 'sp.submission_id')
            ->join('service_monitoring_periods as p', 'p.id', '=', 's.period_id')
            ->where('s.status', SubmissionStatus::Accepted->value)
            ->whereBetween('p.ends_on', [$from->toDateString(), $to->toDateString()]), 's.organization_id')
            ->selectRaw(implode(', ', array_map(fn (string $f): string => "COALESCE(SUM(sp.{$f}), 0) AS {$f}", FormDefinition::SECTION_4)))
            ->first();

        $overdue = $scope(DB::table('service_monitoring_action_items')
            ->whereIn('status', [ActionItemStatus::Open->value, ActionItemStatus::InProgress->value])
            ->where('due_on', '<', $this->calendar->today()), 'owner_organization_id')
            ->count();

        return new ServiceMonitoringIndicatorSnapshot(
            periodLabelEn: $period?->label('en'),
            periodLabelAm: $period?->label('am'),
            compliance: $compliance,
            accountabilityByAction: $accountability,
            digitalServicesVerified: $digital,
            standardPerformance: array_map('intval', array_intersect_key($performance, array_flip(FormDefinition::SECTION_4))) + array_fill_keys(FormDefinition::SECTION_4, 0),
            overdueActionItems: (int) $overdue,
        );
    }
}
