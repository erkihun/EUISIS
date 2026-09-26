import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type LedgerEntry = {
    id: string;
    employee_id: string;
    employee: { full_name: string; employee_number: string } | null;
    ledger_date: string;
    entry_type: string;
    amount: number;
    balance_after: number;
    working_day: boolean;
    description: string | null;
    created_at: string;
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function LedgerIndex({
    entries,
    meta,
    filters,
    employee,
    balance,
    employees,
    entryTypes,
    summary,
}: {
    entries: LedgerEntry[];
    meta: Meta;
    filters: Record<string, string>;
    employee: { id: string; full_name: string; employee_number: string } | null;
    balance: number | null;
    employees: { id: string; full_name: string; employee_number: string }[];
    entryTypes: string[];
    summary: { credits: number; debits: number; net: number };
}) {
    const { t, locale } = useLocale();
    const { errors } = usePage().props;
    const money = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const [loading, setLoading] = useState(false);
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        const params = Object.fromEntries(fd) as Record<string, string>;
        params.date_from = dateFrom;
        params.date_to = dateTo;
        navigate(params);
    }

    function navigate(params: Record<string, string | number>, reset = false) {
        router.get(route('cafeteria.ledger.index'), params, { preserveState: !reset, preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    }

    const entryTypeLabel = (s: string) => ({
        allocation:               t('cafeteria.allocation'),
        usage:                    t('cafeteria.usage'),
        extra_usage:              t('cafeteria.ledgerExtraUsage'),
        carry_forward_deduction:  t('cafeteria.carryForwardDeduction'),
        adjustment:               t('cafeteria.adjustment'),
        reversal:                 t('cafeteria.reversal'),
    } as Record<string, string>)[s] ?? s;

    function entryTypeColor(type: string) {
        if (type === 'allocation') return 'text-emerald-600';
        if (type === 'carry_forward_deduction') return 'text-orange-600';
        if (type === 'reversal') return 'text-[color:var(--color-primary)]';
        return 'text-gray-600 dark:text-slate-400';
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.ledger')} description={t('cafeteria.ledgerDescription')} />}>
            <Head title={t('cafeteria.ledger')} />

            <div className="w-full space-y-6" aria-busy={loading}>
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.ledgerFilters')}</h2>
                    <form className="mt-4 space-y-4" onSubmit={submit}>
                        <fieldset disabled={loading} className="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <label className="space-y-2 text-sm text-gray-600 dark:text-slate-300"><span>{t('cafeteria.employeeInfo')}</span><select name="employee_id" defaultValue={filters.employee_id ?? ''} className={inputCls}><option value="">{t('common.all')}</option>{employees.map(person => <option key={person.id} value={person.id}>{person.employee_number} · {person.full_name}</option>)}</select></label>
                            <label className="space-y-2 text-sm text-gray-600 dark:text-slate-300"><span>{t('cafeteria.entryType')}</span><select name="entry_type" defaultValue={filters.entry_type ?? ''} className={inputCls}><option value="">{t('common.all')}</option>{entryTypes.map(type => <option key={type} value={type}>{entryTypeLabel(type)}</option>)}</select></label>
                            <div className="space-y-2 text-sm text-gray-600 dark:text-slate-300"><label htmlFor="ledger-from">{t('cafeteria.ledgerDateFrom')}</label><LocalizedDatePicker id="ledger-from" className={inputCls} value={dateFrom} onChange={setDateFrom} /></div>
                            <div className="space-y-2 text-sm text-gray-600 dark:text-slate-300"><label htmlFor="ledger-to">{t('cafeteria.ledgerDateTo')}</label><LocalizedDatePicker id="ledger-to" className={inputCls} value={dateTo} min={dateFrom || undefined} onChange={setDateTo} /></div>
                        </fieldset>
                        {Object.entries(errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600 dark:text-red-400">{error}</p>)}
                        <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-slate-800"><button type="button" disabled={loading} onClick={() => navigate({}, true)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-slate-700 dark:text-slate-200">{t('common.clear')}</button><button type="submit" disabled={loading} className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('common.filter')}</button></div>
                    </form>
                </section>
                <section aria-label={t('cafeteria.ledgerSummary')}>
                    <div className="grid gap-4 sm:grid-cols-3">{(['credits', 'debits', 'net'] as const).map(key => <div key={key} className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><p className="text-sm text-gray-500 dark:text-slate-400">{t('cafeteria.ledger_' + key)}</p><p className="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-slate-100">{money.format(summary[key])} <span className="text-xs font-normal">ETB</span></p></div>)}</div>
                    <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.ledgerSummaryHelp')}</p>
                </section>
                {employee && balance !== null && (
                    <div className={`rounded-card border p-5 ${balance < 0 ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/30' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'}`}>
                        <p className="text-sm font-medium text-gray-600 dark:text-slate-400">{employee.full_name} — {t('cafeteria.ledgerBalance')}</p>
                        <p className={`mt-1 text-3xl font-bold ${balance < 0 ? 'text-orange-600' : 'text-emerald-600'}`}>
                            {money.format(balance)} ETB
                        </p>
                        {balance < 0 && (
                            <p className="mt-1 text-xs text-orange-600">{t('cafeteria.pendingDeduction')}: {Math.abs(balance).toFixed(2)} ETB</p>
                        )}
                    </div>
                )}

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-slate-800"><h2 className="font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.ledger')}</h2><p className="text-sm text-gray-500 dark:text-slate-400">{t('cafeteria.transactionResults').replace(':from', String(entries.length ? (meta.current_page - 1) * meta.per_page + 1 : 0)).replace(':to', String(entries.length ? (meta.current_page - 1) * meta.per_page + entries.length : 0)).replace(':total', String(meta.total))}</p></div>
                    {entries.length === 0 ? (
                        <EmptyState title={t('common.noResults')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.employeeInfo')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('common.date')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.entryType')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('common.amount')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.balanceAfter')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('common.description')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {entries.map((entry) => (
                                        <tr key={entry.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="min-w-48 px-4 py-3"><p className="font-medium text-gray-900 dark:text-slate-100">{entry.employee?.full_name ?? '—'}</p><p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{entry.employee?.employee_number}</p></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={entry.ledger_date} /></td>
                                            <td className={`px-4 py-3 font-medium ${entryTypeColor(entry.entry_type)}`}>{entryTypeLabel(entry.entry_type)}</td>
                                            <td className={`px-4 py-3 text-right font-medium ${entry.amount >= 0 ? 'text-emerald-600' : 'text-orange-600'}`}>
                                                {entry.amount >= 0 ? '+' : ''}{money.format(entry.amount)}
                                            </td>
                                            <td className={`px-4 py-3 text-right font-bold ${entry.balance_after < 0 ? 'text-orange-600' : 'text-gray-900 dark:text-slate-100'}`}>
                                                {money.format(entry.balance_after)}
                                            </td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{entry.description ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <nav aria-label={t('cafeteria.ledgerPages')} className="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 dark:border-slate-800"><button type="button" disabled={loading || meta.current_page <= 1} onClick={() => navigate({ ...filters, page: meta.current_page - 1 })} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-40 dark:border-slate-700 dark:text-slate-200">{t('common.previous')}</button><span className="text-sm tabular-nums text-gray-500 dark:text-slate-400">{meta.current_page} / {meta.last_page}</span><button type="button" disabled={loading || meta.current_page >= meta.last_page} onClick={() => navigate({ ...filters, page: meta.current_page + 1 })} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-40 dark:border-slate-700 dark:text-slate-200">{t('common.next')}</button></nav>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
