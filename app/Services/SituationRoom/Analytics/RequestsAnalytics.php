<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\OrganizationalChangeRequestStatus as ChangeStatus;
use App\Enums\TemporaryAuthorityStatus;
use App\Models\SupportTicket;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The registers an executive is answerable for: support tickets staff raised,
 * organizational change requests on their way to implementation, employee
 * record corrections awaiting review, and acting appointments in force.
 * Counts only; no requester is named.
 */
final class RequestsAnalytics extends SituationAnalytics
{
    use ScopesByEmployee;

    public const SOURCE = 'situationRoom.sources.requests';

    public const CHANGE_STAGES = ['review', 'implementation', 'done', 'closed'];

    public function panels(SituationContext $context): array
    {
        return [
            'requests_kpis' => fn (): SituationPanel => $this->kpis($context),
            'support_tickets_by_status' => fn (): SituationPanel => $this->ticketStatus($context),
            'change_requests_by_stage' => fn (): SituationPanel => $this->changeStages($context),
            'tickets_trend' => fn (): SituationPanel => $this->ticketsTrend($context),
            'requests_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    private function tickets(SituationContext $context): Builder
    {
        $query = DB::table('support_tickets as st');
        $context->scope->apply($query, 'st.organization_id');

        return $query;
    }

    private function changes(SituationContext $context): Builder
    {
        $query = DB::table('organizational_change_requests as ocr')->where('ocr.status', '!=', ChangeStatus::Draft->value);
        $context->scope->apply($query, 'ocr.organization_id');

        return $query;
    }

    /** @return list<string> */
    private function openChangeStatuses(): array
    {
        return array_values(array_map(fn (ChangeStatus $s): string => $s->value, array_filter(ChangeStatus::cases(), fn (ChangeStatus $s): bool => $this->changeStage($s) === 'review' || $this->changeStage($s) === 'implementation')));
    }

    private function changeStage(?ChangeStatus $status): string
    {
        return match ($status) {
            ChangeStatus::Submitted, ChangeStatus::UnderReview, ChangeStatus::CorrectionRequested, ChangeStatus::Resubmitted => 'review',
            ChangeStatus::Approved, ChangeStatus::PendingImplementation, ChangeStatus::Implementing, ChangeStatus::ImplementationBlocked => 'implementation',
            ChangeStatus::Implemented, ChangeStatus::Completed => 'done',
            default => 'closed',
        };
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $corrections = DB::table('employee_correction_requests as ecr')->whereIn('ecr.employee_id', $this->employeesInScope($context));
        $acting = DB::table('acting_appointments as aa');
        $context->scope->apply($acting, 'aa.organization_id');

        return SituationPanel::kpis('requests_kpis', $this->title('requests_kpis'), self::SOURCE, [
            $this->kpi('tickets_open', $this->tickets($context)->whereIn('st.status', SupportTicket::ACTIVE_STATUSES)->count()),
            $this->kpi('tickets_opened', $this->inPeriod($this->tickets($context), 'st.created_at', $period)->count()),
            $this->kpi('tickets_resolved', $this->inPeriod($this->tickets($context), 'st.resolved_at', $period)->count()),
            $this->kpi('change_requests_open', $this->changes($context)->whereIn('ocr.status', $this->openChangeStatuses())->count()),
            $this->kpi('change_requests_submitted', $this->inPeriod($this->changes($context), 'ocr.submitted_at', $period)->count()),
            $this->kpi('change_requests_completed', $this->inPeriod($this->changes($context), 'ocr.completed_at', $period)->count()),
            $this->kpi('corrections_pending', $corrections->whereNull('ecr.reviewed_at')->count()),
            $this->kpi('acting_active', $acting->where('aa.status', TemporaryAuthorityStatus::Active->value)->where(fn (Builder $q) => $q->whereNull('aa.ends_on')->orWhere('aa.ends_on', '>=', now()->toDateString()))->count()),
        ]);
    }

    private function ticketStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->tickets($context), 'st.status');

        return $this->chart('support_tickets_by_status', 'donut', self::SOURCE, $this->valueLabels('ticket_status', SupportTicket::STATUSES), [
            ['key' => 'tickets', 'label' => SituationLabel::key('situationRoom.series.tickets'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, SupportTicket::STATUSES)],
        ]);
    }

    private function changeStages(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->changes($context), 'ocr.status');
        $stages = array_fill_keys(self::CHANGE_STAGES, 0);
        foreach ($counts as $status => $total) {
            $stages[$this->changeStage(ChangeStatus::tryFrom($status))] += $total;
        }

        return $this->chart('change_requests_by_stage', 'bar', self::SOURCE, $this->valueLabels('change_stage', self::CHANGE_STAGES), [
            ['key' => 'requests', 'label' => SituationLabel::key('situationRoom.series.requests'), 'values' => array_values($stages)],
        ]);
    }

    private function ticketsTrend(SituationContext $context): SituationPanel
    {
        $opened = $this->trend($this->tickets($context), 'st.created_at', $context->period());
        $resolved = $this->trend($this->tickets($context), 'st.resolved_at', $context->period());

        return $this->chart('tickets_trend', 'line', self::SOURCE, $opened['categories'], [
            ['key' => 'tickets_opened', 'label' => SituationLabel::key('situationRoom.kpi.tickets_opened'), 'values' => $opened['values']],
            ['key' => 'tickets_resolved', 'label' => SituationLabel::key('situationRoom.kpi.tickets_resolved'), 'values' => $resolved['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $tickets = $this->rolledUp($context, $this->countBy($this->tickets($context)->whereIn('st.status', SupportTicket::ACTIVE_STATUSES), 'st.organization_id'));
        $changes = $this->rolledUp($context, $this->countBy($this->changes($context)->whereIn('ocr.status', $this->openChangeStatuses()), 'ocr.organization_id'));
        $byId = array_combine($changes['ids'], $changes['values']) ?: [];

        return $this->chart('requests_by_organization', 'hbar', self::SOURCE, $tickets['categories'], [
            ['key' => 'tickets_open', 'label' => SituationLabel::key('situationRoom.kpi.tickets_open'), 'values' => $tickets['values']],
            ['key' => 'change_requests_open', 'label' => SituationLabel::key('situationRoom.kpi.change_requests_open'), 'values' => array_map(fn (string $id): int => (int) ($byId[$id] ?? 0), $tickets['ids'])],
        ], span: 2);
    }
}
