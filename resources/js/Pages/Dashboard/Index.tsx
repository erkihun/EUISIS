import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import MetricGrid from '@/Components/dashboard/MetricGrid';
import KpiCard from '@/Components/dashboard/KpiCard';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import DateRangeFilter from '@/Components/dashboard/DateRangeFilter';
import ChartCard from '@/Components/dashboard/ChartCard';
import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import EmptyDashboardState from '@/Components/dashboard/EmptyDashboardState';
import WorkflowQueue from '@/Components/dashboard/WorkflowQueue';
import AlertPanel from '@/Components/dashboard/AlertPanel';
import RecentActivityFeed from '@/Components/dashboard/RecentActivityFeed';
import ProviderRanking from '@/Components/dashboard/ProviderRanking';
import CardLifecycleFunnel from '@/Components/dashboard/CardLifecycleFunnel';
import ProgressMetric from '@/Components/dashboard/ProgressMetric';
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useChartColors } from '@/hooks/useChartColors';

interface KpiItem {
    key: string;
    labelKey: string;
    value: string | number;
    valueFormatted: string;
    trend?: number | null;
    trendDirection?: 'up' | 'down' | 'flat' | null;
    comparisonLabelKey?: string | null;
    icon?: 'users' | 'card' | 'building' | 'layers' | 'shield' | 'queue' | 'alert' | 'transfer' | 'coverage' | 'activity' | 'primary';
    tone?: 'primary' | 'success' | 'warning' | 'critical' | 'neutral';
}

interface KeyValueDatum {
    key: string;
    value: number;
}

interface LabelValueDatum {
    label: string;
    value: number;
}

interface DashboardProps {
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
    workflowQueues: Array<{
        key: string;
        labelKey: string;
        count: number;
        href: string;
        tone: 'primary' | 'warning' | 'neutral';
    }>;
    alerts: Array<{
        key: string;
        titleKey: string;
        descriptionKey: string;
        severity: 'warning' | 'critical' | 'info';
        count: number;
        href: string;
    }>;
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
        <ResponsiveContainer width="100%" height={260}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} />
                <XAxis dataKey="key" tickFormatter={labelFor} tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip
                    formatter={(value, name) => [Number(value ?? 0), labelFor(String(name))]}
                    labelFormatter={(label) => labelFor(String(label))}
                />
                <Bar dataKey="value" fill={colors.primary} radius={[8, 8, 0, 0]} />
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
        <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data}>
                <CartesianGrid strokeDasharray="3 3" stroke={colors.grid} />
                <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Line type="monotone" dataKey="value" stroke={colors.accent} strokeWidth={3} dot={false} />
            </LineChart>
        </ResponsiveContainer>
    );
}

