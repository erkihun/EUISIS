import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Entry = {
    id: string;
    employee_number: string;
    employee_name: string | null;
    organization_code: string | null;
    entry_type: string;
    amount: string | number;
    balance_after: string | number;
    entry_date: string;
    description: string | null;
};

const inputCls =
    'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Ledger({
    entries,
    filters,
    balance,
}: {
    entries: { data: Entry[] };
    filters: Record<string, string>;
    balance: number | null;
}) {
    const { t } = useLocale();
    const [employeeNumber, setEmployeeNumber] = useState(filters.employee_number ?? '');

    function search() {
        router.get('/ledger', { employee_number: employeeNumber }, { preserveState: true });
    }

    return (
        <AppLayout title={t('ledger.title')}>
            <div className="mb-4 flex flex-wrap items-end gap-2">
                <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600">{t('extra.employeeNumber')}</label>
                    <input
                        className={inputCls}
                        value={employeeNumber}
                        onChange={(e) => setEmployeeNumber(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && search()}
                        placeholder={t('extra.employeeNumber')}
                    />
                </div>
                <button
                    type="button"
                    onClick={search}
                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                >
                    Search
                </button>

                {balance !== null && (
                    <div className="ml-auto rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-right">
                        <p className="text-xs text-emerald-700">{t('extra.balance')}</p>
                        <p className="text-lg font-bold tabular-nums text-emerald-800">{Number(balance).toFixed(2)}</p>
                    </div>
                )}
            </div>

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.date')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.employee')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.type')}</th>
                            <th className="px-4 py-2 text-right font-medium text-slate-600">{t('common.amount')}</th>
                            <th className="px-4 py-2 text-right font-medium text-slate-600">{t('extra.balance')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.description')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {entries.data.length === 0 ? (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">{t('ledger.noEntries')}</td></tr>
                        ) : entries.data.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2 text-xs text-slate-500">{row.entry_date}</td>
                                <td className="px-4 py-2">
                                    <span className="font-medium text-slate-900">{row.employee_name ?? '-'}</span>
                                    <span className="block font-mono text-xs text-slate-400">{row.employee_number}</span>
                                </td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                        row.entry_type === 'credit'
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-amber-100 text-amber-700'
                                    }`}>{row.entry_type}</span>
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums text-slate-800">{Number(row.amount).toFixed(2)}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-slate-600">{Number(row.balance_after).toFixed(2)}</td>
                                <td className="px-4 py-2 text-xs text-slate-500">{row.description ?? '-'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
