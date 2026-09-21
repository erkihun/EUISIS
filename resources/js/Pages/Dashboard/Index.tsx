import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import DashboardHeader, { type DashboardHeaderData } from '@/Components/dashboard/DashboardHeader';
import StatTileGroup, { type Stat } from '@/Components/dashboard/StatTile';
import { useLocale } from '@/hooks/useLocale';
import MetricGrid from '@/Components/dashboard/MetricGrid';
import KpiCard from '@/Components/dashboard/KpiCard';
import DateRangeFilter from '@/Components/dashboard/DateRangeFilter';
import ChartCard from '@/Components/dashboard/ChartCard';
import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import EmptyDashboardState from '@/Components/dashboard/EmptyDashboardState';
import { type AlertItem, type QueueItem } from '@/Components/dashboard/AttentionPanel';
import DashboardTabs, { useDashboardTab, type DashboardTab } from '@/Components/dashboard/DashboardTabs';
import ProviderRanking from '@/Components/dashboard/ProviderRanking';
import CardLifecycleFunnel from '@/Components/dashboard/CardLifecycleFunnel';
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useChartColors } from '@/hooks/useChartColors';
import DashboardOverview from '@/Components/dashboard/DashboardOverview';

export interface KpiItem {
    key: string;
    labelKey: string;
    value: string | number;
    valueFormatted: string;
    trend?: number | null;
    trendDirection?: 'up' | 'down' | 'flat' | null;
    comparisonLabelKey?: string | null;
    href?: string | null;
    icon?: 'users' | 'card' | 'building' | 'layers' | 'shield' | 'queue' | 'alert' | 'transfer' | 'coverage' | 'activity' | 'primary';
    tone?: 'primary' | 'success' | 'warning' | 'critical' | 'neutral';
    /**
     * `headline` is the top strip; `overflow` is a headline card demoted
     * because the strip was full; anything else names the tab that renders it.
     * Assigned by DashboardMetricService.
     */
    group?: string;
}

interface KeyValueDatum {
    key: string;
    value: number;
}

interface LabelValueDatum {
    label: string;
    value: number;
}

export interface DashboardProps {
    header: DashboardHeaderData;
    filters: {
        dateRange: string;
        dateFrom: string;
        dateTo: string;
        organizationId: string | null;
        organizationOptions: { id: string; name: string; code: string }[];
    };
    can: Record<string, boolean>;
    kpis: KpiItem[];
    cards: Record<string, Record<string, string | number | null>>;
    charts: Record<string, KeyValueDatum[] | LabelValueDatum[]>;
    workflowQueues: QueueItem[];
    alerts: AlertItem[];
    recentActivity: Array<{
        id: string;
        event: string;
        actor: string;
        subject: string;
        timestamp: string | null;
        severity: 'info' | 'warning' | 'critical';
    }>;
    meta: {
        scope: {
            providerOnly: boolean;
            globalAccess: boolean;
        };
    };
}

const REFRESHED_PROPS = ['kpis', 'cards', 'charts', 'alerts', 'workflowQueues', 'recentActivity', 'header'] as const;

function keyLabel(t: (key: string) => string, prefix: string) {
    return (key: string): string => {
        const translated = t(`${prefix}.${key}`);
        return translated === `${prefix}.${key}` ? key.replace(/_/g, ' ') : translated;
    };
}

function SimpleBarChart({ data, labelFor, emptyTitle }: { data: KeyValueDatum[]; labelFor: (key: string) => string; emptyTitle?: string }) {
    const colors = useChartColors();

    if (data.length === 0) {
        return <EmptyDashboardState compact title={emptyTitle} />;
    }

    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                <XAxis dataKey="key" tickFormatter={labelFor} tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} width={36} />
                <Tooltip
                    contentStyle={{ background: 'var(--app-surface)', borderColor: 'var(--app-border)', color: 'var(--app-foreground)', borderRadius: 8 }}
                    labelStyle={{ color: 'var(--app-foreground)' }}
                    itemStyle={{ color: 'var(--app-foreground)' }}
                    formatter={(value, name) => [Number(value ?? 0), labelFor(String(name))]}
                    labelFormatter={(label) => labelFor(String(label))}
                />
                {/* Square-ish caps: a 8px radius on a 20px bar reads as a pill. */}
                <Bar dataKey="value" fill={colors.primary} radius={[2, 2, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function SimpleLineChart({ data, emptyTitle }: { data: LabelValueDatum[]; emptyTitle?: string }) {
    const colors = useChartColors();

    if (data.length === 0) {
        return <EmptyDashboardState compact title={emptyTitle} />;
    }

    return (
        <ResponsiveContainer width="100%" height={240}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} vertical={false} />
                <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} width={36} />
                <Tooltip contentStyle={{ background: 'var(--app-surface)', borderColor: 'var(--app-border)', color: 'var(--app-foreground)', borderRadius: 8 }} labelStyle={{ color: 'var(--app-foreground)' }} itemStyle={{ color: 'var(--app-foreground)' }} />
                <Line type="monotone" dataKey="value" stroke={colors.primary} strokeWidth={2} dot={false} />
            </LineChart>
        </ResponsiveContainer>
    );
}

