import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight, ScrollText } from '@/Components/Icons';

type Transaction = {
    id: string; transaction_number: string; scanned_at: string | null;
    meal_amount: number; subsidy_amount_applied: number; employee_payable_amount: number; deduction_amount: number;
    status: string; is_extra_scan: boolean;
    employee: { display_name: string; employee_number: string } | null;
    provider: { name_en: string | null; name_am: string | null } | null;
};
type Meta = { current_page: number; last_page: number; total: number; per_page: number };
type Provider = { id: string; name_en: string; name_am?: string | null; code: string };

export default function TransactionsIndex({ transactions, meta, filters, providers, can, summary }: {
    transactions: Transaction[]; meta: Meta; filters: Record<string, string>; providers: Provider[]; can: { scan: boolean; export: boolean };
    summary: { total: number; accepted: number; meals: number; subsidy: number; employee_payable: number; deductions: number };
}) {
    const { t, locale } = useLocale();
    const form = useForm({ provider_id: filters.provider_id ?? '', date: filters.date ?? '', period: filters.period ?? 'monthly', status: filters.status ?? '', extra_only: filters.extra_only === '1' || filters.extra_only === 'true' });
    const inputCls = 'w-full min-w-0 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
    const buttonCls = 'rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';
    const statusLabels: Record<string, string> = { accepted: t('cafeteria.statusAccepted'), rejected: t('cafeteria.transactionRejected'), pending_review: t('cafeteria.transactionPendingReview'), reversed: t('cafeteria.statusReversed') };
    const money = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const start = transactions.length ? (meta.current_page - 1) * meta.per_page + 1 : 0;
    const end = transactions.length ? start + transactions.length - 1 : 0;
    const resultsLabel = t('cafeteria.transactionResults').replace(':from', String(start)).replace(':to', String(end)).replace(':total', String(meta.total));

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('cafeteria.transactions.index'), { ...form.data, extra_only: form.data.extra_only ? '1' : '' }, { preserveState: true, preserveScroll: true });
    }
    function clearFilters() {
        router.get(route('cafeteria.transactions.index'), {}, { preserveScroll: true });
    }
    function goToPage(page: number) {
        router.get(route('cafeteria.transactions.index'), { ...filters, page }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.transactions')} description={t('cafeteria.transactionDescription')} actions={can.scan ? (
            <Link href={route('cafeteria.scan')} className="inline-flex items-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)]">{t('cafeteria.scanQr')}<ChevronRight className="h-4 w-4" aria-hidden="true" /></Link>
        ) : undefined} />}>
            <Head title={t('cafeteria.transactions')} />
            <div className="space-y-6">
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" aria-labelledby="transaction-filters">
                    <h2 id="transaction-filters" className="font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.transactionFilters')}</h2>
                    <form className="mt-4 space-y-4" onSubmit={submit}>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <label className="space-y-2 text-xs font-medium text-gray-600 dark:text-slate-300"><span>{t('cafeteria.statementPeriod')}</span><select className={inputCls} value={form.data.period} onChange={event => form.setData('period', event.target.value)}>{['daily', 'weekly', 'monthly', 'yearly', 'all'].map(period => <option key={period} value={period}>{t(`cafeteria.statementPeriods.${period}`)}</option>)}</select></label>
                            <label className="space-y-2 text-xs font-medium text-gray-600 dark:text-slate-300"><span>{t('cafeteria.provider')}</span>
                                <select className={inputCls} value={form.data.provider_id} onChange={event => form.setData('provider_id', event.target.value)}><option value="">{t('common.all')}</option>{providers.map(provider => <option key={provider.id} value={provider.id}>{provider.code} — {locale === 'am' ? provider.name_am || provider.name_en : provider.name_en}</option>)}</select>
                            </label>
                            <div className="space-y-2 text-xs font-medium text-gray-600 dark:text-slate-300"><label htmlFor="transaction-date">{t('cafeteria.statementAnchor')}</label><LocalizedDatePicker id="transaction-date" className={inputCls} value={form.data.date} disabled={form.data.period === 'all'} onChange={value => form.setData('date', value)} /></div>
                            <label className="space-y-2 text-xs font-medium text-gray-600 dark:text-slate-300"><span>{t('cafeteria.transactionStatus')}</span>
                                <select className={inputCls} value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="">{t('common.all')}</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                            </label>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-4 border-t border-gray-100 pt-4 dark:border-slate-800">
                            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300"><input type="checkbox" className="rounded border-gray-300" checked={form.data.extra_only} onChange={event => form.setData('extra_only', event.target.checked)} />{t('cafeteria.extraScansOnly')}</label>
                            <div className="flex gap-2"><button type="button" onClick={clearFilters} className={buttonCls}>{t('common.clear')}</button><button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button></div>
                        </div>
                    </form>
                </section>
                <section className="rounded-panel border border-blue-200 bg-blue-50/40 p-5 dark:border-blue-900/40 dark:bg-slate-900">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div><h2 className="font-semibold text-gray-900 dark:text-white">{t('cafeteria.statementTitle')}</h2><p className="mt-1 text-sm text-gray-600 dark:text-slate-300">{filters.start_date ? <><LocalizedDateDisplay value={filters.start_date} /> — <LocalizedDateDisplay value={filters.end_date} /></> : t('cafeteria.statementPeriods.all')}</p></div>
                        {can.export && <div className="flex flex-wrap gap-2">{(['pdf', 'xlsx', 'print'] as const).map(format => <a key={format} href={route('cafeteria.transactions.export', { ...filters, format, locale })} target={format === 'print' ? '_blank' : undefined} rel="noopener noreferrer" className={buttonCls}>{t(`cafeteria.statementExport.${format}`)}</a>)}</div>}
                    </div>
                    <dl className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">{[
                        { label: 'mealAmount', value: summary.meals }, { label: 'statementClaim', value: summary.subsidy },
                        { label: 'employeePayable', value: summary.employee_payable }, { label: 'deductionAmount', value: summary.deductions },
                    ].map(item => <div key={item.label} className="rounded-lg bg-white p-3 dark:bg-slate-950"><dt className="text-xs text-gray-500 dark:text-slate-400">{t(`cafeteria.${item.label}`)}</dt><dd className="mt-2 break-all text-xl font-semibold tabular-nums text-gray-900 dark:text-white">{money.format(item.value)}</dd></div>)}</dl>
                    <p className="mt-4 text-xs leading-5 text-gray-600 dark:text-slate-400">{t('cafeteria.statementNote')}</p>
                </section>
                <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-slate-800">
                        <div className="flex items-center gap-3"><ScrollText className="h-5 w-5 text-[color:var(--color-primary)]" aria-hidden="true" /><h2 className="font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.transactions')}</h2><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-gray-600 dark:bg-slate-800 dark:text-slate-300">{meta.total}</span></div>
                        <p className="text-xs text-gray-500 dark:text-slate-400">{resultsLabel}</p>
                    </div>
                    {transactions.length === 0 ? <div className="p-8"><EmptyState title={t('common.noResults')} /></div> : (
                        <div className="divide-y divide-gray-100 dark:divide-slate-800">
                            {transactions.map(txn => (
                                <article key={txn.id} className="p-5 transition hover:bg-gray-50/60 dark:hover:bg-slate-800/20">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <Link href={route('cafeteria.transactions.show', txn.id)} className="break-all font-mono text-sm font-semibold text-[color:var(--color-primary)] hover:underline">{txn.transaction_number}</Link>
                                        <div className="flex flex-wrap items-center gap-2"><StatusBadge status={txn.status} label={statusLabels[txn.status] ?? txn.status} />{txn.is_extra_scan && <span className="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 dark:bg-orange-900/20 dark:text-orange-300">{t('cafeteria.extraScanBadge')}</span>}</div>
                                    </div>
                                    <div className="mt-4 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="min-w-0"><p className="text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.employeeInfo')}</p><p className="mt-1 break-words text-sm font-medium text-gray-900 dark:text-slate-100">{txn.employee?.display_name || '—'}</p><p className="mt-1 font-mono text-xs text-gray-500 dark:text-slate-400">{txn.employee?.employee_number || '—'}</p></div>
                                            <div className="min-w-0"><p className="text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.provider')}</p><p className="mt-1 break-words text-sm font-medium text-gray-900 dark:text-slate-100">{(locale === 'am' ? txn.provider?.name_am || txn.provider?.name_en : txn.provider?.name_en) || '—'}</p><p className="mt-1 text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={txn.scanned_at} withTime /></p></div>
                                        </div>
                                        <dl className="grid grid-cols-2 gap-4 rounded-xl border border-gray-100 bg-gray-50 p-4 dark:border-slate-800 dark:bg-slate-950/60 sm:grid-cols-4">
                                            {[
                                                { label: 'mealAmount', value: txn.meal_amount, color: 'text-gray-900 dark:text-slate-100' },
                                                { label: 'subsidyApplied', value: txn.subsidy_amount_applied, color: 'text-emerald-700 dark:text-emerald-400' },
                                                { label: 'employeePayable', value: txn.employee_payable_amount, color: 'text-gray-900 dark:text-slate-100' },
                                                { label: 'deductionAmount', value: txn.deduction_amount, color: 'text-orange-700 dark:text-orange-400' },
                                            ].map(amount => <div key={amount.label}><dt className="text-xs leading-5 text-gray-500 dark:text-slate-400">{t(`cafeteria.${amount.label}`)}</dt><dd className={`mt-1 break-all text-sm font-semibold tabular-nums ${amount.color}`}>{money.format(amount.value)}</dd></div>)}
                                        </dl>
                                    </div>
                                    <div className="mt-3 flex justify-end"><Link href={route('cafeteria.transactions.show', txn.id)} aria-label={`${t('common.view')} ${txn.transaction_number}`} className="inline-flex items-center gap-1 py-2 text-xs font-semibold text-[color:var(--color-primary)] hover:underline">{t('common.view')}<ChevronRight className="h-4 w-4" aria-hidden="true" /></Link></div>
                                </article>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 text-sm dark:border-slate-800 dark:bg-slate-950/40">
                        <p className="text-gray-500 dark:text-slate-400">{t('cafeteria.transactionPage').replace(':current', String(meta.current_page)).replace(':last', String(meta.last_page))}</p>
                        <nav aria-label={t('cafeteria.transactionPagination')} className="flex gap-2"><button type="button" className={buttonCls} disabled={meta.current_page <= 1} onClick={() => goToPage(meta.current_page - 1)}>{t('common.previous')}</button><button type="button" className={buttonCls} disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.current_page + 1)}>{t('common.next')}</button></nav>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
