import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Stats = {
    total_providers: number;
    active_providers: number;
    total_branches: number;
    total_users: number;
};

type ProviderRow = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    is_active: boolean;
    branches_count: number;
    active_users_count: number;
    today_tx: number;
    today_subsidy: number;
};

function KpiCard({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
    return (
        <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-gray-900 dark:text-slate-100">{value}</p>
            {sub && <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">{sub}</p>}
        </div>
    );
}

export default function ProviderDashboard({
    stats,
    providers,
    today,
    can,
}: {
    stats: Stats;
    providers: ProviderRow[];
    today: string;
    can: { create: boolean; manageSettings: boolean };
}) {
    const { t, locale } = useLocale();
    const money = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const thCls = 'px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400';
    const tdCls = 'px-4 py-3 text-sm text-gray-700 dark:text-slate-300';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.providerDashboard')}
                    description={t('cafeteria.providers')}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.manageSettings && (
                                <Link
                                    href={route('cafeteria.settings.index') + '?tab=provider-users'}
                                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    {t('cafeteria.providerUsers')}
                                </Link>
                            )}
                            {can.create && (
                                <Link
                                    href={route('cafeteria.providers.create')}
                                    className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    + {t('cafeteria.addProvider')}
                                </Link>
                            )}
                        </div>
                    }
                />
            }
        >

            <div className="space-y-6">

                {/* KPI row */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <KpiCard
                        label={t('cafeteria.totalProviders')}
                        value={stats.total_providers}
                        sub={`${stats.active_providers} ${t('common.active').toLowerCase()}`}
                    />
                    <KpiCard
                        label={t('cafeteria.activeProviders')}
                        value={stats.active_providers}
                    />
                    <KpiCard
                        label={t('cafeteria.branches')}
                        value={stats.total_branches}
                    />
                    <KpiCard
                        label={t('cafeteria.providerUsers')}
                        value={stats.total_users}
                        sub={t('common.active').toLowerCase()}
                    />
                </div>

                {/* Providers table */}
                <div className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('cafeteria.providers')}
                        </h2>
                        <Link
                            href={route('cafeteria.providers.index')}
                            className="text-xs font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                        >
                            {t('common.viewAll')} →
                        </Link>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                    <th className={thCls}>{t('cafeteria.providerCode')}</th>
                                    <th className={thCls}>{t('cafeteria.nameEn')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.branches')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.providerUsers')}</th>
                                    <th className={`${thCls} text-right`}>{t('cafeteria.todayTransactions')}</th>
                                    <th className={`${thCls} text-right`}>{t('cafeteria.todaySubsidy')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.isActive')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {providers.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500">
                                            {t('common.noResults')}
                                        </td>
                                    </tr>
                                ) : providers.map(p => (
                                    <tr key={p.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                        <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">{p.code}</td>
                                        <td className="px-4 py-3">
                                            <Link
                                                href={route('cafeteria.providers.show', p.id)}
                                                className="font-medium text-gray-900 hover:text-[color:var(--color-primary)] hover:underline dark:text-slate-100 dark:hover:text-blue-400"
                                            >
                                                {(locale === 'am' && p.name_am) ? p.name_am : p.name_en}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-center tabular-nums">
                                            <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]">
                                                {p.branches_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center tabular-nums">
                                            <span className="rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                                {p.active_users_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums font-medium text-gray-900 dark:text-slate-100">
                                            {p.today_tx > 0 ? p.today_tx.toLocaleString() : <span className="text-gray-300 dark:text-slate-600">—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums font-medium text-emerald-600">
                                            {p.today_subsidy > 0 ? money.format(p.today_subsidy) : <span className="text-gray-300 dark:text-slate-600">—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <StatusBadge
                                                status={p.is_active ? 'active' : 'inactive'}
                                                label={p.is_active ? t('common.active') : t('common.inactive')}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('cafeteria.providers.show', p.id)}
                                                className="text-xs font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                            >
                                                {t('common.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Footer: today label */}
                    <div className="border-t border-gray-100 px-6 py-3 dark:border-slate-800">
                        <p className="text-xs text-gray-400 dark:text-slate-500">
                            {t('cafeteria.todayTransactions')} — {today}
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
