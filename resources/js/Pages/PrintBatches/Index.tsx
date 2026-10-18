import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type BatchRow = {
    id: string;
    batch_number: string;
    status: string;
    total_cards: number;
    printed_count: number;
    printed_at?: string | null;
    created_at?: string | null;
    created_by?: { name: string } | null;
};

type Filters = { search: string; status: string };

type PageProps = {
    batches: LaravelPageMeta & { data: BatchRow[] };
    filters: Filters;
    can?: { create?: boolean };
};

type BatchStatus = 'draft' | 'printing' | 'completed' | 'cancelled';
const BATCH_STATUSES: BatchStatus[] = ['draft', 'printing', 'completed', 'cancelled'];

type StyleEntry = { badge: string; dot: string };

const batchStatusStyle: Record<BatchStatus, StyleEntry> = {
    draft:      { badge: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40', dot: 'bg-amber-400' },
    printing:   { badge: 'bg-blue-50 text-blue-800 ring-1 ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40', dot: 'bg-blue-400' },
    completed:  { badge: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40', dot: 'bg-emerald-400' },
    cancelled:  { badge: 'bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40', dot: 'bg-red-400' },
};

const fallbackStyle: StyleEntry = {
    badge: 'bg-(--app-surface-muted) text-(--app-muted-foreground) ring-1 ring-(--app-border-strong)',
    dot:   'bg-gray-400',
};

function BatchStatusBadge({ status }: { status: string }) {
    const { t } = useLocale();
    const style = batchStatusStyle[status as BatchStatus] ?? fallbackStyle;
    const label = t(`idCards.batchStatus_${status}`) || status.replace(/_/g, ' ');
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${style.badge}`}>
            <span className={`h-1.5 w-1.5 rounded-full shrink-0 ${style.dot}`} aria-hidden />
            {label}
        </span>
    );
}

export default function PrintBatchesIndex({ batches, filters, can }: PageProps) {
    const { t } = useLocale();
    const [values, setValues] = useState<Filters>(filters);
    const filtersActive = Object.values(filters).some(Boolean);

    function submitFilters() {
        router.get(route('print-batches.index'), { ...values, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        const empty: Filters = { search: '', status: '' };
        setValues(empty);
        router.get(route('print-batches.index'), empty, { preserveScroll: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('idCards.printBatches')}
                    description=""
                    actions={
                        can?.create && (
                            <Link
                                href={route('print-batches.create')}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                {t('idCards.createPrintBatch')}
                            </Link>
                        )
                    }
                />
            }
        >
            <Head title={t('idCards.printBatches')} />

            <FilterPanel onSubmit={submitFilters} onReset={resetFilters} hasActiveFilters={filtersActive} className="mb-4">
                <FilterField label={t('common.search')} wide>
                    <Input
                        type="search"
                        value={values.search}
                        onChange={(event) => setValues({ ...values, search: event.target.value })}
                        placeholder={t('idCards.batchNumber')}
                    />
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}>
                        <option value="">{t('common.allStatuses')}</option>
                        {BATCH_STATUSES.map((status) => (
                            <option key={status} value={status}>{t(`idCards.batchStatus_${status}`)}</option>
                        ))}
                    </Select>
                </FilterField>
            </FilterPanel>

            <div className={tableContainerClassName}>
                {batches.data.length === 0 ? (
                    <EmptyState
                        title={t('idCards.noPrintBatchesFound')}
                        description={t('idCards.noBatchesDescription')}
                        action={
                            can?.create ? (
                                <Link
                                    href={route('print-batches.create')}
                                    className={buttonClassName({ variant: 'primary' })}
                                >
                                    {t('idCards.createPrintBatch')}
                                </Link>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.batchNumber')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.batchStatus')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.cardsInBatch')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.printedAt')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('common.createdAt')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {batches.data.map((batch) => (
                                    <tr
                                        key={batch.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <span className="font-mono text-sm font-semibold text-(--app-foreground)">
                                                {batch.batch_number}
                                            </span>
                                            {batch.created_by && (
                                                <p className="text-xs text-(--app-muted-foreground)">
                                                    {batch.created_by.name}
                                                </p>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <BatchStatusBadge status={batch.status} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-(--app-foreground)">
                                                    {batch.printed_count}
                                                </span>
                                                <span className="text-(--app-muted-foreground)">/</span>
                                                <span className="text-(--app-muted-foreground)">
                                                    {batch.total_cards}
                                                </span>
                                            </div>
                                            {/* Progress bar */}
                                            <div className="mt-1 h-1.5 w-20 rounded-full bg-(--app-surface-muted)">
                                                <div
                                                    className="h-1.5 rounded-full bg-blue-500 transition-all"
                                                    style={{ width: `${batch.total_cards > 0 ? (batch.printed_count / batch.total_cards) * 100 : 0}%` }}
                                                />
                                            </div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={batch.printed_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={batch.created_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('print-batches.show', batch.id) },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <ListPagination meta={batches} />
            </div>
        </AuthenticatedLayout>
    );
}
