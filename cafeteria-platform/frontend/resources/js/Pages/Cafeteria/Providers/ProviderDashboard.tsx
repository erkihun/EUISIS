import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
        <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
            <p className="text-xs font-medium text-(--app-muted-foreground)">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-(--app-foreground)">{value}</p>
            {sub && <p className="mt-1 text-xs text-(--app-muted-foreground)">{sub}</p>}
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

    const thCls = 'px-4 py-3 text-left text-xs font-semibold text-(--app-muted-foreground)';

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
                                    className="rounded-lg border border-(--app-border-strong) px-3 py-2 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)"
                                >
                                    {t('cafeteria.providerUsers')}
                                </Link>
                            )}
                            {can.create && (
                                <Link
                                    href={route('cafeteria.providers.create')}
                                    className={buttonClassName({ variant: 'primary' })}
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
                <div className="rounded-panel border border-(--app-border) bg-(--app-surface)">
                    <div className="flex items-center justify-between border-b border-(--app-border) px-6 py-4">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">
                            {t('cafeteria.providers')}
                        </h2>
                        <Link
                            href={route('cafeteria.providers.index')}
                            className={actionLinkClassName()}
                        >
                            {t('common.viewAll')} →
                        </Link>
                    </div>

                    <div className="overflow-x-auto">
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={thCls}>{t('cafeteria.providerCode')}</th>
                                    <th className={thCls}>{t('cafeteria.nameEn')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.branches')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.providerUsers')}</th>
                                    <th className={`${thCls} text-right`}>{t('cafeteria.todayTransactions')}</th>
                                    <th className={`${thCls} text-right`}>{t('cafeteria.todaySubsidy')}</th>
                                    <th className={`${thCls} text-center`}>{t('cafeteria.isActive')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {providers.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className={cx(tableCellClassName, 'text-center text-(--app-muted-foreground)')}>
                                            {t('common.noResults')}
                                        </td>
                                    </tr>
                                ) : providers.map(p => (
                                    <tr key={p.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-foreground)')}>{p.code}</td>
                                        <td className={tableCellClassName}>
                                            <Link
                                                href={route('cafeteria.providers.show', p.id)}
                                                className="font-medium text-(--app-foreground) hover:text-primary hover:underline dark:hover:text-blue-400"
                                            >
                                                {(locale === 'am' && p.name_am) ? p.name_am : p.name_en}
                                            </Link>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-center tabular-nums')}>
                                            <span className={badgeClassName('info')}>
                                                {p.branches_count}
                                            </span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-center tabular-nums')}>
                                            <span className="rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                                {p.active_users_count}
                                            </span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right tabular-nums font-medium text-(--app-foreground)')}>
                                            {p.today_tx > 0 ? p.today_tx.toLocaleString() : <span className="text-(--app-muted-foreground)">—</span>}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right tabular-nums font-medium text-emerald-600')}>
                                            {p.today_subsidy > 0 ? money.format(p.today_subsidy) : <span className="text-(--app-muted-foreground)">—</span>}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-center')}>
                                            <StatusBadge
                                                status={p.is_active ? 'active' : 'inactive'}
                                                label={p.is_active ? t('common.active') : t('common.inactive')}
                                            />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('cafeteria.providers.show', p.id) },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Footer: today label */}
                    <div className="border-t border-(--app-border) px-6 py-3">
                        <p className="text-xs text-(--app-muted-foreground)">
                            {t('cafeteria.todayTransactions')} — {today}
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
