import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight } from '@/Components/Icons';

import { FilterField, FilterPanel, ListPagination, Select, badgeClassName, buttonClassName, cx, hasFilterQuery, inputClassName, tableContainerClassName } from '@/Components/ui';
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
    const dateCls = cx(inputClassName, 'w-full');
    // The statement always echoes a period and date, so only the URL says what the viewer chose.
    const hasActiveFilters = hasFilterQuery();
    const statusLabels: Record<string, string> = { accepted: t('cafeteria.statusAccepted'), rejected: t('cafeteria.transactionRejected'), pending_review: t('cafeteria.transactionPendingReview'), reversed: t('cafeteria.statusReversed') };
    const money = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function submit() {
        router.get(route('cafeteria.transactions.index'), { ...form.data, extra_only: form.data.extra_only ? '1' : '' }, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        router.get(route('cafeteria.transactions.index'), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.transactions')} description={t('cafeteria.transactionDescription')} actions={can.scan ? (
            <Link href={route('cafeteria.scan')} className={buttonClassName({ variant: 'primary' })}>{t('cafeteria.scanQr')}<ChevronRight className="h-4 w-4" aria-hidden="true" /></Link>
        ) : undefined} />}>
            <Head title={t('cafeteria.transactions')} />
            <div className="space-y-6">
                <FilterPanel label={t('cafeteria.transactionFilters')} onSubmit={submit} onReset={clearFilters} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('cafeteria.statementPeriod')}>
                        <Select value={form.data.period} onChange={event => form.setData('period', event.target.value)}>{['daily', 'weekly', 'monthly', 'yearly', 'all'].map(period => <option key={period} value={period}>{t(`cafeteria.statementPeriods.${period}`)}</option>)}</Select>
                    </FilterField>
                    <FilterField label={t('cafeteria.provider')}>
                        <Select value={form.data.provider_id} onChange={event => form.setData('provider_id', event.target.value)}><option value="">{t('common.all')}</option>{providers.map(provider => <option key={provider.id} value={provider.id}>{provider.code} — {locale === 'am' ? provider.name_am || provider.name_en : provider.name_en}</option>)}</Select>
                    </FilterField>
                    <FilterField label={t('cafeteria.statementAnchor')} htmlFor="transaction-date">
                        <LocalizedDatePicker id="transaction-date" className={dateCls} value={form.data.date} disabled={form.data.period === 'all'} onChange={value => form.setData('date', value)} />
                    </FilterField>
                    <FilterField label={t('cafeteria.transactionStatus')}>
                        <Select value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="">{t('common.all')}</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select>
                    </FilterField>
                    <label className="flex h-[var(--control-h-md)] items-center gap-2 text-sm text-[color:var(--app-foreground)]"><input type="checkbox" className="rounded border-[color:var(--app-border-strong)]" checked={form.data.extra_only} onChange={event => form.setData('extra_only', event.target.checked)} />{t('cafeteria.extraScansOnly')}</label>
                </FilterPanel>
                <section className="rounded-panel border border-blue-200 bg-blue-50/40 p-5 dark:border-blue-900/40 dark:bg-slate-900">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div><h2 className="font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.statementTitle')}</h2><p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{filters.start_date ? <><LocalizedDateDisplay value={filters.start_date} /> — <LocalizedDateDisplay value={filters.end_date} /></> : t('cafeteria.statementPeriods.all')}</p></div>
                        {can.export && <div className="flex flex-wrap gap-2">{(['pdf', 'xlsx', 'print'] as const).map(format => <a key={format} href={route('cafeteria.transactions.export', { ...filters, format, locale })} target={format === 'print' ? '_blank' : undefined} rel="noopener noreferrer" className={buttonClassName({ variant: 'outline' })}>{t(`cafeteria.statementExport.${format}`)}</a>)}</div>}
                    </div>
                    <dl className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">{[
                        { label: 'mealAmount', value: summary.meals }, { label: 'statementClaim', value: summary.subsidy },
                        { label: 'employeePayable', value: summary.employee_payable }, { label: 'deductionAmount', value: summary.deductions },
                    ].map(item => <div key={item.label} className="rounded-lg bg-[color:var(--app-surface)] p-3"><dt className="text-xs text-[color:var(--app-muted-foreground)]">{t(`cafeteria.${item.label}`)}</dt><dd className="mt-2 break-all text-xl font-semibold tabular-nums text-[color:var(--app-foreground)]">{money.format(item.value)}</dd></div>)}</dl>
                    <p className="mt-4 text-xs leading-5 text-[color:var(--app-muted-foreground)]">{t('cafeteria.statementNote')}</p>
                </section>
                <section className={tableContainerClassName}>
                    <div className="border-b border-[color:var(--app-border)] px-4 py-3">
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.transactions')}</h2>
                    </div>
                    {transactions.length === 0 ? <EmptyState description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} /> : (
                        <div className="divide-y divide-[color:var(--app-border)]">
                            {transactions.map(txn => (
                                <article key={txn.id} className="p-5 transition hover:bg-gray-50/60 dark:hover:bg-slate-800/20">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <Link href={route('cafeteria.transactions.show', txn.id)} className="break-all font-mono text-sm font-semibold text-[color:var(--color-primary)] hover:underline">{txn.transaction_number}</Link>
                                        <div className="flex flex-wrap items-center gap-2"><StatusBadge status={txn.status} label={statusLabels[txn.status] ?? txn.status} />{txn.is_extra_scan && <span className={badgeClassName('warning')}>{t('cafeteria.extraScanBadge')}</span>}</div>
                                    </div>
                                    <div className="mt-4 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="min-w-0"><p className="text-xs text-[color:var(--app-muted-foreground)]">{t('cafeteria.employeeInfo')}</p><p className="mt-1 break-words text-sm font-medium text-[color:var(--app-foreground)]">{txn.employee?.display_name || '—'}</p><p className="mt-1 font-mono text-xs text-[color:var(--app-muted-foreground)]">{txn.employee?.employee_number || '—'}</p></div>
                                            <div className="min-w-0"><p className="text-xs text-[color:var(--app-muted-foreground)]">{t('cafeteria.provider')}</p><p className="mt-1 break-words text-sm font-medium text-[color:var(--app-foreground)]">{(locale === 'am' ? txn.provider?.name_am || txn.provider?.name_en : txn.provider?.name_en) || '—'}</p><p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]"><LocalizedDateDisplay value={txn.scanned_at} withTime /></p></div>
                                        </div>
                                        <dl className="grid grid-cols-2 gap-4 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] p-4 sm:grid-cols-4">
                                            {[
                                                { label: 'mealAmount', value: txn.meal_amount, color: 'text-[color:var(--app-foreground)]' },
                                                { label: 'subsidyApplied', value: txn.subsidy_amount_applied, color: 'text-emerald-700 dark:text-emerald-400' },
                                                { label: 'employeePayable', value: txn.employee_payable_amount, color: 'text-[color:var(--app-foreground)]' },
                                                { label: 'deductionAmount', value: txn.deduction_amount, color: 'text-orange-700 dark:text-orange-400' },
                                            ].map(amount => <div key={amount.label}><dt className="text-xs leading-5 text-[color:var(--app-muted-foreground)]">{t(`cafeteria.${amount.label}`)}</dt><dd className={`mt-1 break-all text-sm font-semibold tabular-nums ${amount.color}`}>{money.format(amount.value)}</dd></div>)}
                                        </dl>
                                    </div>
                                    <div className="mt-3 flex justify-end"><Link href={route('cafeteria.transactions.show', txn.id)} aria-label={`${t('common.view')} ${txn.transaction_number}`} className="inline-flex items-center gap-1 py-2 text-xs font-semibold text-[color:var(--color-primary)] hover:underline">{t('common.view')}<ChevronRight className="h-4 w-4" aria-hidden="true" /></Link></div>
                                </article>
                            ))}
                        </div>
                    )}
                    <ListPagination meta={meta} label={t('cafeteria.transactionPagination')} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
