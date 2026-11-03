<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Recruitment\Contracts\Data\VacancyAnnouncementStatus;
use Modules\Recruitment\Contracts\Data\VacancyApplicationStatus;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;

/**
 * Recruitment and transfer: the posts institutions in scope have advertised,
 * the applications those posts drew, and how far each application has come.
 * A vacancy is placed where the post is; a transfer application where it is
 * going. Counts only; no applicant is named.
 */
final class RecruitmentAnalytics extends SituationAnalytics
{
    use ScopesByEmployee;

    public const SOURCE = 'situationRoom.sources.recruitment';

    public const TRANSFER_STAGES = ['review', 'approval', 'approved', 'failed', 'closed'];

    public function panels(SituationContext $context): array
    {
        return [
            'recruitment_kpis' => fn (): SituationPanel => $this->kpis($context),
            'vacancy_application_status' => fn (): SituationPanel => $this->applicationStatus($context),
            'transfer_application_stage' => fn (): SituationPanel => $this->transferStages($context),
            'applications_trend' => fn (): SituationPanel => $this->applicationsTrend($context),
            'recruitment_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    /** Advertised posts: one row per position on an announcement, placed at the post's organization. */
    private function vacancyPosts(SituationContext $context): Builder
    {
        $query = DB::table('vacancy_announcement_positions as vap')->join('vacancy_announcements as va', 'va.id', '=', 'vap.vacancy_announcement_id');
        $context->scope->apply($query, 'vap.organization_id');

        return $query;
    }

    private function openVacancyPosts(SituationContext $context): Builder
    {
        return $this->vacancyPosts($context)
            ->where('va.status', VacancyAnnouncementStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('va.application_closes_at')->orWhere('va.application_closes_at', '>=', now()));
    }

    private function applications(SituationContext $context): Builder
    {
        $query = DB::table('vacancy_applications as app')->join('vacancy_announcement_positions as vap', 'vap.id', '=', 'app.vacancy_announcement_position_id');
        $context->scope->apply($query, 'vap.organization_id');

        return $query;
    }

    private function transferPosts(SituationContext $context): Builder
    {
        $query = DB::table('transfer_announcements as ta');
        $context->scope->apply($query, 'ta.organization_id');

        return $query;
    }

    private function transferApplications(SituationContext $context): Builder
    {
        $query = DB::table('transfer_applications as tap');
        $context->scope->apply($query, 'tap.receiving_organization_id');

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $openTransfers = $this->transferPosts($context)
            ->where('ta.status', TransferAnnouncementStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('ta.closing_date')->orWhere('ta.closing_date', '>=', now()->toDateString()));

        return SituationPanel::kpis('recruitment_kpis', $this->title('recruitment_kpis'), self::SOURCE, [
            $this->kpi('vacancy_slots_open', (int) $this->openVacancyPosts($context)->sum('vap.vacancy_slots')),
            $this->kpi('vacancy_announcements_open', $this->openVacancyPosts($context)->distinct()->count('va.id')),
            $this->kpi('applications_received', $this->inPeriod($this->applications($context), 'app.applied_at', $period)->count()),
            $this->kpi('applications_selected', $this->inPeriod($this->applications($context), 'app.selected_at', $period)->count()),
            $this->kpi('transfer_slots_open', (int) $openTransfers->sum('ta.number_of_vacancies')),
            $this->kpi('transfer_applications_received', $this->inPeriod($this->transferApplications($context), 'tap.submitted_at', $period)->count()),
            $this->kpi('transfers_approved', $this->inPeriod($this->transferApplications($context), 'tap.approved_at', $period)->count()),
            $this->kpi('transfers_implemented', $this->inPeriod($this->transferApplications($context), 'tap.implemented_at', $period)->count()),
        ]);
    }

    private function applicationStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->applications($context), 'app.status');
        $buckets = array_column(VacancyApplicationStatus::cases(), 'value');

        return $this->chart('vacancy_application_status', 'bar', self::SOURCE, $this->valueLabels('vacancy_application_status', $buckets), [
            ['key' => 'applications', 'label' => SituationLabel::key('situationRoom.series.applications'), 'values' => $this->suppressSeries(array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets))],
        ]);
    }

    /** Transfer applications in five stages rather than their thirteen statuses. */
    private function transferStages(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->transferApplications($context), 'tap.status');
        $stages = array_fill_keys(self::TRANSFER_STAGES, 0);

        foreach ($counts as $status => $total) {
            $stage = match (TransferApplicationStatus::tryFrom($status)) {
                TransferApplicationStatus::Submitted, TransferApplicationStatus::UnderReview, TransferApplicationStatus::Verified => 'review',
                TransferApplicationStatus::Selected, TransferApplicationStatus::ReleasePending, TransferApplicationStatus::ReceivingPending, TransferApplicationStatus::FinalApprovalPending => 'approval',
                TransferApplicationStatus::Approved, TransferApplicationStatus::Transferred => 'approved',
                TransferApplicationStatus::ImplementationFailed => 'failed',
                default => 'closed',
            };
            $stages[$stage] += $total;
        }

        return $this->chart('transfer_application_stage', 'donut', self::SOURCE, $this->valueLabels('transfer_stage', self::TRANSFER_STAGES), [
            ['key' => 'applications', 'label' => SituationLabel::key('situationRoom.series.applications'), 'values' => array_values($stages)],
        ]);
    }

    private function applicationsTrend(SituationContext $context): SituationPanel
    {
        $vacancies = $this->trend($this->applications($context), 'app.applied_at', $context->period());
        $transfers = $this->trend($this->transferApplications($context), 'tap.submitted_at', $context->period());

        return $this->chart('applications_trend', 'line', self::SOURCE, $vacancies['categories'], [
            ['key' => 'applications_received', 'label' => SituationLabel::key('situationRoom.kpi.applications_received'), 'values' => $vacancies['values']],
            ['key' => 'transfer_applications_received', 'label' => SituationLabel::key('situationRoom.kpi.transfer_applications_received'), 'values' => $transfers['values']],
        ], span: 2);
    }

    /** Open posts per roll-up row: slots advertised for recruitment and for transfer. */
    private function byOrganization(SituationContext $context): SituationPanel
    {
        $vacancies = $this->rolledUp($context, $this->sumBy($this->openVacancyPosts($context), 'vap.organization_id', 'vap.vacancy_slots'));
        $openTransfers = $this->transferPosts($context)->where('ta.status', TransferAnnouncementStatus::Published->value);
        $transfers = $this->rolledUp($context, $this->sumBy($openTransfers, 'ta.organization_id', 'ta.number_of_vacancies'));
        $byId = array_combine($transfers['ids'], $transfers['values']) ?: [];

        return $this->chart('recruitment_by_organization', 'hbar', self::SOURCE, $vacancies['categories'], [
            ['key' => 'vacancy_slots_open', 'label' => SituationLabel::key('situationRoom.kpi.vacancy_slots_open'), 'values' => array_map(fn ($v): int => (int) $v, $vacancies['values'])],
            ['key' => 'transfer_slots_open', 'label' => SituationLabel::key('situationRoom.kpi.transfer_slots_open'), 'values' => array_map(fn (string $id): int => (int) ($byId[$id] ?? 0), $vacancies['ids'])],
        ], span: 2);
    }
}
