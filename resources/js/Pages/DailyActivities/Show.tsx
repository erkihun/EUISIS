import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ItemsReadOnly from '@/Components/dailyActivity/ItemsReadOnly';
import { LateMark, LogStatusBadge } from '@/Components/dailyActivity/StatusBadges';
import { formatBytes, inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';
import type { LogDetail } from '@/Components/dailyActivity/types';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type Props = {
    log: LogDetail;
    can: { approve: boolean; return: boolean; reopen: boolean };
};

/**
 * One employee-day, for a reviewer or scoped oversight.
 *
 * A reviewer never edits the employee's words: the only actions are approve,
 * return with a comment (optionally pinned to single activities), and, for
 * approved days, a reasoned reopen. Buttons follow server abilities and every
 * endpoint re-authorises.
 */
export default function DailyActivitiesShow({ log, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [mode, setMode] = useState<'approve' | 'return' | null>(null);
    const [openHistory, setOpenHistory] = useState<string | null>(null);
    const form = useForm<{ comment: string; item_notes: Record<string, string> }>({ comment: '', item_notes: {} });

    function decide(action: 'approve' | 'return') {
        form.post(route(action === 'approve' ? 'daily-activities.approve' : 'daily-activities.return', log.id), { preserveScroll: true });
    }

    async function reopen() {
        const { confirmed, reason } = await confirm({
            title: t('dailyActivities.actions.reopen'),
            description: t('dailyActivities.show.reopenHint'),
            confirmLabel: t('dailyActivities.actions.reopen'),
            cancelLabel: t('dailyActivities.actions.cancel'),
            variant: 'warning',
            requireReason: true,
            reasonLabel: t('dailyActivities.fields.reason'),
        });
        if (confirmed && reason) router.post(route('daily-activities.reopen', log.id), { reason }, { preserveScroll: true });
    }

    const meta: [string, JSX.Element | string][] = [
        [t('dailyActivities.show.employee'), `${log.employee?.full_name ?? '—'} (${log.employee?.employee_number ?? ''})`],
        [t('dailyActivities.show.placement'), [log.organization, log.organization_unit, log.position].map((p) => named(p, locale)).filter(Boolean).join(' · ') || '—'],
        [t('dailyActivities.columns.submittedAt'), <LocalizedDateDisplay key="s" value={log.submitted_at} withTime />],
        [t('dailyActivities.show.firstSubmitted'), <LocalizedDateDisplay key="f" value={log.first_submitted_at} withTime />],
        [t('dailyActivities.show.submissions'), String(log.submission_count)],
    ];
    if (log.late_reason) meta.push([t('dailyActivities.show.lateReason'), log.late_reason]);
    if (log.reviewer) meta.push([t('dailyActivities.columns.reviewer'), log.reviewer]);
    if (log.review_comment) meta.push([t('dailyActivities.show.reviewComment'), log.review_comment]);

    const anyAction = can.approve || can.return || can.reopen;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.show.title')} backHref={route('daily-activities.index')} />}>
            <Head title={t('dailyActivities.show.title')} />
            <div className="mx-auto max-w-4xl space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <LocalizedDateDisplay value={log.activity_date} className="text-base font-semibold text-gray-900 dark:text-slate-100" />
                    <LogStatusBadge status={log.status} />
                    {log.is_late && <LateMark />}
                </div>

                <dl className={`${panelCls} grid gap-x-4 gap-y-1.5 p-4 text-sm sm:grid-cols-[12rem_1fr]`}>
                    {meta.map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-gray-500 dark:text-slate-400">{label}</dt>
                            <dd className="whitespace-pre-line break-words text-gray-900 dark:text-slate-100">{value}</dd>
                        </div>
                    ))}
                </dl>

                <section className={`${panelCls} px-4`}>
                    <h2 className="border-b border-gray-100 py-2 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                        {t('dailyActivities.columns.activities')} ({log.items.length})
                    </h2>
                    <ItemsReadOnly items={log.items} />
                </section>

                {log.attachments.length > 0 && (
                    <section className={`${panelCls} p-4`}>
                        <h2 className="mb-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.evidence.title')}</h2>
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                            {log.attachments.map((file) => (
                                <li key={file.id} className="flex items-center justify-between gap-2 py-1.5">
                                    <a href={file.download_url} className="min-w-0 truncate text-[color:var(--color-primary)] hover:underline">{file.original_name}</a>
                                    <span className="shrink-0 text-xs text-gray-500 dark:text-slate-400">{formatBytes(file.file_size)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* Decision */}
                <section className={`${panelCls} p-4`}>
                    <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.show.review')}</h2>
                    {!anyAction && <p className="text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.show.noActions')}</p>}

                    {(can.approve || can.return) && mode === null && (
                        <div className="flex flex-wrap gap-2">
                            {can.approve && <button type="button" onClick={() => setMode('approve')} className={primaryBtn}>{t('dailyActivities.actions.approve')}</button>}
                            {can.return && <button type="button" onClick={() => setMode('return')} className={secondaryBtn}>{t('dailyActivities.actions.return')}</button>}
                        </div>
                    )}

                    {mode !== null && (
                        <form onSubmit={(e) => { e.preventDefault(); decide(mode); }} className="space-y-3">
                            <div>
                                <label htmlFor="review-comment" className={labelCls}>
                                    {t('dailyActivities.fields.comment')}{mode === 'return' && <span className="text-red-600"> *</span>}
                                </label>
                                <textarea id="review-comment" rows={3} className={inputCls} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} />
                                {mode === 'return' && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.show.returnHint')}</p>}
                                {form.errors.comment && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{form.errors.comment}</p>}
                            </div>

                            {mode === 'return' && log.items.length > 1 && (
                                <div className="space-y-2">
                                    {log.items.map((item, index) => item.id && (
                                        <div key={item.id}>
                                            <label htmlFor={`note-${item.id}`} className={labelCls}>
                                                {index + 1}. {item.title} — {t('dailyActivities.show.itemNote')}
                                            </label>
                                            <input
                                                id={`note-${item.id}`}
                                                className={inputCls}
                                                maxLength={1000}
                                                value={form.data.item_notes[item.id] ?? ''}
                                                onChange={(e) => form.setData('item_notes', { ...form.data.item_notes, [item.id as string]: e.target.value })}
                                            />
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <button type="submit" disabled={form.processing} className={primaryBtn}>
                                    {t(mode === 'approve' ? 'dailyActivities.actions.approve' : 'dailyActivities.actions.return')}
                                </button>
                                <button type="button" onClick={() => { setMode(null); form.clearErrors(); }} className={secondaryBtn}>{t('dailyActivities.actions.cancel')}</button>
                            </div>
                        </form>
                    )}

                    {can.reopen && (
                        <div className="mt-3 border-t border-gray-100 pt-3 dark:border-slate-800">
                            <p className="mb-2 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.show.reopenHint')}</p>
                            <button type="button" onClick={reopen} className={secondaryBtn}>{t('dailyActivities.actions.reopen')}</button>
                        </div>
                    )}
                </section>

                {/* Append-only history, including every version that was submitted. */}
                <section className={`${panelCls} p-4`}>
                    <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.show.history')}</h2>
                    <ol className="space-y-2 border-s border-gray-200 ps-4 dark:border-slate-700">
                        {log.history.map((entry) => (
                            <li key={entry.id} className="text-sm">
                                <p className="text-gray-900 dark:text-slate-100">
                                    <span className="font-medium">{t(`dailyActivities.historyActions.${entry.action}`)}</span>
                                    {entry.actor && <span className="text-gray-600 dark:text-slate-400"> · {entry.actor}</span>}
                                    <span className="text-xs text-gray-500 dark:text-slate-400"> · <LocalizedDateDisplay value={entry.created_at} withTime /></span>
                                </p>
                                {entry.comment && <p className="whitespace-pre-line text-gray-700 dark:text-slate-300">{entry.comment}</p>}
                                {entry.items && entry.items.length > 0 && (
                                    <>
                                        <button type="button" onClick={() => setOpenHistory(openHistory === entry.id ? null : entry.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:underline" aria-expanded={openHistory === entry.id}>
                                            {t('dailyActivities.show.submittedVersion')} ({entry.items.length})
                                        </button>
                                        {openHistory === entry.id && (
                                            <div className="mt-1 rounded-control bg-gray-50 px-3 dark:bg-slate-800/50">
                                                <ItemsReadOnly items={entry.items} showReviewerNotes={false} />
                                            </div>
                                        )}
                                    </>
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
