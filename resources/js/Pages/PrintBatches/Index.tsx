import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

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

type PageProps = {
    batches: {
        data: BatchRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    can?: { create?: boolean };
};

type BatchStatus = 'draft' | 'printing' | 'completed' | 'cancelled';

type StyleEntry = { badge: string; dot: string };

const batchStatusStyle: Record<BatchStatus, StyleEntry> = {
    draft:      { badge: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40', dot: 'bg-amber-400' },
    printing:   { badge: 'bg-blue-50 text-blue-800 ring-1 ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',       dot: 'bg-blue-400' },
    completed:  { badge: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40', dot: 'bg-emerald-400' },
    cancelled:  { badge: 'bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40',             dot: 'bg-red-400' },
};

const fallbackStyle: StyleEntry = {
    badge: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
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

export default function PrintBatchesIndex({ batches, can }: PageProps) {
    const { t } = useLocale();

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
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
                            >
                                {t('idCards.createPrintBatch')}
                            </Link>
                        )
                    }
                />
            }
        >
            <Head title={t('idCards.printBatches')} />

            <div className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {batches.data.length === 0 ? (
                    <div className="p-8">
                        <EmptyState
                            title={t('idCards.noPrintBatchesFound')}
                            description={t('idCards.noBatchesDescription')}
                            action={
                                can?.create ? (
                                    <Link
                                        href={route('print-batches.create')}
                                        className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                    >
                                        {t('idCards.createPrintBatch')}
                                    </Link>
                                ) : undefined
                            }
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800/50">
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.batchNumber')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.batchStatus')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.cardsInBatch')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.printedAt')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.createdAt')}
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {batches.data.map((batch) => (
                                    <tr
                                        key={batch.id}
                                        className="transition-colors hover:bg-gray-50 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-sm font-semibold text-gray-900 dark:text-slate-100">
                                                {batch.batch_number}
                                            </span>
                                            {batch.created_by && (
                                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                                    {batch.created_by.name}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <BatchStatusBadge status={batch.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-gray-900 dark:text-slate-100">
                                                    {batch.printed_count}
                                                </span>
                                                <span className="text-gray-400">/</span>
                                                <span className="text-gray-600 dark:text-slate-400">
                                                    {batch.total_cards}
                                                </span>
                                            </div>
                                            {/* Progress bar */}
                                            <div className="mt-1 h-1.5 w-20 rounded-full bg-gray-100 dark:bg-slate-700">
                                                <div
                                                    className="h-1.5 rounded-full bg-blue-500 transition-all"
                                                    style={{ width: `${batch.total_cards > 0 ? (batch.printed_count / batch.total_cards) * 100 : 0}%` }}
                                                />
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={batch.printed_at} withTime />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={batch.created_at} withTime />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={route('print-batches.show', batch.id)}
                                                className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                            >
                                                {t('common.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {batches.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-slate-800 dark:text-slate-500">
                        <span>
                            {t('common.page')} {batches.current_page} {t('common.of')} {batches.last_page}
                        </span>
                        <span>{batches.total} {t('common.total').toLowerCase()}</span>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
