import { Link } from '@inertiajs/react';
import { ChevronRight as ArrowRight, Plus, ShieldCheck, CreditCard, Layers, GitBranchIcon, TrendingUpIcon, KeyIcon } from '@/Components/Icons';
import { badgeClassName } from '@/Components/ui';
import { overallTone, type BackupHealth } from '@/Components/backup/backupUi';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import type { DashboardProps } from '@/Pages/Dashboard/Index';
import ActivityTrends, { type TrendSpec } from './ActivityTrends';
import AttentionPanel from './AttentionPanel';
import ChartCard from './ChartCard';
import type { LabelValueDatum } from './charts/types';
import RecentActivityFeed from './RecentActivityFeed';
import EmptyDashboardState from './EmptyDashboardState';

type Props = Pick<DashboardProps, 'can' | 'cards' | 'charts' | 'kpis' | 'alerts' | 'workflowQueues' | 'recentActivity' | 'header' | 'filters'> & {
    showCharts: boolean;
    onNavigate: (tab: string) => void;
    backupHealth?: BackupHealth | null;
    /** Grants the viewer holds; decides which deep links the rail may show. */
    permissions: string[];
};

const actionIcons: Record<string, typeof Plus> = {
    addEmployee: Plus, addPosition: Layers, issueIdCard: CreditCard,
    verify: ShieldCheck, organogram: GitBranchIcon, viewReports: TrendingUpIcon, apiManagement: KeyIcon,
};

/** One labelled figure in a module panel: label left, value right, a thin rule between rows. */
function Fact({ label, value, tone }: { label: string; value: string; tone?: 'warning' | 'critical' }) {
    const valueColor = tone === 'critical' ? 'text-red-700 dark:text-red-400' : tone === 'warning' ? 'text-amber-700 dark:text-amber-400' : 'text-(--app-foreground)';

    return (
        <div className="flex items-baseline justify-between gap-3 border-t border-(--app-border) py-2 text-sm first:border-t-0 first:pt-0">
            <dt className="min-w-0 wrap-break-word text-(--app-muted-foreground)">{label}</dt>
            <dd className={`shrink-0 font-semibold tabular-nums ${valueColor}`}>{value}</dd>
        </div>
    );
}

/** A module panel: title, "View" into its tab, a few facts, an optional progress line. */
function ModulePanel({ title, onView, viewLabel, children, meter }: { title: string; onView: () => void; viewLabel: string; children: React.ReactNode; meter?: number | null }) {
    return (
        <article className="flex min-w-0 flex-col gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{title}</h3>
                <button type="button" onClick={onView} className="inline-flex min-h-9 items-center gap-1 rounded-md text-xs font-semibold text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">
                    {viewLabel}<ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>
            {meter !== undefined && meter !== null && (
                <div className="h-2 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                    <div className="h-full rounded-full bg-primary transition-[width] duration-700 motion-reduce:transition-none" style={{ width: `${Math.max(0, Math.min(100, meter))}%` }} />
                </div>
            )}
            <dl className="m-0">{children}</dl>
        </article>
    );
}

/**
 * The overview: what needs doing, how the period moved, one panel per module
 * the viewer may open, and a rail with actions, scope and system state.
 * Every figure comes from the props the page already loads — this file only
 * arranges them.
 */
