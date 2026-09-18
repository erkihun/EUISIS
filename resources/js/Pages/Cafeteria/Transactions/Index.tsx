import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type Transaction = {
    id: string;
    transaction_number: string;
    employee_id: string;
    cafeteria_provider_id: string;
    transaction_date: string;
    scanned_at: string;
    meal_amount: number;
    subsidy_amount_applied: number;
    deduction_amount: number;
    status: string;
    status_color: string;
    is_extra_scan: boolean;
    is_holiday: boolean;
    is_working_day: boolean;
    can: { reverse: boolean };
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };
type Provider = { id: string; name_en: string; code: string };

export default function TransactionsIndex({
    transactions,
    meta,
    filters,
    providers,
    can,
}: {
    transactions: Transaction[];
    meta: Meta;
    filters: Record<string, string>;
    providers: Provider[];
    can: { scan: boolean };
}) {
    const { t } = useLocale();
    const [date, setDate] = useState(filters.date ?? '');

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    const statusLabel = (s: string) => ({
        accepted:   t('cafeteria.statusAccepted'),
        reversed:   t('cafeteria.statusReversed'),
        allowed:    t('cafeteria.statusAllowed'),
        denied:     t('cafeteria.statusDenied'),
        extra_scan: t('cafeteria.statusExtraScan'),
    } as Record<string, string>)[s] ?? s;

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        const params = Object.fromEntries(fd) as Record<string, string>;
        params.date = date;
        router.get(route('cafeteria.transactions.index'), params, { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.transactions')}
                    actions={
                        can.scan ? (
                            <Link
                                href={route('cafeteria.scan')}
                                className="inline-flex items-center rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                {t('cafeteria.scanQr')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('cafeteria.transactions')} />

            <div className="space-y-4">
                <form className="flex flex-wrap gap-3" onSubmit={submit}>
                    <select name="provider_id" defaultValue={filters.provider_id ?? ''} className={inputCls}>
                        <option value="">{t('cafeteria.provider')} — {t('common.all')}</option>
                        {providers.map((p) => (
                            <option key={p.id} value={p.id}>{p.name_en}</option>
                        ))}
                    </select>
                    <LocalizedDatePicker className={inputCls} value={date} onChange={iso => setDate(iso)} />
                    <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                        <option value="">{t('cafeteria.transactionStatus')} — {t('common.all')}</option>
                        <option value="accepted">{t('cafeteria.statusAccepted')}</option>
                        <option value="reversed">{t('cafeteria.statusReversed')}</option>
                    </select>
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                        {t('common.filter')}
                    </button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {transactions.length === 0 ? (
                        <EmptyState title={t('common.noResults')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.transactionNumber')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.scanTime')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.subsidyApplied')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.deductionAmount')}</th>
                                        <th className="px-4 py-3 text-center font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.extraScan')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.transactionStatus')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {transactions.map((txn) => (
                                        <tr key={txn.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">{txn.transaction_number}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={txn.scanned_at} withTime /></td>
                                            <td className="px-4 py-3 text-right font-medium text-emerald-600">{txn.subsidy_amount_applied.toFixed(2)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-orange-600">{txn.deduction_amount > 0 ? txn.deduction_amount.toFixed(2) : '—'}</td>
                                            <td className="px-4 py-3 text-center">
                                                {txn.is_extra_scan ? <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">{t('cafeteria.extraScanBadge')}</span> : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={txn.status} label={statusLabel(txn.status)} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={route('cafeteria.transactions.show', txn.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">
                                                    {t('common.view')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {meta.last_page > 1 && (
                    <div className="flex justify-center gap-2 text-sm">
                        {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
                            <button
                                key={page}
                                onClick={() => router.get(route('cafeteria.transactions.index'), { ...filters, page })}
                                className={`rounded px-3 py-1 ${meta.current_page === page ? 'bg-[color:var(--color-primary)] text-white' : 'border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400'}`}
                            >
                                {page}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
