import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import KpiCard from '@/Components/dashboard/KpiCard';
import MetricGrid from '@/Components/dashboard/MetricGrid';
import { ConfigurationHealthPanel, type Health } from '@/Components/Cafeteria/PolicyUi';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type ProviderSummary = {
    provider_id: string;
    provider_name: string;
    transaction_count: number;
    total_meal_amount: number;
    total_subsidy: number;
    total_employee_payable: number;
    total_deductions: number;
};

type Stats = {
    active_providers: number;
    today_transactions: number;
    today_extra_scans: number;
    month_total_subsidy: number;
    month_transactions: number;
};

export default function CafeteriaDashboard({
    stats,
    today_by_provider,
    health,
}: {
    stats: Stats;
    today_by_provider: ProviderSummary[];
    /** Present for users who may view policies. */
    health?: Health | null;
}) {
    const { t } = useLocale();
    const money = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const actionPrimaryCls = buttonClassName({ variant: 'primary' });
    const actionSecondaryCls = buttonClassName({ variant: 'outline' });

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('cafeteria.dashboard')}
                    description={t('cafeteria.moduleName')}
                />
            )}
        >
            <Head title={t('cafeteria.dashboard')} />

            <div className="space-y-6">
                {health && <ConfigurationHealthPanel health={health} />}

                <DashboardSection
                    title={t('cafeteria.dashboard')}
                    actions={(
                        <>
                            <Link href={route('cafeteria.scan')} className={actionPrimaryCls}>
                                {t('cafeteria.scanQr')}
                            </Link>
                            <Link href={route('cafeteria.transactions.index')} className={actionSecondaryCls}>
                                {t('cafeteria.transactions')}
                            </Link>
                            <Link href={route('cafeteria.reports.index')} className={actionSecondaryCls}>
                                {t('cafeteria.reports')}
                            </Link>
                        </>
                    )}
                >
                    <MetricGrid>
                        <KpiCard
                            title={t('cafeteria.activeProviders')}
                            value={stats.active_providers.toLocaleString()}
                            icon="store"
                            tone="primary"
                        />
                        <KpiCard
                            title={t('cafeteria.todayTransactions')}
                            value={stats.today_transactions.toLocaleString()}
                            icon="activity"
                            tone="neutral"
                        />
                        <KpiCard
                            title={t('cafeteria.todayExtraScans')}
                            value={stats.today_extra_scans.toLocaleString()}
                            icon="alert"
                            tone="warning"
                        />
                        <KpiCard
                            title={t('cafeteria.monthTotalSubsidy')}
                            value={money.format(stats.month_total_subsidy)}
                            icon="coverage"
                            tone="success"
                        />
                    </MetricGrid>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <KpiCard
                            title={`${t('cafeteria.transactions')} (${t('cafeteria.monthly')})`}
                            value={stats.month_transactions.toLocaleString()}
                            icon="layers"
                            tone="neutral"
                        />
                    </div>
                </DashboardSection>

                <DashboardSection title={t('cafeteria.todayTransactions')}>
                    <div className="overflow-hidden rounded-panel border border-gray-200/80 bg-white/95 shadow-[0_18px_45px_-28px_rgba(15,23,42,0.45)] dark:border-slate-800/80 dark:bg-slate-900/95">
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.provider')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.transactions')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.totalSubsidy')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.totalDeductions')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {today_by_provider.map((row) => (
                                        <tr key={row.provider_id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{row.provider_name}</td>
                                            <td className={cx(tableCellClassName, 'text-right text-[color:var(--app-foreground)]')}>{row.transaction_count}</td>
                                            <td className={cx(tableCellClassName, 'text-right font-medium text-emerald-600')}>{money.format(row.total_subsidy)}</td>
                                            <td className={cx(tableCellClassName, 'text-right font-medium text-orange-600')}>{money.format(row.total_deductions)}</td>
                                        </tr>
                                    ))}
                                    {today_by_provider.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className={cx(tableCellClassName, 'text-center text-[color:var(--app-muted-foreground)]')}>
                                                {t('common.noRecords')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </DashboardSection>
            </div>
        </AuthenticatedLayout>
    );
}
