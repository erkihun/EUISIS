import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import SearchPicker from '@/Components/fieldWork/SearchPicker';

import { FilterField, FilterPanel, ListPagination, Select, cx, filterCaptionClassName, filterFieldClassName, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type EmployeeOption = { id: string; full_name: string; employee_number: string };

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
    entryTypes,
    summary,
}: {
    entries: LedgerEntry[];
    meta: Meta;
    filters: Record<string, string>;
    employee: { id: string; full_name: string; employee_number: string } | null;
    balance: number | null;
    entryTypes: string[];
    summary: { credits: number; debits: number; net: number };
}) {
    const { t, locale } = useLocale();
    const { errors } = usePage().props;
    const money = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const [loading, setLoading] = useState(false);
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    // Searched on the server through lookup.employees (SCL-10).
    const [picked, setPicked] = useState<EmployeeOption | null>(employee);

    const dateCls = cx(inputClassName, 'w-full');
    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit(e: FormEvent<HTMLFormElement>) {
        const fd = new FormData(e.currentTarget);
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
        return 'text-[color:var(--app-muted-foreground)]';
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.ledger')} description={t('cafeteria.ledgerDescription')} />}>
            <Head title={t('cafeteria.ledger')} />

            <div className="w-full space-y-6" aria-busy={loading}>
                <FilterPanel
                    label={t('cafeteria.ledgerFilters')}
                    onSubmit={submit}
                    onReset={() => { setPicked(null); navigate({}, true); }}
                    hasActiveFilters={hasActiveFilters}
                    processing={loading}
                    footer={Object.keys(errors).length > 0 ? Object.entries(errors).map(([key, error]) => <p key={key} role="alert" className="text-red-600 dark:text-red-400">{error}</p>) : undefined}
                >
                    <div className={filterFieldClassName(true)}>
                        <input type="hidden" name="employee_id" value={picked?.id ?? ''} />
                        {picked ? (
                            <>
                                <span className={filterCaptionClassName}>{t('cafeteria.employeeInfo')}</span>
                                <div className="flex h-[var(--control-h-md)] items-center justify-between gap-2 rounded-[var(--radius-control)] border border-[color:var(--app-border-strong)] px-3 text-sm">
                                    <span className="min-w-0 truncate text-[color:var(--app-foreground)]">{picked.employee_number} · {picked.full_name}</span>
                                    <button type="button" className="text-xs font-medium text-[color:var(--color-primary)] hover:underline" onClick={() => setPicked(null)}>{t('common.change')}</button>
                                </div>
                            </>
                        ) : (
                            <SearchPicker<EmployeeOption>
                                url={route('lookup.employees')}
                                label={t('cafeteria.employeeInfo')}
                                placeholder={t('common.searchEmployee')}
                                render={(person) => `${person.employee_number} · ${person.full_name}`}
                                onPick={setPicked}
                            />
                        )}
                    </div>
                    <FilterField label={t('cafeteria.entryType')}>
                        <Select name="entry_type" defaultValue={filters.entry_type ?? ''}>
                            <option value="">{t('common.all')}</option>
                            {entryTypes.map(type => <option key={type} value={type}>{entryTypeLabel(type)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cafeteria.ledgerDateFrom')} htmlFor="ledger-from">
                        <LocalizedDatePicker id="ledger-from" className={dateCls} value={dateFrom} onChange={setDateFrom} />
                    </FilterField>
                    <FilterField label={t('cafeteria.ledgerDateTo')} htmlFor="ledger-to">
                        <LocalizedDatePicker id="ledger-to" className={dateCls} value={dateTo} min={dateFrom || undefined} onChange={setDateTo} />
                    </FilterField>
                </FilterPanel>
                <section aria-label={t('cafeteria.ledgerSummary')}>
                    <div className="grid gap-4 sm:grid-cols-3">{(['credits', 'debits', 'net'] as const).map(key => <div key={key} className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5"><p className="text-sm text-[color:var(--app-muted-foreground)]">{t('cafeteria.ledger_' + key)}</p><p className="mt-2 text-2xl font-semibold tabular-nums text-[color:var(--app-foreground)]">{money.format(summary[key])} <span className="text-xs font-normal">ETB</span></p></div>)}</div>
                    <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{t('cafeteria.ledgerSummaryHelp')}</p>
                </section>
                {employee && balance !== null && (
                    <div className={`rounded-card border p-5 ${balance < 0 ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950/30' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'}`}>
                        <p className="text-sm font-medium text-[color:var(--app-muted-foreground)]">{employee.full_name} — {t('cafeteria.ledgerBalance')}</p>
                        <p className={`mt-1 text-3xl font-bold ${balance < 0 ? 'text-orange-600' : 'text-emerald-600'}`}>
                            {money.format(balance)} ETB
                        </p>
                        {balance < 0 && (
                            <p className="mt-1 text-xs text-orange-600">{t('cafeteria.pendingDeduction')}: {Math.abs(balance).toFixed(2)} ETB</p>
                        )}
                    </div>
                )}

                <div className={tableContainerClassName}>
                    <div className="border-b border-[color:var(--app-border)] px-4 py-3"><h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.ledger')}</h2></div>
                    {entries.length === 0 ? (
                        <EmptyState description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.employeeInfo')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('common.date')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.entryType')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('common.amount')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.balanceAfter')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('common.description')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {entries.map((entry) => (
                                        <tr key={entry.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'min-w-48')}><p className="font-medium text-[color:var(--app-foreground)]">{entry.employee?.full_name ?? '—'}</p><p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{entry.employee?.employee_number}</p></td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={entry.ledger_date} /></td>
                                            <td className={cx(tableCellClassName, 'font-medium', entryTypeColor(entry.entry_type))}>{entryTypeLabel(entry.entry_type)}</td>
                                            <td className={cx(tableCellClassName, 'text-right font-medium tabular-nums', entry.amount >= 0 ? 'text-emerald-600' : 'text-orange-600')}>
                                                {entry.amount >= 0 ? '+' : ''}{money.format(entry.amount)}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-right font-bold tabular-nums', entry.balance_after < 0 ? 'text-orange-600' : 'text-[color:var(--app-foreground)]')}>
                                                {money.format(entry.balance_after)}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{entry.description ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <ListPagination meta={meta} label={t('cafeteria.ledgerPages')} disabled={loading} onPageChange={(page) => navigate({ ...filters, page })} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
