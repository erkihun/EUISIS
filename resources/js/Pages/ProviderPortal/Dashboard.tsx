import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Transaction = { id: string; transaction_number: string; scanned_at: string; subsidy_amount_applied: number; status: string; employee?: { display_name?: string | null } | null };
type Order = { id: string; order_number: string; status: string; employee?: { name?: string | null } | null; menu?: { title_en?: string | null } | null };

const STATUS_COLORS: Record<string, string> = {
    accepted: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    pending:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    served:   'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]',
    reversed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
};

function StatusBadge({ status }: { status: string }) {
    return (
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_COLORS[status] ?? 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400'}`}>
            {status}
        </span>
    );
}

function KpiCard({ label, value, icon, color }: { label: string; value: string | number; icon: string; color: string }) {
    return (
        <div className="rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">{label}</p>
                <span className={`flex h-9 w-9 items-center justify-center rounded-card text-lg ${color}`}>{icon}</span>
            </div>
            <p className="mt-3 text-3xl font-bold tabular-nums text-gray-900 dark:text-slate-100">{value}</p>
        </div>
    );
}

export default function Dashboard({ providers, selected_provider_id, stats, recent_transactions, recent_orders }: {
    providers: Provider[];
    selected_provider_id: string | null;
    stats: { today_scans: number; today_subsidy: number; pending_orders: number; active_menus: number };
    recent_transactions: Transaction[];
    recent_orders: Order[];
}) {
    const { t } = useLocale();
    const money = (v: number) => v.toFixed(2);

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.dashboard')}
            header={<PageHeader title={t('providerPortal.dashboard')} />}
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <div className="space-y-6">
                {/* KPIs */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard label={t('providerPortal.todayScans')}   value={stats.today_scans}             icon="⚡" color="bg-orange-100 dark:bg-orange-900/30" />
                    <KpiCard label={t('providerPortal.todaySubsidy')} value={money(stats.today_subsidy)}    icon="💰" color="bg-emerald-100 dark:bg-emerald-900/30" />
                    <KpiCard label={t('providerPortal.pendingOrders')}value={stats.pending_orders}          icon="🛒" color="bg-amber-100 dark:bg-amber-900/30" />
                    <KpiCard label={t('providerPortal.activeMenus')}  value={stats.active_menus}            icon="🍽️" color="bg-blue-100 dark:bg-blue-900/30" />
                </div>

                {/* Quick actions */}
                <div className="flex flex-wrap gap-3">
                    <Link
                        href={route('provider.portal.scan')}
                        className="inline-flex items-center gap-2 rounded-lg bg-orange-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-orange-600"
                    >
                        <span>📷</span> {t('providerPortal.scan')}
                    </Link>
                    <Link
                        href={route('provider.portal.menus.create')}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <span>🍽️</span> {t('providerPortal.menus')}
                    </Link>
                    <Link
                        href={route('provider.portal.orders.index')}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <span>📋</span> {t('providerPortal.orders')}
                    </Link>
                </div>

                <div className="grid gap-5 lg:grid-cols-2">
                    {/* Recent Transactions */}
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('providerPortal.recentActivity')}</h2>
                            <Link href={route('provider.portal.transactions.index')} className="text-xs font-medium text-orange-600 hover:underline dark:text-orange-400">{t('common.viewAll')}</Link>
                        </div>
                        {recent_transactions.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-gray-400 dark:text-slate-500">{t('common.noRecords')}</p>
                        ) : (
                            <div className="divide-y divide-gray-100 dark:divide-slate-800">
                                {recent_transactions.map(tx => (
                                    <Link key={tx.id} href={route('provider.portal.transactions.show', tx.id)} className="flex items-center justify-between px-5 py-3 hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{tx.employee?.display_name ?? tx.transaction_number}</p>
                                            <p className="font-mono text-xs text-gray-400">{tx.transaction_number}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-sm font-semibold text-emerald-600">{money(tx.subsidy_amount_applied)}</p>
                                            <StatusBadge status={tx.status} />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Recent Orders */}
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('providerPortal.recentOrders')}</h2>
                            <Link href={route('provider.portal.orders.index')} className="text-xs font-medium text-orange-600 hover:underline dark:text-orange-400">{t('common.viewAll')}</Link>
                        </div>
                        {recent_orders.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-gray-400 dark:text-slate-500">{t('common.noRecords')}</p>
                        ) : (
                            <div className="divide-y divide-gray-100 dark:divide-slate-800">
                                {recent_orders.map(order => (
                                    <Link key={order.id} href={route('provider.portal.orders.show', order.id)} className="flex items-center justify-between px-5 py-3 hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{order.employee?.name ?? order.order_number}</p>
                                            <p className="text-xs text-gray-400">{order.menu?.title_en}</p>
                                        </div>
                                        <StatusBadge status={order.status} />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