export default function Dashboard({
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

    const employeeStatusLabel = keyLabel(t, 'status');
    const chartLabel = keyLabel(t, 'dashboard.verificationResults');
    const positionStats: Array<[string, string | number | null]> = [
        ['totalPositions', cards.positions?.totalPositions ?? 0],
        ['activePositions', cards.positions?.activePositions ?? 0],
        ['vacantPositions', cards.positions?.vacantPositions ?? 0],
    ];

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('dashboard.title')}
                    description={t('dashboard.subtitle')}
                />
            )}
        >
            <Head title={t('dashboard.title')} />

            <div className="space-y-6">
                <DateRangeFilter filters={filters} t={t} />

                {kpis.length > 0 ? (
                    <MetricGrid>
                        {kpis.map((kpi) => (
                            <KpiCard
                                key={kpi.key}
                                title={t(kpi.labelKey)}
                                value={kpi.valueFormatted}
                                icon={kpi.icon}
                                tone={kpi.tone}
                                trend={kpi.trend}
                                trendDirection={kpi.trendDirection}
                                comparisonLabel={kpi.comparisonLabelKey ? t(kpi.comparisonLabelKey) : null}
                            />
                        ))}
                    </MetricGrid>
                ) : (
                    <EmptyDashboardState title={t('dashboard.noDashboardData')} />
                )}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    <div className="space-y-6">
                        {can.employees && (
                            <DashboardSection
                                title={t('dashboard.sections.employeeOverview')}
                                description={t('dashboard.subtitle')}
                            >
                                <div className="grid gap-6 xl:grid-cols-2">
                                    <ChartCard title={t('dashboard.sections.employeeOverview')}>
                                        <StatusDistribution
                                            data={(charts.employeesByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.kpis.registeredEmployees')}>
                                        <SimpleLineChart emptyTitle={t("dashboard.noData")} data={(charts.employeeRegistrationsTrend as LabelValueDatum[]) ?? []} />
                                    </ChartCard>
                                </div>
                                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                                    <ChartCard title={t('dashboard.sections.organizationOverview')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.employeesByOrganizationType as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.kpis.dataQualityWarnings')}>
                                        <div className="space-y-5">
                                            <ProgressMetric
                                                label={t('dashboard.kpis.dataQualityWarnings')}
                                                value={Number(cards.employees?.duplicateWarnings ?? 0)}
                                                total={Math.max(Number(cards.employees?.duplicateWarnings ?? 0), 1)}
                                                tone="orange"
                                            />
                                            <ProgressMetric
                                                label={t('dashboard.missingPhotos')}
                                                value={Number(cards.employees?.missingPhotoCount ?? 0)}
                                                total={Math.max(Number(kpis.find((item) => item.key === 'registeredEmployees')?.value ?? 0), 1)}
                                            />
                                            <ProgressMetric
                                                label={t('dashboard.missingDocuments')}
                                                value={Number(cards.employees?.missingDocumentCount ?? 0)}
                                                total={Math.max(Number(kpis.find((item) => item.key === 'registeredEmployees')?.value ?? 0), 1)}
                                                tone="green"
                                            />
                                            <div className="rounded-xl bg-gray-50 p-4 dark:bg-slate-950">
                                                <p className="text-sm text-gray-500 dark:text-slate-400">{t('dashboard.averageDataQualityScore')}</p>
                                                <p className="mt-2 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                                    {cards.employees?.averageDataQualityScore ?? 0}
                                                </p>
                                            </div>
                                        </div>
                                    </ChartCard>
                                </div>
                            </DashboardSection>
                        )}

                        {can.organizations && (
                            <DashboardSection title={t('dashboard.sections.organizationOverview')}>
                                <div className="grid gap-6 xl:grid-cols-2">
                                    <ChartCard title={t('dashboard.sections.organizationOverview')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.organizationsByType as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('common.status')}>
                                        <StatusDistribution
                                            data={(charts.organizationsByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                </div>
                            </DashboardSection>
                        )}

                        {can.positions && (
                            <DashboardSection title={t('dashboard.sections.positionsOverview')}>
                                <div className="grid gap-6 xl:grid-cols-3">
                                    {positionStats.map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="rounded-xl bg-gray-50 p-4 dark:bg-slate-950"
                                        >
                                            <p className="text-sm text-gray-500 dark:text-slate-400">
                                                {t(`dashboard.kpis.${key}`)}
                                            </p>
                                            <p className="mt-2 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                                {Number(value).toLocaleString()}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                                <div className="grid gap-6 xl:grid-cols-2">
                                    <ChartCard title={t('dashboard.positionsByGradeLevel')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.positionsByGradeLevel as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.positionsByJobFamily')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.positionsByJobFamily as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                </div>
                            </DashboardSection>
                        )}

                        {can.cards && (
                            <DashboardSection title={t('dashboard.sections.idCardOverview')}>
                                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                                    <ChartCard title={t('dashboard.sections.idCardOverview')}>
                                        <StatusDistribution
                                            data={(charts.cardsByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.lifecycleFunnel')}>
                                        <CardLifecycleFunnel
                                            data={(charts.cardLifecycleFunnel as KeyValueDatum[]) ?? []}
                                            t={t}
                                        />
                                    </ChartCard>
                                </div>
                            </DashboardSection>
                        )}

                        {can.verification && (
                            <DashboardSection title={t('dashboard.sections.verificationOverview')}>
                                <div className="grid gap-6 xl:grid-cols-2">
                                    <ChartCard title={t('dashboard.sections.verificationOverview')}>
                                        <StatusDistribution
                                            data={(charts.verificationAllowedDenied as KeyValueDatum[]) ?? []}
                                            labelFor={chartLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.topDenialReasons')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.denialReasons as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => value}
                                        />
                                    </ChartCard>
                                </div>
                                <ChartCard title={t('dashboard.verificationTrend')}>
                                    <SimpleLineChart emptyTitle={t("dashboard.noData")} data={(charts.verificationTrend as LabelValueDatum[]) ?? []} />
                                </ChartCard>
                            </DashboardSection>
                        )}

                        {(can.entitlements || can.transactions || can.providers) && (
                            <DashboardSection title={t('dashboard.sections.serviceTransactions')}>
                                <div className="grid gap-6 xl:grid-cols-2">
                                    {can.entitlements && (
                                        <ChartCard title={t('dashboard.sections.serviceEntitlements')}>
                                            <SimpleBarChart
                                                emptyTitle={t("dashboard.noData")}
                                                data={(charts.entitlementsByServiceType as KeyValueDatum[]) ?? []}
                                                labelFor={(value) => value}
                                            />
                                        </ChartCard>
                                    )}
                                    {can.transactions && (
                                        <ChartCard title={t('dashboard.sections.serviceTransactions')}>
                                            <SimpleLineChart emptyTitle={t("dashboard.noData")} data={(charts.serviceTransactionsTrend as LabelValueDatum[]) ?? []} />
                                        </ChartCard>
                                    )}
                                </div>
                                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
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
                                </div>
                            </DashboardSection>
                        )}

                        {can.transfers && (
                            <DashboardSection title={t('dashboard.sections.transferOverview')}>
                                <div className="grid gap-6 xl:grid-cols-2">
                                    <ChartCard title={t('dashboard.sections.transferOverview')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.transfersByStatus as KeyValueDatum[]) ?? []}
                                            labelFor={employeeStatusLabel}
                                        />
                                    </ChartCard>
                                    <ChartCard title={t('dashboard.transferAgingTitle')}>
                                        <SimpleBarChart
                                            emptyTitle={t("dashboard.noData")}
                                            data={(charts.transferAging as KeyValueDatum[]) ?? []}
                                            labelFor={(value) => t(`dashboard.transferAging.${value}`)}
                                        />
                                    </ChartCard>
                                </div>
                            </DashboardSection>
                        )}
                    </div>

                    <div className="space-y-6">
                        <DashboardSection title={t('dashboard.sections.workflowQueue')}>
                            <WorkflowQueue items={workflowQueues} t={t} />
                        </DashboardSection>

                        <DashboardSection title={t('dashboard.sections.alertsAndExceptions')}>
                            <AlertPanel alerts={alerts} t={t} />
                        </DashboardSection>

                        {can.audit && (
                            <DashboardSection title={t('dashboard.sections.recentActivity')}>
                                <ChartCard title={t('dashboard.sections.auditAndSecurity')}>
                                    <RecentActivityFeed items={recentActivity} t={t} />
                                </ChartCard>
                            </DashboardSection>
                        )}

                        {meta.scope.providerOnly && (
                            <ChartCard title={t('dashboard.sections.providerOverview')}>
                                <p className="text-sm text-gray-500 dark:text-slate-400">
                                    {t('dashboard.providerScopeNotice')}
                                </p>
                            </ChartCard>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
