import { Link } from '@inertiajs/react';
import { ChevronRight as ArrowRight, Plus, ShieldCheck, CreditCard, Layers, GitBranchIcon, TrendingUpIcon, KeyIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import type { DashboardProps } from '@/Pages/Dashboard/Index';
import AttentionPanel from './AttentionPanel';
import ChartCard from './ChartCard';
import RecentActivityFeed from './RecentActivityFeed';
import EmptyDashboardState from './EmptyDashboardState';

type Props = Pick<DashboardProps, 'can' | 'cards' | 'charts' | 'kpis' | 'alerts' | 'workflowQueues' | 'recentActivity' | 'header'> & {
    showCharts: boolean;
    onNavigate: (tab: string) => void;
};

const statusColors: Record<string, string> = {
    active: 'bg-emerald-600 dark:bg-emerald-400',
    occupied: 'bg-[color:var(--color-primary)] dark:bg-indigo-400',
    vacant: 'bg-amber-500',
    pending: 'bg-amber-500',
    expired: 'bg-red-500',
    revoked: 'bg-red-500',
};

const actionIcons: Record<string, typeof Plus> = {
    addEmployee: Plus, addPosition: Layers, issueIdCard: CreditCard,
    verify: ShieldCheck, organogram: GitBranchIcon, viewReports: TrendingUpIcon, apiManagement: KeyIcon,
};

/** Small, labelled comparisons of aggregate counts; never employee-level data. */
function Breakdown({ data, labelFor, showBars }: {
    data?: Array<{ key: string; value: number }>;
    labelFor: (key: string) => string;
    showBars: boolean;
}) {
    const { number } = useDisplayFormat();
    const { t } = useLocale();
    if (!data?.length) return <EmptyDashboardState compact title={t('dashboard.noData')} />;
    const total = data.reduce((sum, item) => sum + item.value, 0);

    return (
        <dl className="space-y-4">
            {data.map((item) => (
                <div key={item.key}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <dt className="min-w-0 break-words text-gray-600 dark:text-slate-300">{labelFor(item.key)}</dt>
                        <dd className="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">{number(item.value)}</dd>
                    </div>
                    {showBars && (
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800" aria-hidden="true">
                            <div className={`h-full rounded-full ${statusColors[item.key] ?? 'bg-slate-400'}`} style={{ width: `${total > 0 ? (item.value / total) * 100 : 0}%` }} />
                        </div>
                    )}
                </div>
            ))}
        </dl>
    );
}

export default function DashboardOverview({ can, cards, charts, kpis, alerts, workflowQueues, recentActivity, header, showCharts, onNavigate }: Props) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();
    const hasAttention = alerts.some((item) => item.count > 0) || workflowQueues.some((item) => item.count > 0);
    const hasSidebar = hasAttention || header.quickActions.length > 0 || can.organizations;
    const hasWorkforce = can.employees || can.positions;
    const hasMain = hasWorkforce || can.cards || can.verification || can.nfc || can.audit;
    const series = (key: string) => charts[key] as Array<{ key: string; value: number }> | undefined;
    const statusLabel = (key: string) => {
        const translated = t(`status.${key}`);
        return translated === `status.${key}` ? key.replace(/_/g, ' ') : translated;
    };
    const cardStatusLabel = (key: string) => {
        const translated = t(`dashboard.cardStates.${key}`);
        if (translated !== `dashboard.cardStates.${key}`) return translated;
        const cardLabel = t(`idCards.${key}`);
        return cardLabel !== `idCards.${key}` ? cardLabel : statusLabel(key);
    };
    const details = (tab: string) => (
        <button type="button" onClick={() => onNavigate(tab)} className="inline-flex min-h-9 items-center gap-2 rounded-md text-xs font-semibold text-[color:var(--color-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">
            {t('dashboard.viewDetails')}<ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </button>
    );
    const organizationMetrics = kpis.filter((item) => item.group === 'organizations');
    const secondaryMetrics = kpis.filter((item) => !['activeEmployees', 'totalPositions', 'activeIdCards', 'pendingCardRequests'].includes(item.key) && item.group !== 'organizations');

    return (
        <div className="grid min-w-0 gap-5 xl:grid-cols-12">
            {hasAttention && <div className="xl:hidden"><AttentionPanel alerts={alerts} queues={workflowQueues} t={t} title={t('dashboard.needsAttention')} /></div>}
            {hasMain && (
                <div className={`min-w-0 space-y-5 ${hasSidebar ? 'xl:col-span-8' : 'xl:col-span-12'}`}>
                    {hasWorkforce && (
                        <ChartCard title={t('dashboard.workforceSnapshot')} action={details(can.positions ? 'structure' : 'workforce')}>
                            <div className={`grid gap-6 ${can.employees && can.positions ? 'sm:grid-cols-2' : ''}`}>
                                {can.positions && (
                                    <section>
                                        <h3 className="mb-4 text-xs font-medium text-gray-500 dark:text-slate-400">{t('dashboard.positionOccupancy')}</h3>
                                        <Breakdown data={series('positionsByOccupancy')} labelFor={(key) => t(`common.${key}`)} showBars={showCharts} />
                                    </section>
                                )}
                                {can.employees && (
                                    <section className={can.positions ? 'border-t border-gray-100 pt-5 sm:border-s sm:border-t-0 sm:ps-6 sm:pt-0 dark:border-slate-800' : ''}>
                                        <h3 className="mb-4 text-xs font-medium text-gray-500 dark:text-slate-400">{t('dashboard.employeesByStatus')}</h3>
                                        <Breakdown data={series('employeesByStatus')} labelFor={statusLabel} showBars={showCharts} />
                                    </section>
                                )}
                            </div>
                        </ChartCard>
                    )}

                    {(can.cards || can.verification || can.nfc) && (
                        <ChartCard title={t('dashboard.tabs.identity')} action={details('identity')}>
                            {can.cards && (
                                <div className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                                    {series('cardsByStatus')?.length ? series('cardsByStatus')!.map((item) => (
                                        <div key={item.key} className="flex items-center gap-2 border-b border-gray-100 pb-3 dark:border-slate-800">
                                            <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full ${statusColors[item.key] ?? 'bg-slate-400'}`} />
                                            <span className="min-w-0 flex-1 text-sm text-gray-600 dark:text-slate-300">{cardStatusLabel(item.key)}</span>
                                            <span className="font-semibold tabular-nums text-gray-900 dark:text-white">{number(item.value)}</span>
                                        </div>
                                    )) : <EmptyDashboardState compact title={t('dashboard.noData')} />}
                                </div>
                            )}
                            {can.verification && (
                                <dl className={`grid grid-cols-2 gap-4 ${can.cards ? 'mt-5 border-t border-gray-100 pt-4 dark:border-slate-800' : ''}`}>
                                    {['allowedToday', 'deniedToday'].map((key) => (
                                        <div key={key}>
                                            <dt className="text-xs leading-relaxed text-gray-500 dark:text-slate-400">{t(`dashboard.${key}`)}</dt>
                                            <dd className="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-slate-100">{cards.verification?.[key] == null ? '—' : number(cards.verification[key])}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                            {can.nfc && cards.nfc && (
                                <div className="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4 text-sm dark:border-slate-800">
                                    <ShieldCheck className="h-4 w-4 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                                    <span className="flex-1 text-gray-600 dark:text-slate-300">{t('dashboard.nfc.active')}</span>
                                    <span className="font-semibold tabular-nums text-gray-900 dark:text-white">{cards.nfc.active == null ? '—' : number(cards.nfc.active)}</span>
                                </div>
                            )}
                        </ChartCard>
                    )}

                    {can.audit && (
                        <ChartCard title={t('dashboard.sections.recentActivity')} action={
                            <Link href={route('audit-logs.index')} className="inline-flex min-h-9 items-center gap-2 rounded-md text-xs font-semibold text-[color:var(--color-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">
                                {t('dashboard.viewAll')}<ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        }>
                            <RecentActivityFeed items={recentActivity} t={t} />
                        </ChartCard>
                    )}
                </div>
            )}

            {hasSidebar && (
                <aside className={`min-w-0 space-y-5 ${hasMain ? 'xl:col-span-4' : 'xl:col-span-12'}`}>
                    {hasAttention && <div className="hidden xl:block"><AttentionPanel alerts={alerts} queues={workflowQueues} t={t} title={t('dashboard.needsAttention')} /></div>}
                    {header.quickActions.length > 0 && (
                        <ChartCard title={t('dashboard.quickActions')}>
                            <nav aria-label={t('dashboard.quickActions')} className="-my-2 divide-y divide-gray-100 dark:divide-slate-800">
                                {header.quickActions.map((action) => {
                                    const ActionIcon = actionIcons[action.key] ?? ArrowRight;
                                    return (
                                    <Link key={action.key} href={route(action.routeName, action.params)} className="group flex min-h-11 items-center gap-3 rounded-md py-2 text-sm text-gray-700 hover:text-[color:var(--color-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-slate-200 dark:hover:text-indigo-300">
                                        <ActionIcon className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                        <span className="min-w-0 flex-1 break-words">{t(`dashboard.actions.${action.key}`)}</span>
                                        <ArrowRight className="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true" />
                                    </Link>
                                    );
                                })}
                            </nav>
                        </ChartCard>
                    )}
                    {can.organizations && organizationMetrics.length > 0 && (
                        <ChartCard title={t('dashboard.sections.organizationOverview')} action={details('structure')}>
                            <dl className="space-y-4">
                                {organizationMetrics.map((item) => (
                                    <div key={item.key} className="flex items-baseline justify-between gap-3">
                                        <dt className="text-sm text-gray-500 dark:text-slate-400">{t(item.labelKey)}</dt>
                                        <dd className="text-lg font-semibold tabular-nums text-gray-900 dark:text-slate-100">{item.valueFormatted}</dd>
                                    </div>
                                ))}
                            </dl>
                        </ChartCard>
                    )}
                </aside>
            )}

            {!hasMain && secondaryMetrics.length > 0 && (
                <div className="xl:col-span-12">
                    <ChartCard title={t('dashboard.kpisTitle')}>
                        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {secondaryMetrics.map((item) => <div key={item.key}><dt className="text-sm text-gray-500 dark:text-slate-400">{t(item.labelKey)}</dt><dd className="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{item.valueFormatted}</dd></div>)}
                        </dl>
                    </ChartCard>
                </div>
            )}
            {!hasMain && !hasSidebar && kpis.length === 0 && <div className="xl:col-span-12"><EmptyDashboardState title={t('dashboard.noDashboardData')} /></div>}
        </div>
    );
}
