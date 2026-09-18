import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import PortalDatePicker from '@/Components/Calendar/PortalDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Entry = { id: string; entry_date: string; entry_type: string; debit: number; credit: number; balance_after: number; description?: string | null };

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

function KpiCard({ label, value, color, bg }: { label: string; value: string; color: string; bg: string }) {
    return (
        <div className={`rounded-card border border-gray-200 p-5 dark:border-slate-800 ${bg}`}>
            <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">{label}</p>
            <p className={`mt-2 text-3xl font-bold tabular-nums ${color}`}>{value}</p>
        </div>
    );
}

export default function LedgerIndex({ providers, selected_provider_id, entries, filters }: {
    providers: Provider[];
    selected_provider_id: string | null;
    entries: Entry[];
    filters: Record<string, string>;
}) {
    const { t } = useLocale();
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('provider.portal.ledger.index'), { date_from: dateFrom, date_to: dateTo }, { preserveState: true });
    }

    const totalCredit = entries.reduce((s, e) => s + e.credit, 0);
    const totalDebit  = entries.reduce((s, e) => s + e.debit, 0);
    const balance     = entries.at(-1)?.balance_after ?? 0;

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.ledger')}
            header={<PageHeader title={t('providerPortal.ledger')} />}
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <div className="space-y-5">
                {/* KPI summary */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <KpiCard label={t('providerPortal.credit')}  value={totalCredit.toFixed(2)} color="text-emerald-600" bg="bg-emerald-50 dark:bg-emerald-900/20" />
                    <KpiCard label={t('providerPortal.debit')}   value={totalDebit.toFixed(2)}  color="text-red-600" bg="bg-red-50 dark:bg-red-900/20" />
                    <KpiCard label={t('providerPortal.balance')} value={balance.toFixed(2)}     color="text-[color:var(--color-primary)]" bg="bg-blue-50 dark:bg-blue-900/20" />
                </div>

                {/* Filters */}
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

                {/* Table */}
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.date')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-600 dark:text-slate-400">{t('common.description')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.credit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.debit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.balance')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {entries.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-10 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noRecords')}</td>
                                    </tr>
                                ) : entries.map(e => (
                                    <tr key={e.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={e.entry_date} /></td>
                                        <td className="px-4 py-3 text-gray-900 dark:text-slate-100">{e.description ?? e.entry_type}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-emerald-600">{e.credit > 0 ? e.credit.toFixed(2) : '—'}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-red-600">{e.debit > 0 ? e.debit.toFixed(2) : '—'}</td>
                                        <td className="px-4 py-3 text-right font-bold text-[color:var(--color-primary)]">{e.balance_after.toFixed(2)}</td>
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