export default function Dashboard({
    header,
    filters,
    can,
    kpis,
    cards,
    charts,
    workflowQueues,
    alerts,
    recentActivity,
    meta,
}: DashboardProps) {
    const { t } = useLocale();
    const { getString, getNumber } = useSystemSettings();
    const [refreshing, setRefreshing] = useState(false);
    const refreshingRef = useRef(false);

    /*
     * `appearance.dashboard_layout`.
     *
     * `compact` keeps the operational readout — what needs attention, the
     * headline figures, the stat tiles — and drops the charts. It is for a
     * duty desk that wants numbers on a wall display, not analysis.
     */
    const showCharts = getString('appearance.dashboard_layout', 'executive') !== 'compact';

    /*
     * `appearance.dashboard_refresh_seconds`.
     *
     * A partial reload: only the data props are refetched. `router.reload`
     * already preserves scroll position and component state, so a dashboard
     * left open on a wall display stays current without yanking the page out
     * from under anyone reading it, and the open tab does not reset.
     *
     * The registry clamps the value to 15–3600.
     */
    const refreshSeconds = getNumber('appearance.dashboard_refresh_seconds', 60);

    const refreshDashboard = useCallback(() => {
        if (refreshingRef.current) return;

        refreshingRef.current = true;
        setRefreshing(true);
        router.reload({
            only: [...REFRESHED_PROPS],
            onFinish: () => {
                refreshingRef.current = false;
                setRefreshing(false);
            },
        });
    }, []);

    useEffect(() => {
        if (!Number.isFinite(refreshSeconds) || refreshSeconds < 15) return;

        const id = window.setInterval(() => {
            /* Skip while the tab is hidden — no point polling a screen nobody
               is looking at, and it keeps idle sessions off the database. */
            if (document.hidden) return;

            refreshDashboard();
        }, refreshSeconds * 1000);

        return () => window.clearInterval(id);
    }, [refreshDashboard, refreshSeconds]);

    /**
     * Two charts side by side on wide screens, stacked below — and nothing at
     * all under the `compact` layout, which is how that setting takes effect.
     *
     * `items-start` matters: without it the grid stretches every card to the
     * tallest in the row, so a card whose only content is "no data" rendered
     * as a 340px empty rectangle purely to match the chart beside it.
     */
    const ChartRow = ({ children, full = false }: { children: React.ReactNode; full?: boolean }) =>
        showCharts ? (
            <div className={`grid items-start gap-4 ${full ? '' : 'xl:grid-cols-2'}`}>{children}</div>
        ) : null;

    // A figure that was never loaded is null, not 0: the StatTile renders an
    // em dash for it so a real zero stays distinguishable from missing data.
    const cardStatusSeries = charts.cardsByStatus as KeyValueDatum[] | undefined;
    const cardStatusCount = (key: string): number | null => {
        if (!cardStatusSeries) return null;

        return cardStatusSeries.find((row) => row.key === key)?.value ?? 0;
    };
    const numberOrNull = (value: string | number | null | undefined): number | null =>
        value === null || value === undefined ? null : Number(value);

    const employeeStatusLabel = keyLabel(t, 'status');
    const occupancyLabel = keyLabel(t, 'common');
    const verificationLabel = keyLabel(t, 'dashboard.verificationResults');

    const priorityKeys = ['activeEmployees', 'totalPositions', 'activeIdCards', 'pendingCardRequests'];
    const prioritized = priorityKeys.flatMap((key) => kpis.filter((kpi) => kpi.key === key));
    const headlineKpis = [...prioritized, ...kpis.filter((kpi) => !priorityKeys.includes(kpi.key))].slice(0, 4);
    const kpisForGroup = (group: string) => kpis.filter((kpi) => kpi.group === group);

    const renderKpi = (kpi: KpiItem, featured = false) => (
        <KpiCard
            key={kpi.key}
            title={t(kpi.labelKey)}
            value={kpi.valueFormatted}
            icon={kpi.icon}
            tone={kpi.tone}
            trend={kpi.trend}
            trendDirection={kpi.trendDirection}
            comparisonLabel={kpi.comparisonLabelKey ? t(kpi.comparisonLabelKey) : null}
            href={kpi.href ?? null}
            featured={featured}
        />
    );

    /** A group's own KPI tiles, above its charts. Renders nothing when empty. */
    const GroupMetrics = ({ group }: { group: string }) => {
        const items = kpisForGroup(group);
        if (items.length === 0) return null;

        return <MetricGrid count={items.length}>{items.map((kpi) => renderKpi(kpi))}</MetricGrid>;
    };

    /*
     * Tabs are built from permissions, so a viewer never sees an empty panel
     * and never sees a tab they cannot use. Order follows how often each area
     * is opened, not the order the tables happen to be defined in.
     */
    const canStructure = can.organizations || can.positions;
    const canIdentity = can.cards || can.verification || can.nfc;
    const canServices = can.entitlements || can.transactions || can.providers || can.serviceFeedback;
    const canSystem = can.integration;

    const tabs: DashboardTab[] = [
        ...(can.employees || can.positions || can.cards || can.verification || can.nfc || can.audit || can.organizations || kpis.length > 0
            ? [{ id: 'overview', label: t('dashboard.navigationOverview') }] : []),
        ...(can.employees || can.transfers ? [{ id: 'workforce', label: t('dashboard.tabs.workforce') }] : []),
        ...(canStructure ? [{ id: 'structure', label: t('dashboard.tabs.structure') }] : []),
        ...(canIdentity ? [{ id: 'identity', label: t('dashboard.tabs.identity') }] : []),
        ...(canServices ? [{ id: 'services', label: t('dashboard.tabs.services') }] : []),
        ...(canSystem ? [{ id: 'system', label: t('dashboard.tabs.system') }] : []),
    ];

    const [activeTab, setActiveTab] = useDashboardTab(tabs);

    const panelProps = (id: string) => ({
        role: 'tabpanel' as const,
        id: `dashboard-panel-${id}`,
        'aria-labelledby': `dashboard-tab-${id}`,
        className: 'space-y-5',
    });

    return (
        <AuthenticatedLayout>
            <Head title={t('dashboard.title')} />

            <DashboardHeader header={header} refreshing={refreshing} onRefresh={refreshDashboard} filters={<DateRangeFilter filters={filters} t={t} />} />

            <div className="min-w-0 space-y-5">
                <section aria-labelledby="dashboard-overview-title" className="space-y-3">
                    <h2
                        id="dashboard-overview-title"
                        className="sr-only"
                    >
                        {t('dashboard.overview')}
                    </h2>

                    <div className="space-y-5">
                        {kpis.length > 0 ? (
                            <>
                                {headlineKpis.length > 0 && (
                                    <MetricGrid count={headlineKpis.length} variant="featured">
                                        {headlineKpis.map((kpi, index) => renderKpi(kpi, index === 0))}
                                    </MetricGrid>
                                )}
                            </>
                        ) : !Object.values(can).some(Boolean) ? (
                            <EmptyDashboardState title={t('dashboard.noDashboardData')} />
                        ) : null}
                    </div>
                </section>

                <DashboardTabs tabs={tabs} activeId={activeTab} onChange={setActiveTab} label={t('dashboard.title')} />

                {activeTab === 'overview' && (
                    <div {...panelProps('overview')}>
                        <DashboardOverview
                            can={can} cards={cards} charts={charts} kpis={kpis}
                            alerts={alerts} workflowQueues={workflowQueues}
                            recentActivity={recentActivity} header={header}
                            showCharts={showCharts} onNavigate={setActiveTab}
                        />
                    </div>
                )}

                {/* ── Workforce ──────────────────────────────────────────── */}
                {activeTab === 'workforce' && (
                    <div {...panelProps('workforce')}>
                        <GroupMetrics group="employees" />

                        {can.employees && (
                            <>
                                <ChartRow>
                                    <ChartCard title={t('dashboard.employeesByStatus')}>
                                        <StatusDistribution
                                            data={(charts.employeesByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.kpis.registeredEmployees')}>
                                        <SimpleLineChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.employeeRegistrationsTrend as LabelValueDatum[]) ?? []}
                                        />
                                    </ChartCard>
                                </ChartRow>

                                <ChartRow>
                                    <ChartCard title={t('dashboard.employeesByOrganizationType')}>
                                        <SimpleBarChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.employeesByOrganizationType as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.dataQualityTitle')}>
                                        {/* Exact counts, not proportions — a progress
                                            bar would misrepresent them. */}
                                        <StatTileGroup
                                            columns={3}
                                            stats={[
                                                { key: 'dupes', label: t('dashboard.kpis.dataQualityWarnings'), value: numberOrNull(cards.employees?.duplicateWarnings), tone: 'warning' },
                                                { key: 'photos', label: t('dashboard.missingPhotos'), value: numberOrNull(cards.employees?.missingPhotoCount), tone: 'warning' },
                                                { key: 'docs', label: t('dashboard.missingDocuments'), value: numberOrNull(cards.employees?.missingDocumentCount), tone: 'warning' },
                                                { key: 'score', label: t('dashboard.averageDataQualityScore'), value: numberOrNull(cards.employees?.averageDataQualityScore), tone: 'neutral' },
                                            ] satisfies Stat[]}
                                        />
                                    </ChartCard>
                                </ChartRow>
                            </>
                        )}

                        {can.transfers && (
                            <ChartRow>
                                <ChartCard title={t('dashboard.transfersByStatus')}>
                                    <SimpleBarChart
                                        emptyTitle={t('dashboard.noData')}
                                        data={(charts.transfersByStatus as KeyValueDatum[]) ?? []}
                                        labelFor={employeeStatusLabel}
                                    />
                                </ChartCard>
                                <ChartCard title={t('dashboard.transferAgingTitle')}>
                                    <SimpleBarChart
                                        emptyTitle={t('dashboard.noData')}
                                        data={(charts.transferAging as KeyValueDatum[]) ?? []}
                                        labelFor={(value) => t(`dashboard.transferAging.${value}`)}
                                    />
                                </ChartCard>
                            </ChartRow>
                        )}
                    </div>
                )}

                {/* ── Structure ──────────────────────────────────────────── */}
                {activeTab === 'structure' && (
                    <div {...panelProps('structure')}>
                        <GroupMetrics group="organizations" />
                        <GroupMetrics group="positions" />

                        {can.organizations && (
                            <ChartRow>
                                <ChartCard title={t('dashboard.organizationsByType')}>
                                    <SimpleBarChart
                                        emptyTitle={t('dashboard.noData')}
                                        data={(charts.organizationsByType as KeyValueDatum[]) ?? []}
                                        labelFor={(value) => value}
                                    />
                                </ChartCard>
                                <ChartCard title={t('dashboard.organizationsByStatus')}>
                                    <StatusDistribution
                                        data={(charts.organizationsByStatus as KeyValueDatum[]) ?? []}
                                        labelFor={employeeStatusLabel}
                                    />
                                </ChartCard>
                            </ChartRow>
                        )}

                        {can.positions && (
                            <>
                                <ChartRow>
                                    <ChartCard title={t('dashboard.positionsByGradeLevel')}>
                                        <SimpleBarChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.positionsByGradeLevel as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.positionOccupancy')}>
                                        <StatusDistribution
                                            data={(charts.positionsByOccupancy as KeyValueDatum[]) ?? []}
                                            labelFor={occupancyLabel}
                                        />
                                    </ChartCard>
                                </ChartRow>
                                <ChartRow full>
                                    <ChartCard title={t('dashboard.positionsByJobFamily')}>
                                        <SimpleBarChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.positionsByJobFamily as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                </ChartRow>
                            </>
                        )}
                    </div>
                )}

                {/* ── Identity & Access ──────────────────────────────────── */}
                {activeTab === 'identity' && (
                    <div {...panelProps('identity')}>
                        <GroupMetrics group="cards" />
                        <GroupMetrics group="verification" />

                        {can.cards && (
                            <>
                                <StatTileGroup
                                    stats={[
                                        { key: 'active', label: t('dashboard.cardStates.active'), value: cardStatusCount('active'), tone: 'success', href: route('id-cards.index', { status: 'active' }) },
                                        { key: 'expiringSoon', label: t('dashboard.cardStates.expiringSoon'), value: numberOrNull(cards.cards?.expiringSoonCount), tone: 'warning' },
                                        { key: 'expired', label: t('dashboard.cardStates.expired'), value: cardStatusCount('expired'), tone: 'critical' },
                                        { key: 'lost', label: t('dashboard.cardStates.lost'), value: cardStatusCount('lost'), tone: 'critical' },
                                        { key: 'revoked', label: t('dashboard.cardStates.revoked'), value: cardStatusCount('revoked'), tone: 'critical' },
                                        { key: 'replaced', label: t('dashboard.cardStates.replaced'), value: cardStatusCount('replaced'), tone: 'neutral' },
                                        { key: 'printBatches', label: t('dashboard.cardStates.pendingPrintBatches'), value: numberOrNull(cards.cards?.pendingPrintBatches), tone: 'neutral' },
                                    ] satisfies Stat[]}
                                />
                                <ChartRow>
                                    <ChartCard title={t('dashboard.cardsByStatus')}>
                                        <StatusDistribution
                                            data={(charts.cardsByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.lifecycleFunnel')}>
                                        <CardLifecycleFunnel data={(charts.cardLifecycleFunnel as KeyValueDatum[]) ?? []} t={t} />
                                    </ChartCard>
                                </ChartRow>
                            </>
                        )}

                        {can.verification && (
                            <>
                                <ChartRow>
                                    <ChartCard title={t('dashboard.verificationResultsTitle')}>
                                        <StatusDistribution
                                            data={(charts.verificationAllowedDenied as KeyValueDatum[]) ?? []}
                                            labelFor={verificationLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.topDenialReasons')}>
                                        <SimpleBarChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.denialReasons as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                </ChartRow>
                                <ChartRow full>
                                    <ChartCard title={t('dashboard.verificationTrend')}>
                                        <SimpleLineChart
                                            emptyTitle={t('dashboard.noData')}
                                            data={(charts.verificationTrend as LabelValueDatum[]) ?? []}
                                        />
                                    </ChartCard>
                                </ChartRow>
                            </>
                        )}

                        {/* NFC is a separate credential from the QR code: its counts
                            are reported on their own and never merged with card
                            verification figures. */}
                        {can.nfc && cards.nfc && (
                            <StatTileGroup
                                stats={[
                                    { key: 'nfcActive', label: t('dashboard.nfc.active'), value: numberOrNull(cards.nfc.active), tone: 'success', href: route('nfc-management.credentials.index', { status: 'active' }) },
                                    { key: 'nfcPending', label: t('dashboard.nfc.pending'), value: numberOrNull(cards.nfc.pending), tone: 'warning', href: route('nfc-management.credentials.index', { status: 'pending' }) },
                                    { key: 'nfcSuspended', label: t('dashboard.nfc.suspended'), value: numberOrNull(cards.nfc.suspended), tone: 'warning', href: route('nfc-management.credentials.index', { status: 'suspended' }) },
                                    { key: 'nfcRevoked', label: t('dashboard.nfc.revoked'), value: numberOrNull(cards.nfc.revoked), tone: 'critical', href: route('nfc-management.credentials.index', { status: 'revoked' }) },
                                    { key: 'nfcLost', label: t('dashboard.nfc.lost'), value: numberOrNull(cards.nfc.lost), tone: 'critical' },
                                    { key: 'nfcTerminals', label: t('dashboard.nfc.activeTerminals'), value: numberOrNull(cards.nfc.activeTerminals), tone: 'neutral', href: route('nfc-management.terminals.index') },
                                    { key: 'nfcTerminalsOffline', label: t('dashboard.nfc.inactiveTerminals'), value: numberOrNull(cards.nfc.inactiveTerminals), tone: 'warning' },
                                    { key: 'nfcToday', label: t('dashboard.nfc.verificationsToday'), value: numberOrNull(cards.nfc.verificationsToday), tone: 'neutral', href: route('nfc-management.logs.index') },
                                ] satisfies Stat[]}
                            />
                        )}
                    </div>
                )}

                {/* ── Services ───────────────────────────────────────────── */}
                {activeTab === 'services' && (
                    <div {...panelProps('services')}>
                        <GroupMetrics group="entitlements" />
                        <GroupMetrics group="transactions" />

                        {can.serviceFeedback && cards.feedback && (
                            <StatTileGroup
                                stats={[
                                    { key: 'feedbackTotal', label: t('dashboard.feedback.total'), value: numberOrNull(cards.feedback.total), tone: 'neutral', href: route('service-feedback.admin.dashboard') },
                                    { key: 'feedbackAverage', label: t('dashboard.feedback.averageSatisfaction'), value: numberOrNull(cards.feedback.averageSatisfaction), tone: 'success', href: route('service-feedback.admin.reports') },
                                    { key: 'feedbackLow', label: t('dashboard.feedback.lowRatingPending'), value: numberOrNull(cards.feedback.lowRatingPending), tone: Number(cards.feedback.lowRatingPending ?? 0) > 0 ? 'warning' : 'neutral', href: route('service-feedback.admin.index') },
                                    { key: 'feedbackPending', label: t('dashboard.feedback.pendingReview'), value: numberOrNull(cards.feedback.pendingReview), tone: Number(cards.feedback.pendingReview ?? 0) > 0 ? 'warning' : 'neutral', href: route('service-feedback.admin.index') },
                                ] satisfies Stat[]}
                            />
                        )}

                        <ChartRow>
                            {can.entitlements && (
                                <ChartCard title={t('dashboard.sections.serviceEntitlements')}>
                                    <SimpleBarChart
                                        emptyTitle={t('dashboard.noData')}
                                        data={(charts.entitlementsByServiceType as KeyValueDatum[]) ?? []}
                                        labelFor={(value) => value}
                                    />
                                </ChartCard>
                            )}
                            {can.transactions && (
                                <ChartCard title={t('dashboard.sections.serviceTransactions')}>
                                    <SimpleLineChart
                                        emptyTitle={t('dashboard.noData')}
                                        data={(charts.serviceTransactionsTrend as LabelValueDatum[]) ?? []}
                                    />
                                </ChartCard>
                            )}
                        </ChartRow>

                        <ChartRow>
                            {(can.providers || can.transactions) && (
                                <ChartCard title={t('dashboard.sections.providerOverview')}>
                                    <ProviderRanking data={(charts.providersTopUsage as KeyValueDatum[]) ?? []} />
                                </ChartCard>
                            )}
                            {can.transactions && (
                                <ChartCard title={t('dashboard.transactionsByStatus')}>
                                    <StatusDistribution
                                        data={(charts.transactionsByStatus as KeyValueDatum[]) ?? []}
                                        labelFor={employeeStatusLabel}
                                    />
                                </ChartCard>
                            )}
                        </ChartRow>
                    </div>
                )}

                {/* ── System ─────────────────────────────────────────────── */}
                {activeTab === 'system' && (
                    <div {...panelProps('system')}>
                        {can.integration && cards.integration && (
                            <StatTileGroup
                                columns={3}
                                stats={[
                                    { key: 'apps', label: t('dashboard.integration.activeApplications'), value: numberOrNull(cards.integration.activeApplications), tone: 'neutral' },
                                    { key: 'tokens', label: t('dashboard.integration.activeTokens'), value: numberOrNull(cards.integration.activeTokens), tone: 'neutral' },
                                    { key: 'endpoints', label: t('dashboard.integration.activeEndpoints'), value: numberOrNull(cards.integration.activeEndpoints), tone: 'neutral' },
                                    { key: 'requests', label: t('dashboard.integration.requestsToday'), value: numberOrNull(cards.integration.requestsToday), tone: 'neutral' },
                                    { key: 'failed', label: t('dashboard.integration.failedToday'), value: numberOrNull(cards.integration.failedToday), tone: Number(cards.integration.failedToday ?? 0) > 0 ? 'critical' : 'neutral' },
                                ] satisfies Stat[]}
                            />
                        )}

                    </div>
                )}

                {meta.scope.providerOnly && (
                    <p className="rounded-panel border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                        {t('dashboard.providerScopeNotice')}
                    </p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
