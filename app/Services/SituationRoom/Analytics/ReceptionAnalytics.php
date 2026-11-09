<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reception desks: the visits citizens made to institutions in scope, how
 * long they waited, which came by appointment, and where they were referred.
 * Counts and averages only; no visitor is named and no phone number leaves
 * the register.
 */
final class ReceptionAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.reception';

    public const VISIT_STATUSES = ['checked_in', 'in_service', 'completed', 'no_show', 'cancelled'];

    public const APPOINTMENT_STATUSES = ['scheduled', 'rescheduled', 'checked_in', 'cancelled', 'no_show'];

    /** Waiting times are averaged over at most this many visits, newest first. */
    private const WAIT_SAMPLE = 5000;

    public function panels(SituationContext $context): array
    {
        return [
            'reception_kpis' => fn (): SituationPanel => $this->kpis($context),
            'visits_by_status' => fn (): SituationPanel => $this->visitStatus($context),
            'appointments_by_status' => fn (): SituationPanel => $this->appointmentStatus($context),
            'visits_trend' => fn (): SituationPanel => $this->visitsTrend($context),
            'visits_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    private function visits(SituationContext $context): Builder
    {
        $query = DB::table('reception_visits as v');
        $context->scope->apply($query, 'v.organization_id');

        return $query;
    }

    private function appointments(SituationContext $context): Builder
    {
        $query = DB::table('reception_appointments as ap');
        $context->scope->apply($query, 'ap.organization_id');

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $referrals = DB::table('reception_referrals as rf');
        $context->scope->apply($referrals, 'rf.from_organization_id');
        $desks = DB::table('reception_desks as d')->where('d.is_active', true);
        $context->scope->apply($desks, 'd.organization_id');

        return SituationPanel::kpis('reception_kpis', $this->title('reception_kpis'), self::SOURCE, [
            $this->kpi('visits', $this->inPeriod($this->visits($context), 'v.checked_in_at', $period)->count()),
            $this->kpi('visits_completed', $this->inPeriod($this->visits($context), 'v.completed_at', $period)->count()),
            $this->kpi('visits_no_show', $this->inPeriod($this->visits($context), 'v.created_at', $period)->where('v.status', 'no_show')->count()),
            $this->kpi('average_wait_minutes', $this->averageWait($context), ['unit' => 'min']),
            $this->kpi('appointments_scheduled', $this->inPeriod($this->appointments($context), 'ap.scheduled_at', $period)->count()),
            $this->kpi('appointments_kept', $this->inPeriod($this->appointments($context), 'ap.scheduled_at', $period)->whereNotNull('ap.checked_in_at')->count()),
            $this->kpi('referrals_made', $this->inPeriod($referrals, 'rf.referred_at', $period)->count()),
            $this->kpi('desks_active', $desks->count()),
        ]);
    }

    /** Minutes from check-in to the start of service, averaged in PHP so no database-specific date arithmetic is needed. */
    private function averageWait(SituationContext $context): ?float
    {
        $rows = $this->inPeriod($this->visits($context), 'v.service_started_at', $context->period())
            ->whereNotNull('v.checked_in_at')
            ->orderByDesc('v.service_started_at')
            ->limit(self::WAIT_SAMPLE)
            ->get(['v.checked_in_at', 'v.service_started_at']);
        if ($rows->isEmpty()) {
            return null;
        }
        $minutes = $rows->map(fn ($row): float => max(0, CarbonImmutable::parse((string) $row->checked_in_at)->diffInSeconds(CarbonImmutable::parse((string) $row->service_started_at)) / 60));

        return round($minutes->avg(), 1);
    }

    private function visitStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod($this->visits($context), 'v.created_at', $context->period()), 'v.status');

        return $this->chart('visits_by_status', 'donut', self::SOURCE, $this->valueLabels('visit_status', self::VISIT_STATUSES), [
            ['key' => 'visits', 'label' => SituationLabel::key('situationRoom.series.visits'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, self::VISIT_STATUSES)],
        ]);
    }

    private function appointmentStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod($this->appointments($context), 'ap.scheduled_at', $context->period()), 'ap.status');

        return $this->chart('appointments_by_status', 'donut', self::SOURCE, $this->valueLabels('appointment_status', self::APPOINTMENT_STATUSES), [
            ['key' => 'appointments', 'label' => SituationLabel::key('situationRoom.series.appointments'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, self::APPOINTMENT_STATUSES)],
        ]);
    }

    private function visitsTrend(SituationContext $context): SituationPanel
    {
        $appointments = $this->trend($this->visits($context)->whereNotNull('v.appointment_id'), 'v.checked_in_at', $context->period());
        $walkIns = $this->trend($this->visits($context)->whereNull('v.appointment_id'), 'v.checked_in_at', $context->period());

        return $this->chart('visits_trend', 'line', self::SOURCE, $appointments['categories'], [
            ['key' => 'appointments', 'label' => SituationLabel::key('situationRoom.series.appointments'), 'values' => $appointments['values']],
            ['key' => 'walk_ins', 'label' => SituationLabel::key('situationRoom.series.walk_ins'), 'values' => $walkIns['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, $this->countBy($this->inPeriod($this->visits($context), 'v.checked_in_at', $context->period()), 'v.organization_id'));

        return $this->chart('visits_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'visits', 'label' => SituationLabel::key('situationRoom.series.visits'), 'values' => $rolled['values']],
        ], span: 2);
    }
}
