import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import PortalDatePicker from '@/Components/Calendar/PortalDatePicker';
import { router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

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

export default function ReportsIndex({ providers, selected_provider_id, filters, summary }: {
    providers: Provider[];
    selected_provider_id: string | null;
    filters: Record<string, string>;
    summary: { scan_count: number; subsidy_total: number; employee_payable_total: number; order_count: number };
}) {
    const { t } = useLocale();
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('provider.portal.reports.index'), { date_from: dateFrom, date_to: dateTo }, { preserveState: true });
    }

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.reports')}
            header={<PageHeader title={t('providerPortal.reports')} />}
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <div className="space-y-6">
                {/* Filter card */}
                <div className="rounded-card border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.dateFrom')}</label>
                            <PortalDatePicker value={dateFrom} onChange={setDateFrom} className={inputCls} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.dateTo')}</label>
                            <PortalDatePicker value={dateTo} onChange={setDateTo} className={inputCls} />
                        </div>
                        <button type="submit" className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                            {t('common.filter')}
                        </button>
                    </form>
                </div>

                {/* KPI cards */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard label={t('providerPortal.scanCount')}        value={summary.scan_count}                           icon="⚡" color="bg-orange-100 dark:bg-orange-900/30" />
                    <KpiCard label={t('providerPortal.subsidyApplied')}   value={summary.subsidy_total.toFixed(2)}             icon="💰" color="bg-emerald-100 dark:bg-emerald-900/30" />
                    <KpiCard label={t('providerPortal.employeePayable')}  value={summary.employee_payable_total.toFixed(2)}    icon="👤" color="bg-blue-100 dark:bg-blue-900/30" />
                    <KpiCard label={t('providerPortal.orderCount')}       value={summary.order_count}                          icon="🛒" color="bg-purple-100 dark:bg-purple-900/30" />
                </div>

                {/* Period summary */}
                <div className="rounded-card border border-orange-200 bg-orange-50 p-5 dark:border-orange-800/40 dark:bg-orange-900/10">
                    <p className="text-xs font-semibold text-orange-700 dark:text-orange-400">{t('providerPortal.periodSummary')}</p>
                    <div className="mt-3 flex flex-wrap gap-6 text-sm">
                        <span className="text-gray-700 dark:text-slate-300">
                            {t('providerPortal.subsidyApplied')}: <strong className="text-emerald-600">{summary.subsidy_total.toFixed(2)}</strong>
                        </span>
                        <span className="text-gray-700 dark:text-slate-300">
                            {t('providerPortal.employeePayable')}: <strong className="text-[color:var(--color-primary)]">{summary.employee_payable_total.toFixed(2)}</strong>
                        </span>
                        <span className="text-gray-700 dark:text-slate-300">
                            {t('providerPortal.scanCount')}: <strong className="text-orange-600">{summary.scan_count}</strong>
                        </span>
                    </div>
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
