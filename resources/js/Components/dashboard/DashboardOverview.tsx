import { Link } from '@inertiajs/react';
import { ChevronRight as ArrowRight, Plus, ShieldCheck, CreditCard, Layers, GitBranchIcon, TrendingUpIcon, KeyIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import type { DashboardProps } from '@/Pages/Dashboard/Index';
import ActivityTrends, { type TrendSpec } from './ActivityTrends';
import AttentionPanel from './AttentionPanel';
import CardLifecycleFunnel from './CardLifecycleFunnel';
import ChartCard from './ChartCard';
import RatioMeter from './RatioMeter';
import type { LabelValueDatum } from './charts/types';
import RecentActivityFeed from './RecentActivityFeed';
import EmptyDashboardState from './EmptyDashboardState';

type Props = Pick<DashboardProps, 'can' | 'cards' | 'charts' | 'kpis' | 'alerts' | 'workflowQueues' | 'recentActivity' | 'header' | 'filters'> & {
    showCharts: boolean;
    onNavigate: (tab: string) => void;
};

const actionIcons: Record<string, typeof Plus> = {
    addEmployee: Plus, addPosition: Layers, issueIdCard: CreditCard,
    verify: ShieldCheck, organogram: GitBranchIcon, viewReports: TrendingUpIcon, apiManagement: KeyIcon,
};

/**
 * Small, labelled comparisons of aggregate counts; never employee-level data.
 * Largest first, length = share of the whole. One series, so one hue: the
 * `emphasis` row (the healthy state the reader checks first) is primary and
 * the rest a recessive grey, so the bars never pretend to be good/bad status.
 */
function Breakdown({ data, labelFor, showBars, emphasis }: {
    data?: Array<{ key: string; value: number }>;
    labelFor: (key: string) => string;
    showBars: boolean;
    emphasis?: string;
}) {
    const { number } = useDisplayFormat();
    const { t } = useLocale();
    if (!data?.length) return <EmptyDashboardState compact title={t('dashboard.noData')} />;
    const total = data.reduce((sum, item) => sum + item.value, 0);
    const rows = [...data].sort((a, b) => b.value - a.value);
    const highlight = emphasis && rows.some((item) => item.key === emphasis) ? emphasis : undefined;

    return (
        <dl className="space-y-3.5">
            {rows.map((item) => {
                const share = total > 0 ? item.value / total : 0;
                return (
                    <div key={item.key}>
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <dt className="min-w-0 wrap-break-word text-(--app-muted-foreground)">{labelFor(item.key)}</dt>
                            <dd className="shrink-0 tabular-nums">
                                <span className="font-semibold text-(--app-foreground)">{number(item.value)}</span>
                                {showBars && total > 0 && <span className="ms-2 text-xs text-(--app-muted-foreground)">{Math.round(share * 100)}%</span>}
                            </dd>
                        </div>
                        {showBars && (
                            <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                                <div
                                    className={`h-full origin-left rounded-full transition-transform duration-700 ease-out starting:scale-x-0 motion-reduce:transition-none rtl:origin-right ${!highlight || item.key === highlight ? 'bg-primary' : 'bg-(--app-muted-foreground)/40 dark:bg-(--app-muted-foreground)/30'}`}
                                    style={{ width: item.value > 0 ? `${Math.max(2, share * 100)}%` : '0%' }}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </dl>
    );
}

export default function DashboardOverview({ can, cards, charts, kpis, alerts, workflowQueues, recentActivity, header, filters, showCharts, onNavigate }: Props) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();
    const hasAttention = alerts.some((item) => item.count > 0) || workflowQueues.some((item) => item.count > 0);
    const hasSidebar = hasAttention || header.quickActions.length > 0 || can.organizations;
    const hasWorkforce = can.employees || can.positions;
    const series = (key: string) => charts[key] as Array<{ key: string; value: number }> | undefined;
    const trend = (key: string) => charts[key] as LabelValueDatum[] | undefined;
    // The period at a glance, one small multiple per measure the viewer may see.
    const trends = ([
        can.employees && { key: 'employees', title: t('dashboard.overviewCharts.employeesRegistered'), data: trend('employeeRegistrationsTrend') },
        can.verification && { key: 'verifications', title: t('dashboard.overviewCharts.cardVerifications'), data: trend('verificationTrend') },
        can.transactions && { key: 'transactions', title: t('dashboard.overviewCharts.serviceTransactions'), data: trend('serviceTransactionsTrend') },
    ].filter(Boolean) as TrendSpec[]);
    const hasTrends = showCharts && trends.length > 0;
    const hasMain = hasTrends || hasWorkforce || can.cards || can.verification || can.nfc || can.audit;
    const occupancy = Object.fromEntries((series('positionsByOccupancy') ?? []).map((item) => [item.key, item.value]));
    const occupied = occupancy.occupied ?? 0;
    const allowedToday = Number(cards.verification?.allowedToday ?? 0);
    const deniedToday = Number(cards.verification?.deniedToday ?? 0);
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
        <button type="button" onClick={() => onNavigate(tab)} className="inline-flex min-h-9 items-center gap-2 rounded-md text-xs font-semibold text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">
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
                    {hasTrends && (
                        <ChartCard title={t('dashboard.overviewCharts.activityTitle')}>
                            <ActivityTrends trends={trends} dateFrom={filters.dateFrom} dateTo={filters.dateTo} />
                        </ChartCard>
                    )}

                    {hasWorkforce && (
                        <ChartCard title={t('dashboard.workforceSnapshot')} action={details(can.positions ? 'structure' : 'workforce')}>
                            {/* Columns follow the card's width, not the window (container queries). */}
                            <div className="@container">
                                <div className={`grid gap-6 ${can.employees && can.positions ? '@xl:grid-cols-2' : ''}`}>
                                    {can.positions && (
                                        <section aria-label={t('dashboard.positionOccupancy')}>
                                            {showCharts ? (
                                                <RatioMeter
                                                    label={t('dashboard.overviewCharts.positionsFilled')}
                                                    value={occupied}
                                                    total={occupied + (occupancy.vacant ?? 0)}
                                                    valueLabel={t('common.occupied')}
                                                    restLabel={t('common.vacant')}
                                                />
                                            ) : (
                                                <>
                                                    <h3 className="mb-4 text-xs font-medium text-(--app-muted-foreground)">{t('dashboard.positionOccupancy')}</h3>
                                                    <Breakdown data={series('positionsByOccupancy')} labelFor={(key) => t(`common.${key}`)} showBars={false} />
                                                </>
                                            )}
                                        </section>
                                    )}
                                    {can.employees && (
                                        <section className={can.positions ? 'border-t border-(--app-border) pt-5 @xl:border-s @xl:border-t-0 @xl:ps-6 @xl:pt-0' : ''}>
                                            <h3 className="mb-4 text-xs font-medium text-(--app-muted-foreground)">{t('dashboard.employeesByStatus')}</h3>
                                            <Breakdown data={series('employeesByStatus')} labelFor={statusLabel} showBars={showCharts} emphasis="active" />
                                        </section>
                                    )}
                                </div>
                            </div>
                        </ChartCard>
                    )}

                    {(can.cards || can.verification || can.nfc) && (
                        <ChartCard title={t('dashboard.tabs.identity')} action={details('identity')}>
                            <div className="@container">
                                {can.cards && (
                                    <div className={`grid gap-6 ${showCharts ? '@xl:grid-cols-2' : ''}`}>
                                        <section>
                                            <h3 className="mb-4 text-xs font-medium text-(--app-muted-foreground)">{t('dashboard.cardsByStatus')}</h3>
                                            <Breakdown data={series('cardsByStatus')} labelFor={cardStatusLabel} showBars={showCharts} emphasis="active" />
                                        </section>
                                        {showCharts && (
                                            <section className="border-t border-(--app-border) pt-5 @xl:border-s @xl:border-t-0 @xl:ps-6 @xl:pt-0">
                                                <h3 className="mb-4 text-xs font-medium text-(--app-muted-foreground)">{t('dashboard.overviewCharts.cardPipeline')}</h3>
                                                <CardLifecycleFunnel data={series('cardLifecycleFunnel') ?? []} t={t} />
                                            </section>
                                        )}
                                    </div>
                                )}
                                {can.verification && (
                                    <div className={can.cards ? 'mt-5 border-t border-(--app-border) pt-5' : ''}>
                                        {showCharts ? (
                                            <RatioMeter
                                                label={t('dashboard.overviewCharts.verificationsAllowedToday')}
                                                value={allowedToday}
                                                total={allowedToday + deniedToday}
                                                valueLabel={t('dashboard.verificationResults.allowed')}
                                                restLabel={t('dashboard.verificationResults.denied')}
                                                emptyLabel={t('dashboard.overviewCharts.noVerificationsToday')}
                                            />
                                        ) : (
                                            <dl className="grid grid-cols-2 gap-4">
                                                {['allowedToday', 'deniedToday'].map((key) => (
                                                    <div key={key}>
                                                        <dt className="text-xs leading-relaxed text-(--app-muted-foreground)">{t(`dashboard.${key}`)}</dt>
                                                        <dd className="mt-1 text-xl font-semibold tabular-nums text-(--app-foreground)">{cards.verification?.[key] == null ? '—' : number(cards.verification[key])}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </div>
                                )}
                            </div>
                            {can.nfc && cards.nfc && (
                                <div className="mt-4 flex items-center gap-2 border-t border-(--app-border) pt-4 text-sm">
                                    <ShieldCheck className="h-4 w-4 text-(--app-muted-foreground)" aria-hidden="true" />
                                    <span className="flex-1 text-(--app-muted-foreground)">{t('dashboard.nfc.active')}</span>
                                    <span className="font-semibold tabular-nums text-(--app-foreground)">{cards.nfc.active == null ? '—' : number(cards.nfc.active)}</span>
                                </div>
                            )}
                        </ChartCard>
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

            {hasSidebar && (
                <aside className={`min-w-0 space-y-5 ${hasMain ? 'xl:col-span-4' : 'xl:col-span-12'}`}>
                    {hasAttention && <div className="hidden xl:block"><AttentionPanel alerts={alerts} queues={workflowQueues} t={t} title={t('dashboard.needsAttention')} /></div>}
                    {header.quickActions.length > 0 && (
                        <ChartCard title={t('dashboard.quickActions')}>
                            <nav aria-label={t('dashboard.quickActions')} className="-my-2 divide-y divide-(--app-border)">
                                {header.quickActions.map((action) => {
                                    const ActionIcon = actionIcons[action.key] ?? ArrowRight;
                                    return (
                                    <Link key={action.key} href={route(action.routeName, action.params)} className="group flex min-h-11 items-center gap-3 rounded-md py-2 text-sm text-(--app-foreground) hover:text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:text-indigo-300">
                                        <ActionIcon className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
                                        <span className="min-w-0 flex-1 wrap-break-word">{t(`dashboard.actions.${action.key}`)}</span>
                                        <ArrowRight className="h-3.5 w-3.5 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
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
                                        <dt className="text-sm text-(--app-muted-foreground)">{t(item.labelKey)}</dt>
                                        <dd className="text-lg font-semibold tabular-nums text-(--app-foreground)">{item.valueFormatted}</dd>
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
                            {secondaryMetrics.map((item) => <div key={item.key}><dt className="text-sm text-(--app-muted-foreground)">{t(item.labelKey)}</dt><dd className="mt-1 text-xl font-semibold tabular-nums text-(--app-foreground)">{item.valueFormatted}</dd></div>)}
                        </dl>
                    </ChartCard>
                </div>
            )}
            {!hasMain && !hasSidebar && kpis.length === 0 && <div className="xl:col-span-12"><EmptyDashboardState title={t('dashboard.noDashboardData')} /></div>}
        </div>
    );
}
