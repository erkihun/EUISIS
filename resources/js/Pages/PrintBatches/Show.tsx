import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type BatchItem = {
    id: string;
    status: string;
    spoiled: boolean;
    reprint_reason?: string | null;
    card?: {
        id: string;
        card_number: string;
        status: string;
        employee?: { employee_number: string; full_name: string } | null;
    } | null;
};

type BatchData = {
    id: string;
    batch_number: string;
    status: string;
    total_cards: number;
    printed_count: number;
    spoiled_count: number;
    printer_notes?: string | null;
    printed_at?: string | null;
    created_at?: string | null;
    created_by?: { name: string } | null;
    printed_by?: { name: string } | null;
    items?: BatchItem[];
};

type PageProps = {
    batch: BatchData;
    can: { markPrinted?: boolean };
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
    badge: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',
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

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

export default function PrintBatchShow({ batch, can }: PageProps) {
    const { t } = useLocale();
    const markForm = useForm({ printer_notes: '' });

    const printProgress = batch.total_cards > 0
        ? Math.round((batch.printed_count / batch.total_cards) * 100)
        : 0;

    const inputCls = 'rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('print-batches.index')}
                    title={batch.batch_number}
                    description={`${batch.total_cards} ${t('idCards.cardsInBatch').toLowerCase()}`}
                />
            }
        >
            <Head title={batch.batch_number} />

            <div className="space-y-5">
                {/* Summary card */}
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-start justify-between gap-6">
                        <div className="flex-1">
                            <div className="flex items-center gap-3 mb-4">
                                <BatchStatusBadge status={batch.status} />
                                <span className="font-mono text-sm text-gray-500 dark:text-slate-400">
                                    {batch.batch_number}
                                </span>
                            </div>

                            <dl className="grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-4">
                                <Field label={t('common.total')}>
                                    <span className="text-lg font-bold text-gray-900 dark:text-slate-100">
                                        {batch.total_cards}
                                    </span>
                                </Field>
                                <Field label={t('idCards.printedCards')}>
                                    <span className="text-lg font-bold text-emerald-600">
                                        {batch.printed_count}
                                    </span>
                                </Field>
                                <Field label={t('idCards.spoiledCount')}>
                                    <span className={`text-lg font-bold ${batch.spoiled_count > 0 ? 'text-red-600' : 'text-gray-400'}`}>
                                        {batch.spoiled_count}
                                    </span>
                                </Field>
                                <Field label={t('idCards.printProgress')}>
                                    <span className="text-lg font-bold text-blue-600">{printProgress}%</span>
                                </Field>
                            </dl>

                            {/* Progress bar */}
                            <div className="mt-4">
                                <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-slate-700">
                                    <div
                                        className="h-2 rounded-full bg-blue-500 transition-all"
                                        style={{ width: `${printProgress}%` }}
                                    />
                                </div>
                            </div>

                            <dl className="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3 text-sm">
                                {batch.created_by && (
                                    <Field label={t('common.createdAt')}>
                                        {batch.created_by.name}
                                        {batch.created_at && (
                                            <span className="ml-1 text-xs text-gray-400 dark:text-slate-500">
                                                · <LocalizedDateDisplay value={batch.created_at} withTime />
                                            </span>
                                        )}
                                    </Field>
                                )}
                                {batch.printed_by && (
                                    <Field label={t('idCards.printedBy')}>
                                        {batch.printed_by.name}
                                        {batch.printed_at && (
                                            <span className="ml-1 text-xs text-gray-400 dark:text-slate-500">
                                                · <LocalizedDateDisplay value={batch.printed_at} withTime />
                                            </span>
                                        )}
                                    </Field>
                                )}
                                {batch.printer_notes && (
                                    <div className="col-span-full">
                                        <Field label={t('idCards.printerNotes')}>
                                            {batch.printer_notes}
                                        </Field>
                                    </div>
                                )}
                            </dl>
                        </div>

                        {/* Mark as printed action */}
                        {can.markPrinted && batch.status !== 'completed' && (
                            <div className="flex flex-col gap-3 min-w-[200px]">
                                <label className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                    {t('idCards.printerNotes')}
                                </label>
                                <input
                                    className={inputCls}
                                    placeholder={t('idCards.printerNotesPlaceholder')}
                                    value={markForm.data.printer_notes}
                                    onChange={(e) => markForm.setData('printer_notes', e.target.value)}
                                />
                                <button
                                    type="button"
                                    disabled={markForm.processing}
                                    onClick={() => markForm.post(route('print-batches.mark-printed', batch.id))}
                                    className="flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60 transition-colors"
                                >
                                    <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5z" />
                                    </svg>
                                    {t('idCards.markAsPrinted')}
                                </button>
                            </div>
                        )}
                    </div>
                </section>

                {/* Cards list */}
                <section className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
                            {t('idCards.cardsInBatch')}
                        </h3>
                        <span className="text-xs text-gray-400 dark:text-slate-500">
                            {batch.items?.length ?? 0}
                        </span>
                    </div>
                    <div className="divide-y divide-gray-100 dark:divide-slate-800">
                        {(batch.items ?? []).length === 0 ? (
                            <div className="px-5 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                                {t('idCards.noCardsInBatch')}
                            </div>
                        ) : (
                            (batch.items ?? []).map((item) => (
                                <div
                                    key={item.id}
                                    className={`flex items-center gap-4 px-5 py-3 ${item.spoiled ? 'bg-red-50/50 dark:bg-red-900/5' : ''}`}
                                >
                                    <div className="min-w-0 flex-1">
                                        {item.card ? (
                                            <>
                                                <Link
                                                    href={route('id-cards.show', item.card.id)}
                                                    className="font-mono text-sm font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {item.card.card_number}
                                                </Link>
                                                <p className="text-xs text-gray-400 dark:text-slate-500">
                                                    {item.card.employee?.employee_number}
                                                    {item.card.employee?.full_name && (
                                                        <>
                                                            <span className="mx-1.5">·</span>
                                                            {item.card.employee.full_name}
                                                        </>
                                                    )}
                                                </p>
                                            </>
                                        ) : (
                                            <span className="text-sm text-gray-400 dark:text-slate-500">—</span>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3 shrink-0">
                                        {item.card && <CardStatusBadge status={item.card.status} />}
                                        {item.spoiled ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40">
                                                {t('idCards.spoiled')}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-gray-400 dark:text-slate-500 capitalize">
                                                {item.status}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