export default function DashboardOverview({ can, cards, charts, kpis, alerts, workflowQueues, recentActivity, header, filters, showCharts, onNavigate, backupHealth, permissions }: Props) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();
    const hasAttention = alerts.some((item) => item.count > 0) || workflowQueues.some((item) => item.count > 0);
    const series = (key: string) => charts[key] as Array<{ key: string; value: number }> | undefined;
    const trend = (key: string) => charts[key] as LabelValueDatum[] | undefined;
    const fmt = (value: string | number | null | undefined): string => (value === null || value === undefined ? '—' : number(Number(value)));

    const trends = ([
        can.employees && { key: 'employees', title: t('dashboard.overviewCharts.employeesRegistered'), data: trend('employeeRegistrationsTrend') },
        can.verification && { key: 'verifications', title: t('dashboard.overviewCharts.cardVerifications'), data: trend('verificationTrend') },
        can.transactions && { key: 'transactions', title: t('dashboard.overviewCharts.serviceTransactions'), data: trend('serviceTransactionsTrend') },
    ].filter(Boolean) as TrendSpec[]);
    const hasTrends = showCharts && trends.length > 0;

    const occupancy = Object.fromEntries((series('positionsByOccupancy') ?? []).map((item) => [item.key, item.value]));
    const occupied = occupancy.occupied ?? 0;
    const vacant = occupancy.vacant ?? 0;
    const activeEmployees = (series('employeesByStatus') ?? []).find((item) => item.key === 'active')?.value;
    const activeCards = (series('cardsByStatus') ?? []).find((item) => item.key === 'active')?.value;
    const allowedToday = cards.verification?.allowedToday;
    const deniedToday = cards.verification?.deniedToday;

    const hasWorkforce = can.employees || can.positions;
    const hasIdentity = can.cards || can.verification || can.nfc;
    const hasServices = can.serviceFeedback && !!cards.feedback;
    const hasModules = hasWorkforce || hasIdentity || hasServices;
    const hasMain = hasAttention || hasTrends || hasModules || can.audit;

    const organizationMetrics = kpis.filter((item) => item.group === 'organizations');
    const canSystemHealth = permissions.includes('intelligence.system-health.view') && permissions.includes('intelligence.view');
    const canSituationRoom = permissions.includes('situation_room.view');
    const hasRail = header.quickActions.length > 0 || (can.organizations && organizationMetrics.length > 0) || backupHealth || canSystemHealth;

    const backupTone = backupHealth ? overallTone(backupHealth) : 'neutral';
    const backupLabel = backupHealth ? (t(`backup.status.${backupHealth.overall_status}`) === `backup.status.${backupHealth.overall_status}` ? backupHealth.overall_status : t(`backup.status.${backupHealth.overall_status}`)) : t('dashboard.systemState.unknown');
    const restoreDays = backupHealth?.latest_restore_test_at ? Math.round((Date.now() / 1000 - backupHealth.latest_restore_test_at) / 86400) : null;

    if (!hasMain && !hasRail) {
        return kpis.length === 0 ? <EmptyDashboardState title={t('dashboard.noDashboardData')} /> : null;
    }

    return (
        <div className="flex min-w-0 flex-wrap items-start gap-5">
            {hasMain && (
                <div className="min-w-0 flex-[999_1_38rem] space-y-5">
                    {hasAttention && <AttentionPanel alerts={alerts} queues={workflowQueues} t={t} title={t('dashboard.needsAttention')} />}

                    {hasTrends && (
                        <ChartCard title={t('dashboard.overviewCharts.activityTitle')}>
                            <ActivityTrends trends={trends} dateFrom={filters.dateFrom} dateTo={filters.dateTo} />
                        </ChartCard>
                    )}

                    {hasModules && (
                        <section aria-label={t('dashboard.overview')} className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                            {hasWorkforce && (
                                <ModulePanel title={t('dashboard.modules.workforce')} viewLabel={t('dashboard.modules.view')} onView={() => onNavigate(can.positions ? 'structure' : 'workforce')}
                                    meter={can.positions && occupied + vacant > 0 ? (100 * occupied) / (occupied + vacant) : undefined}>
                                    {can.positions && <Fact label={t('dashboard.modules.occupiedPositions')} value={fmt(occupied)} />}
                                    {can.positions && <Fact label={t('dashboard.modules.vacantPositions')} value={fmt(vacant)} tone={vacant > 0 ? 'warning' : undefined} />}
                                    {can.employees && <Fact label={t('dashboard.modules.activeEmployees')} value={fmt(activeEmployees)} />}
                                    {can.employees && cards.employees?.duplicateWarnings != null && <Fact label={t('dashboard.kpis.dataQualityWarnings')} value={fmt(cards.employees.duplicateWarnings)} tone={Number(cards.employees.duplicateWarnings) > 0 ? 'warning' : undefined} />}
                                </ModulePanel>
                            )}
                            {hasIdentity && (
                                <ModulePanel title={t('dashboard.modules.identity')} viewLabel={t('dashboard.modules.view')} onView={() => onNavigate('identity')}>
                                    {can.cards && <Fact label={t('dashboard.modules.activeCards')} value={fmt(activeCards)} />}
                                    {can.cards && <Fact label={t('dashboard.modules.pendingPrint')} value={fmt(cards.cards?.pendingPrintBatches)} />}
                                    {can.verification && <Fact label={`${t('dashboard.modules.verificationsToday')} (${t('dashboard.modules.allowedDenied')})`} value={`${fmt(allowedToday)} · ${fmt(deniedToday)}`} tone={Number(deniedToday ?? 0) > 0 ? 'warning' : undefined} />}
                                    {can.nfc && cards.nfc && <Fact label={t('dashboard.modules.nfcActive')} value={fmt(cards.nfc.active)} />}
                                </ModulePanel>
                            )}
                            {hasServices && (
                                <ModulePanel title={t('dashboard.modules.services')} viewLabel={t('dashboard.modules.view')} onView={() => onNavigate('services')}>
                                    <Fact label={t('dashboard.feedback.total')} value={fmt(cards.feedback?.total)} />
                                    <Fact label={t('dashboard.feedback.pendingReview')} value={fmt(cards.feedback?.pendingReview)} tone={Number(cards.feedback?.pendingReview ?? 0) > 0 ? 'warning' : undefined} />
                                    <Fact label={t('dashboard.feedback.lowRatingPending')} value={fmt(cards.feedback?.lowRatingPending)} tone={Number(cards.feedback?.lowRatingPending ?? 0) > 0 ? 'critical' : undefined} />
                                    <Fact label={t('dashboard.feedback.averageSatisfaction')} value={cards.feedback?.averageSatisfaction == null ? '—' : `${cards.feedback.averageSatisfaction} / 5`} />
                                </ModulePanel>
                            )}
                        </section>
                    )}

                    {can.audit && (
                        <ChartCard title={t('dashboard.sections.recentActivity')} action={
                            <Link href={route('audit-logs.index')} className="inline-flex min-h-9 items-center gap-2 rounded-md text-xs font-semibold text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">
                                {t('dashboard.viewAll')}<ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        }>
                            <RecentActivityFeed items={recentActivity} t={t} />
                        </ChartCard>
                    )}
                </div>
            )}

            {hasRail && (
                <aside className={`min-w-0 space-y-5 ${hasMain ? 'flex-[1_1_18rem] xl:max-w-sm' : 'w-full'}`}>
                    {header.quickActions.length > 0 && (
                        <section aria-labelledby="dashboard-quick-actions">
                            <h2 id="dashboard-quick-actions" className="mb-2 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('dashboard.quickActions')}</h2>
                            <nav aria-label={t('dashboard.quickActions')} className="grid grid-cols-2 gap-2.5">
                                {header.quickActions.map((action) => {
                                    const ActionIcon = actionIcons[action.key] ?? ArrowRight;
                                    return (
                                        <Link key={action.key} href={route(action.routeName, action.params)} className="group flex min-h-20 flex-col items-start gap-2 rounded-panel border border-(--app-border) bg-(--app-surface) p-3 text-sm font-medium text-(--app-foreground) transition-colors hover:border-primary focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                                            <ActionIcon className="h-5 w-5 text-primary dark:text-indigo-300" aria-hidden="true" />
                                            <span className="min-w-0 wrap-break-word">{t(`dashboard.actions.${action.key}`)}</span>
                                        </Link>
                                    );
                                })}
                            </nav>
                        </section>
                    )}

                    {can.organizations && organizationMetrics.length > 0 && (
                        <ChartCard title={t('dashboard.sections.organizationOverview')}>
                            <dl className="m-0">
                                {organizationMetrics.map((item) => <Fact key={item.key} label={t(item.labelKey)} value={item.valueFormatted} />)}
                            </dl>
                            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold">
                                <button type="button" onClick={() => onNavigate('structure')} className="inline-flex min-h-9 items-center gap-1 rounded-md text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">{t('dashboard.viewDetails')}<ArrowRight className="h-3.5 w-3.5" aria-hidden="true" /></button>
                                {canSituationRoom && <Link href={route('situation-room.index')} className="inline-flex min-h-9 items-center gap-1 rounded-md text-primary hover:underline dark:text-indigo-300">{t('dashboard.openSituationRoom')}</Link>}
                            </div>
                        </ChartCard>
                    )}

                    {(backupHealth || canSystemHealth) && (
                        <ChartCard title={t('dashboard.systemState.title')}>
                            <dl className="m-0">
                                {backupHealth && (
                                    <div className="flex items-center justify-between gap-3 py-2 text-sm">
                                        <dt className="text-(--app-muted-foreground)">{t('dashboard.systemState.backup')}</dt>
                                        <dd><span className={badgeClassName(backupTone)}>{backupLabel}</span></dd>
                                    </div>
                                )}
                                {backupHealth && <Fact label={t('dashboard.systemState.lastRestoreTest')} value={restoreDays === null ? '—' : `${number(restoreDays)} d`} />}
                            </dl>
                            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold">
                                {backupHealth && <Link href={route('backups.index')} className="inline-flex min-h-9 items-center gap-1 rounded-md text-primary hover:underline dark:text-indigo-300">{t('dashboard.systemState.openBackup')}</Link>}
                                {canSystemHealth && <Link href={route('intelligence.system-health')} className="inline-flex min-h-9 items-center gap-1 rounded-md text-primary hover:underline dark:text-indigo-300">{t('dashboard.systemState.openSystemHealth')}</Link>}
                            </div>
                        </ChartCard>
                    )}
                </aside>
            )}
        </div>
    );
}
