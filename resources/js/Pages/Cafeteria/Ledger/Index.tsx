import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type LedgerEntry = {
    id: string;
    employee_id: string;
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
}: {
    entries: LedgerEntry[];
    meta: Meta;
    filters: Record<string, string>;
    employee: { id: string; full_name: string; employee_number: string } | null;
    balance: number | null;
}) {
    const { t } = useLocale();
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        const params = Object.fromEntries(fd) as Record<string, string>;
        params.date_from = dateFrom;
        params.date_to = dateTo;
        router.get(route('cafeteria.ledger.index'), params, { preserveState: true });
    }

    const entryTypeLabel = (s: string) => ({
        allocation:               t('cafeteria.allocation'),
        usage:                    t('cafeteria.usage'),
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
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.ledger')} />}>
            <Head title={t('cafeteria.ledger')} />

            <div className="space-y-4">
                {employee && balance !== null && (
                    <div className={`rounded-card border p-5 ${balance < 0 ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/30' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'}`}>
                        <p className="text-sm font-medium text-gray-600 dark:text-slate-400">{employee.full_name} — {t('cafeteria.ledgerBalance')}</p>
                        <p className={`mt-1 text-3xl font-bold ${balance < 0 ? 'text-orange-600' : 'text-emerald-600'}`}>
                            {balance.toFixed(2)} ETB
                        </p>
                        {balance < 0 && (
                            <p className="mt-1 text-xs text-orange-600">{t('cafeteria.pendingDeduction')}: {Math.abs(balance).toFixed(2)} ETB</p>
                        )}
                    </div>
                )}

                <form className="flex flex-wrap gap-3" onSubmit={submit}>
                    <input name="employee_id" defaultValue={filters.employee_id ?? ''} placeholder={t('employees.employeeId')} className={inputCls} />
                    <LocalizedDatePicker className={inputCls} value={dateFrom} onChange={iso => setDateFrom(iso)} />
                    <LocalizedDatePicker className={inputCls} value={dateTo} onChange={iso => setDateTo(iso)} />
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                        {t('common.filter')}
                    </button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {entries.length === 0 ? (
                        <EmptyState title={t('common.noResults')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
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
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={entry.ledger_date} /></td>
                                            <td className={`px-4 py-3 font-medium ${entryTypeColor(entry.entry_type)}`}>{entryTypeLabel(entry.entry_type)}</td>
                                            <td className={`px-4 py-3 text-right font-medium ${entry.amount >= 0 ? 'text-emerald-600' : 'text-orange-600'}`}>
                                                {entry.amount >= 0 ? '+' : ''}{entry.amount.toFixed(2)}
                                            </td>
                                            <td className={`px-4 py-3 text-right font-bold ${entry.balance_after < 0 ? 'text-orange-600' : 'text-gray-900 dark:text-slate-100'}`}>
                                                {entry.balance_after.toFixed(2)}
                                            </td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{entry.description ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
