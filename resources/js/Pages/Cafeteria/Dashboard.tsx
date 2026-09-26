import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import DashboardSection from '@/Components/dashboard/DashboardSection';
import KpiCard from '@/Components/dashboard/KpiCard';
import MetricGrid from '@/Components/dashboard/MetricGrid';
import { ConfigurationHealthPanel, type Health } from '@/Components/Cafeteria/PolicyUi';

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

    const actionPrimaryCls =
        'inline-flex h-9 items-center justify-center rounded-lg bg-[color:var(--color-primary)] px-4 text-sm font-medium text-white transition hover:bg-[color:var(--color-primary-hover)]';
    const actionSecondaryCls =
        'inline-flex h-9 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-slate-700 dark:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800';

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
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.provider')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.transactions')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.totalSubsidy')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.totalDeductions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {today_by_provider.map((row) => (
                                        <tr key={row.provider_id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 text-gray-900 dark:text-slate-100">{row.provider_name}</td>
                                            <td className="px-4 py-3 text-right text-gray-700 dark:text-slate-300">{row.transaction_count}</td>
                                            <td className="px-4 py-3 text-right font-medium text-emerald-600">{money.format(row.total_subsidy)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-orange-600">{money.format(row.total_deductions)}</td>
                                        </tr>
                                    ))}
                                    {today_by_provider.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
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
