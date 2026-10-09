import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import PortalDatePicker from '@/Components/Calendar/PortalDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Order = {
    id: string; order_number: string; order_date: string; status: string; quantity: number;
    total_amount?: number; subsidy_amount_applied?: number;
    employee?: { name?: string | null } | null;
    menu?: { title_en?: string | null; title_am?: string | null } | null;
};

const STATUS_CLS: Record<string, string> = {
    pending:   'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    served:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    confirmed: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]',
};

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

export default function OrdersIndex({ providers, selected_provider_id, orders, filters }: {
    providers: Provider[];
    selected_provider_id: string | null;
    orders: Order[];
    filters: Record<string, string>;
}) {
    const { t, locale } = useLocale();
    const menuTitle = (o: Order) => (locale === 'am' && o.menu?.title_am) ? o.menu.title_am : o.menu?.title_en;
    const [date, setDate] = useState(filters.date ?? '');

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const params = Object.fromEntries(fd) as Record<string, string>;
        params.date = date;
        router.get(route('provider.portal.orders.index'), params, { preserveState: true });
    }

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.orders')}
            header={<PageHeader title={t('providerPortal.orders')} />}
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <div className="space-y-4">
                {/* Filters */}
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.date')}</label>
                        <PortalDatePicker value={date} onChange={setDate} className={inputCls} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.status')}</label>
                        <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                            <option value="">{t('common.all')}</option>
                            <option value="pending">{t('providerPortal.pending')}</option>
                            <option value="confirmed">{t('providerPortal.confirmed')}</option>
                            <option value="served">{t('providerPortal.served')}</option>
                            <option value="cancelled">{t('providerPortal.cancelled')}</option>
                        </select>
                    </div>
                    <button type="submit" className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                        {t('common.filter')}
                    </button>
                </form>

                {/* Table */}
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.orderNumber')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.employee')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.menuTitle')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.date')}</th>
                                    <th className="px-4 py-3 text-center text-xs font-medium text-gray-600 dark:text-slate-400">Qty</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.status')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {orders.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noRecords')}</td>
                                    </tr>
                                ) : orders.map(o => (
                                    <tr key={o.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                        <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-400">{o.order_number}</td>
                                        <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{o.employee?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{menuTitle(o) ?? '—'}</td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={o.order_date} /></td>
                                        <td className="px-4 py-3 text-center text-gray-700 dark:text-slate-300">{o.quantity}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_CLS[o.status] ?? 'bg-gray-100 text-gray-600'}`}>{o.status}</span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={route('provider.portal.orders.show', o.id)} className="text-xs font-medium text-orange-600 hover:underline dark:text-orange-400">{t('common.view')}</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
