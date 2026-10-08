import { Link } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Section } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { CoverageBar, Kpi, KpiGroup, OUTCOMES, oversightHref, type Cycle, type Metrics } from './shell';

type Peer = { required: number; assigned: number; completed: number; missing: number };

export default function DashboardOverview({ cycle, totals, peers, evaluatorsAvailable, canEmployees, filters }: {
    cycle: Cycle; totals: Metrics; peers: Peer[]; evaluatorsAvailable: boolean; canEmployees: boolean; filters: Record<string, string>;
}) {
    const { t } = useLocale();
    const evaluator = peers.reduce((sum, row) => ({
        required: sum.required + row.required,
        assigned: sum.assigned + row.assigned,
        submitted: sum.submitted + row.completed,
        missing: sum.missing + row.missing,
    }), { required: 0, assigned: 0, submitted: 0, missing: 0 });
    const dates = [
        ['period_start', cycle.period_start], ['period_end', cycle.period_end],
        ['submission_deadline', cycle.submission_deadline], ['verification_deadline', cycle.verification_deadline],
    ] as const;
    return <>
        <Section title={t('assessmentOversight.dashboardCycle')}>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div><p className="text-xs text-gray-500">{t('assessmentOversight.status')}</p><p className="mt-1 font-semibold">{t(`assessmentOversight.cycleStatuses.${cycle.status}`)}</p></div>
                {dates.map(([key, value]) => <div key={key}><p className="text-xs text-gray-500">{t(`assessmentOversight.cycleForm.${key}`)}</p><p className="mt-1 text-sm font-medium"><LocalizedDateDisplay value={value} fallback={t('assessmentOversight.notAvailable')} /></p></div>)}
            </div>
        </Section>
        <KpiGroup title={t('assessmentOversight.dashboardWorkload')}>
            <Kpi label={t('assessmentOversight.outcomes.not_assigned')} value={totals.outcomes.not_assigned} href={canEmployees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: 'not_assigned' }) : undefined} tone="warning" />
            <Kpi label={t('assessmentOversight.outcomes.not_started')} value={totals.outcomes.not_started} />
            <Kpi label={t('assessmentOversight.outcomes.in_progress')} value={totals.outcomes.in_progress} />
            <Kpi label={t('assessmentOversight.outcomes.awaiting_review')} value={totals.outcomes.awaiting_review} href={canEmployees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: 'awaiting_review' }) : undefined} />
            <Kpi label={t('assessmentOversight.excluded')} value={totals.excluded} href={canEmployees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, eligibility_status: 'excluded' }) : undefined} />
            <Kpi label={t('assessmentOversight.outcomes.invalid_result')} value={totals.outcomes.invalid_result} tone="danger" />
        </KpiGroup>
        <div className="grid gap-4 xl:grid-cols-2">
            <Section title={t('assessmentOversight.dashboardProgress')} description={t('assessmentOversight.coverageFormula')}>
                <CoverageBar value={totals.coverage_percent} />
                <dl className="mt-4 space-y-3">
                    {OUTCOMES.map((outcome) => <div key={outcome} className="flex items-center justify-between gap-3 text-sm">
                        <dt>{canEmployees ? <Link className="hover:underline" href={oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome })}>{t(`assessmentOversight.outcomes.${outcome}`)}</Link> : t(`assessmentOversight.outcomes.${outcome}`)}</dt>
                        <dd className="font-medium tabular-nums">{(totals.outcomes[outcome] ?? 0).toLocaleString()}</dd>
                    </div>)}
                </dl>
            </Section>
            <Section title={t('assessmentOversight.dashboardEvaluators')} description={t('assessmentOversight.dashboardEvaluatorScope')}>
                {!evaluatorsAvailable ? <p role="status">{t('assessmentOversight.dashboardUnavailable')}</p> : <div className="grid gap-3 sm:grid-cols-2">
                    <Kpi label={t('assessmentOversight.dashboardRequired')} value={evaluator.required} />
                    <Kpi label={t('assessmentOversight.kpi.assigned')} value={evaluator.assigned} />
                    <Kpi label={t('assessmentOversight.kpi.submitted')} value={evaluator.submitted} />
                    <Kpi label={t('assessmentOversight.dashboardPendingResponses')} value={Math.max(0, evaluator.assigned - evaluator.submitted)} tone="warning" />
                    <Kpi label={t('assessmentOversight.dashboardMissingSlots')} value={evaluator.missing} tone="danger" />
                </div>}
            </Section>
        </div>
    </>;
}
