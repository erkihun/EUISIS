import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { DayStatusBadge, LateMark, LogStatusBadge } from '@/Components/dailyActivity/StatusBadges';
import ItemsReadOnly from '@/Components/dailyActivity/ItemsReadOnly';
import {
    dangerBtn, fill, formatBytes, inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn,
} from '@/Components/dailyActivity/helpers';
import type { ActivityItem, EmployeeRef, LogDetail, Named, Option } from '@/Components/dailyActivity/types';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Plus, TrashIcon } from '@/Components/Icons';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type JSX } from 'react';

type Props = {
    employee: EmployeeRef;
    today: string;
    date?: string;
    day_status?: string;
    placement?: { organization: Named; organization_unit: Named; position: Named } | null;
    log?: LogDetail | null;
    tasks?: Option[];
    kpis?: (Option & { unit?: string | null })[];
    options?: { categories: string[]; progress_statuses: string[] };
    rules?: {
        enabled: boolean;
        submission_deadline: string;
        require_late_reason: boolean;
        require_output_result: boolean;
        evidence_attachments_enabled: boolean;
        max_attachment_size_kb: number;
        would_be_late: boolean;
        earliest_date: string;
    };
    can?: { edit: boolean; submit: boolean; upload: boolean };
    week?: Record<string, number>;
    today_status?: string;
    recent?: { date: string; status: string; is_late: boolean; items_count: number }[];
};

type EditableItem = ActivityItem & { key: string; open: boolean };

// React keys only. Not crypto.randomUUID(): it is missing on plain-HTTP
// intranet deployments, which are not a secure context.
let keySeed = 0;
const nextKey = (): string => `new-${++keySeed}`;

const blankItem = (): EditableItem => ({
    key: nextKey(),
    open: false,
    activity_category: null,
    position_service_id: null,
    title: '',
    description: '',
    output_result: '',
    progress_status: 'completed',
    started_at: null,
    ended_at: null,
    duration_minutes: null,
    quantity: null,
    unit_of_measure: null,
    challenge_issue: null,
    next_action: null,
});

const toEditable = (item: ActivityItem): EditableItem => ({
    ...item,
    key: item.id ?? nextKey(),
    // Keep optional details open when the employee already filled some.
    open: Boolean(item.started_at || item.ended_at || item.quantity || item.challenge_issue || item.next_action),
});

/**
 * My Work > Daily Activity.
 *
 * Built for a phone first: one column, full-width controls, the two actions
 * pinned to the bottom of the screen. Each activity is an inline block with
 * the four required fields visible and everything optional one tap away,
 * so a typical day is entered without opening any dialog.
 */
export default function DailyActivityEntry(props: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();

    if (!props.employee || !props.date || !props.rules || !props.can || !props.options) {
        return (
            <PortalPage title={t('dailyActivities.title')}>
                <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{t('dailyActivities.noEmployee')}</p>
            </PortalPage>
        );
    }

    const { date, log, rules, can, options } = props;
    const tasks = props.tasks ?? [];
    const kpis = props.kpis ?? [];

    return (
        <PortalPage title={t('dailyActivities.title')} description={t('dailyActivities.subtitle')}>
            <EntryBody
                // Remount after each save so the form picks up server item ids
                // (otherwise the next save would recreate every item).
                key={`${date}-${log?.id ?? 'new'}-${log?.status ?? ''}-${log?.items.map((item) => item.id).join(',') ?? ''}`}
                {...props}
                date={date}
                log={log ?? null}
                rules={rules}
                can={can}
                options={options}
                tasks={tasks}
                kpis={kpis}
                t={t}
                locale={locale}
                confirm={confirm}
            />
        </PortalPage>
    );
}

type BodyProps = Props & {
    date: string;
    log: LogDetail | null;
    rules: NonNullable<Props['rules']>;
    can: NonNullable<Props['can']>;
    options: NonNullable<Props['options']>;
    tasks: Option[];
    kpis: Option[];
    t: (key: string) => string;
    locale: string;
    confirm: ReturnType<typeof useConfirm>['confirm'];
};

function EntryBody({ date, today, day_status, placement, log, tasks, kpis, options, rules, can, week, today_status, recent, t, locale, confirm }: BodyProps) {
    const form = useForm<{ items: EditableItem[]; late_reason: string; action: 'draft' | 'submit' }>({
        items: log?.items.length ? log.items.map(toEditable) : (can.edit ? [blankItem()] : []),
        late_reason: log?.late_reason ?? '',
        action: 'draft',
    });
    const [uploading, setUploading] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const signature = JSON.stringify({
        items: form.data.items.map(({ open: _open, key: _key, ...item }) => item),
        late_reason: form.data.late_reason,
    });
    const [savedSignature, setSavedSignature] = useState(signature);
    const dirty = can.edit && signature !== savedSignature;
    const busy = form.processing || uploading || removing || confirming;
    const readyCount = form.data.items.filter((item) => item.title.trim() && item.description?.trim() && (!rules.require_output_result || item.output_result?.trim())).length;
    const step = log?.status === 'approved' ? 3 : log && !can.edit ? 2 : 1;
    const allowNavigation = useRef(false);
    const navigationPrompt = useRef(false);

    useEffect(() => {
        if (!dirty) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        const off = router.on('before', (event) => {
            if (event.detail.visit.method !== 'get') return;
            if (allowNavigation.current) {
                allowNavigation.current = false;
                return;
            }
            event.preventDefault();
            if (navigationPrompt.current) return;
            navigationPrompt.current = true;
            const visit = event.detail.visit;
            void confirm({
                title: t('dailyActivities.entry.unsaved'),
                description: t('dailyActivities.entry.leaveConfirm'),
                confirmLabel: t('dailyActivities.actions.confirm'),
                cancelLabel: t('dailyActivities.actions.cancel'),
                variant: 'warning',
            }).then(({ confirmed }) => {
                navigationPrompt.current = false;
                if (confirmed) {
                    allowNavigation.current = true;
                    router.visit(visit.url, visit);
                }
            });
        });
        window.addEventListener('beforeunload', unload);
        return () => { off(); window.removeEventListener('beforeunload', unload); };
    }, [dirty, t, confirm]);
    const fileInput = useRef<HTMLInputElement>(null);
    const errorSummary = useRef<HTMLUListElement>(null);
    // Upload and delete go through router, so their errors arrive as page props.
    const uploadError = (usePage().props.errors as Record<string, string | undefined>).file;

    const errors = form.errors as Record<string, string | undefined>;
    const isReturned = log?.status === 'returned_for_correction';
    const showLateReason = rules.would_be_late && can.submit && !isReturned;

    function update(index: number, patch: Partial<EditableItem>) {
        form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    }

    async function removeItem(index: number) {
        const item = form.data.items[index];
        if (item.id || item.title || item.description || item.output_result || item.started_at || item.ended_at || item.quantity || item.unit_of_measure || item.challenge_issue || item.next_action || item.position_service_id || item.activity_category) {
            const { confirmed } = await confirm({
                title: t('dailyActivities.entry.remove'),
                description: t('dailyActivities.entry.removeConfirm'),
                confirmLabel: t('dailyActivities.entry.remove'),
                cancelLabel: t('dailyActivities.actions.cancel'),
                variant: 'danger',
            });
            if (!confirmed) return;
        }
        form.setData('items', form.data.items.filter((_, i) => i !== index));
        form.clearErrors();
    }

    function send(action: 'draft' | 'submit') {
        if (form.processing || uploading || removing) return;
        form.transform((data) => ({
            action,
            late_reason: data.late_reason,
            items: data.items.map(({ key: _key, open: _open, reviewer_note: _note, position_service: _service, ...item }) => item),
        }));
        form.post(route('employee.daily-activity.save', date), {
            preserveScroll: true,
            onSuccess: () => setSavedSignature(signature),
            onError: () => requestAnimationFrame(() => errorSummary.current?.focus()),
        });
    }

    async function submit() {
        if (busy) return;
        setConfirming(true);
        const { confirmed } = await confirm({
            title: t(isReturned ? 'dailyActivities.actions.resubmit' : 'dailyActivities.actions.submit'),
            description: t('dailyActivities.entry.submitConfirm'),
            confirmLabel: t('dailyActivities.actions.confirm'),
            cancelLabel: t('dailyActivities.actions.cancel'),
        });
        setConfirming(false);
        if (confirmed) send('submit');
    }

    function upload(file: File | undefined) {
        if (!file || !log || busy) return;
        setUploading(true);
        router.post(route('employee.daily-activity.attachments.store', log.id), { file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setUploading(false);
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    }

    async function removeAttachment(id: string) {
        const { confirmed } = await confirm({
            title: t('dailyActivities.evidence.delete'),
            description: t('dailyActivities.evidence.deleteConfirm'),
            confirmLabel: t('dailyActivities.evidence.delete'),
            cancelLabel: t('dailyActivities.actions.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            setRemoving(true);
            router.delete(route('employee.daily-activity.attachments.destroy', id), { preserveScroll: true, onFinish: () => setRemoving(false) });
        }
    }

    const placementText = placement
        ? [placement.organization, placement.organization_unit, placement.position].map((p) => named(p, locale)).filter(Boolean).join(' · ')
        : '';

    return (
        <div className="mx-auto max-w-5xl space-y-6 pb-28 sm:pb-6">
            {/* Heading: the date is the subject of the page. */}
            <header className={`${panelCls} space-y-5 p-5 shadow-sm sm:p-6`}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p className="flex flex-wrap items-center gap-2 text-base text-gray-900 dark:text-slate-100">
                            <LocalizedDateDisplay value={date} className="font-medium" />
                            {log ? <LogStatusBadge status={log.status} /> : day_status && <DayStatusBadge status={day_status} />}
                            {log?.is_late && <LateMark />}
                        </p>
                    </div>
                    <label className="w-full sm:w-44">
                        <span className={labelCls}>{t('dailyActivities.entry.changeDate')}</span>
                        <LocalizedDatePicker
                            value={date}
                            max={today}
                            disabled={busy}
                            onChange={(value) => value && router.get(route('employee.daily-activity.entry'), { date: value })}
                        />
                    </label>
                </div>
                {placementText && (
                    <p className="text-xs text-gray-500 dark:text-slate-400">
                        {t('dailyActivities.entry.placement')}: {placementText}
                    </p>
                )}
                {week && (
                    <p className="flex flex-wrap gap-x-4 gap-y-1 border-y border-gray-200 py-2 text-xs text-gray-600 dark:border-slate-800 dark:text-slate-400">
                        <span>
                            {t('dailyActivities.entry.today')}:{' '}
                            {today_status && <strong className="text-gray-900 dark:text-slate-100">{t(`dailyActivities.dayStatuses.${today_status}`)}</strong>}
                        </span>
                        <span>
                            {t('dailyActivities.entry.thisWeek')}:{' '}
                            {t('dailyActivities.summary.required')} <strong className="text-gray-900 dark:text-slate-100">{week.required ?? 0}</strong>
                            {' · '}{t('dailyActivities.summary.submitted')} <strong className="text-gray-900 dark:text-slate-100">{week.submitted ?? 0}</strong>
                            {' · '}{t('dailyActivities.summary.missing')} <strong className={(week.missing ?? 0) > 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-900 dark:text-slate-100'}>{week.missing ?? 0}</strong>
                        </span>
                    </p>
                )}
            </header>

            <ol aria-label={t('dailyActivities.entry.workflow')} className="grid gap-3 sm:grid-cols-3">
                {['draftStep', 'submitStep', 'reviewStep'].map((name, index) => (
                    <li key={name} aria-current={step === index + 1 ? 'step' : undefined} className={`${panelCls} flex gap-3 p-4 ${step === index + 1 ? 'ring-2 ring-[color:var(--color-primary)]' : ''}`}>
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-700 dark:bg-slate-800 dark:text-slate-200">{index + 1}</span>
                        <div><p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t(`dailyActivities.entry.${name}`)}</p><p className="mt-1 text-xs leading-relaxed text-gray-500 dark:text-slate-400">{t(`dailyActivities.entry.${name}Hint`)}</p></div>
                    </li>
                ))}
            </ol>

            {/* Reviewer feedback comes first when the day was sent back. */}
            {isReturned && log?.review_comment && (
                <section className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/30" role="status">
                    <p className="font-semibold text-amber-900 dark:text-amber-200">
                        {t(log.reopened_at ? 'dailyActivities.entry.reopenedTitle' : 'dailyActivities.entry.returnedTitle')}
                    </p>
                    <p className="mt-1 whitespace-pre-line text-amber-900 dark:text-amber-100">{log.review_comment}</p>
                    <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                        {fill(t('dailyActivities.entry.returnedBy'), { name: (log.reopened_by ?? log.reviewer) ?? '—' })}
                        {' · '}
                        <LocalizedDateDisplay value={log.reopened_at ?? log.reviewed_at} withTime />
                    </p>
                </section>
            )}

            {!log && !can.edit && (
                <p className={`${panelCls} p-3 text-sm text-gray-600 dark:text-slate-300`}>
                    {t('dailyActivities.entry.notRegistrable')}
                    {day_status && <> ({t(`dailyActivities.dayStatuses.${day_status}`)})</>}
                </p>
            )}

            {errors.date && <p className="rounded-panel border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{errors.date}</p>}
            {errors.status && <p className="rounded-panel border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{errors.status}</p>}

            {/* Read-only view once submitted or approved. */}
            {log && !can.edit && (
                <section className={`${panelCls} px-4`}>
                    <p className="border-b border-gray-100 py-2 text-xs text-gray-500 dark:border-slate-800 dark:text-slate-400">
                        {fill(t('dailyActivities.entry.readOnly'), { status: t(`dailyActivities.statuses.${log.status}`) })}
                    </p>
                    <ItemsReadOnly items={log.items} />
                </section>
            )}

            {can.edit && (
                <form onSubmit={(e) => { e.preventDefault(); send('draft'); }} className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-2 py-2">
                        <div><h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.entry.yourActivities')}</h2><p className="mt-1 text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.entry.requiredHint')}</p></div>
                        <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">{fill(t('dailyActivities.entry.readyCount'), { ready: readyCount, total: form.data.items.length })}</span>
                    </div>
                    {Object.keys(errors).length > 0 && (
                        <ul ref={errorSummary} tabIndex={-1} role="alert" className="list-inside list-disc rounded-panel border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                            {Object.entries(errors).map(([field, message]) => <li key={field}>{message}</li>)}
                        </ul>
                    )}

                    <fieldset disabled={busy} className="min-w-0 space-y-4">
                    {form.data.items.length === 0 && <p className={`${panelCls} p-8 text-center text-sm text-gray-500 dark:text-slate-400`}>{t('dailyActivities.entry.noItems')}</p>}
                    {form.data.items.map((item, index) => (
                        <ItemEditor
                            key={item.key}
                            index={index}
                            item={item}
                            tasks={tasks}
                            kpis={kpis}
                            options={options}
                            requireOutput={rules.require_output_result}
                            errors={errors}
                            onChange={(patch) => update(index, patch)}
                            onRemove={() => removeItem(index)}
                            t={t}
                            locale={locale}
                        />
                    ))}

                    <button type="button" disabled={form.data.items.length >= 30} onClick={() => form.setData('items', [...form.data.items, blankItem()])} className={`${secondaryBtn} w-full border-dashed py-3`}>
                        <Plus className="h-4 w-4" aria-hidden="true" />
                        {t('dailyActivities.entry.addActivity')}
                    </button>
                    {form.data.items.length >= 30 && <p role="status" className="text-xs text-gray-500">{t('dailyActivities.entry.itemLimit')}</p>}

                    {showLateReason && (
                        <div className="rounded-panel border border-amber-300 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                            <p className="mb-2 text-sm text-amber-900 dark:text-amber-200">
                                {fill(t('dailyActivities.entry.lateWarning'), { deadline: rules.submission_deadline })}
                            </p>
                            <label htmlFor="late_reason" className={labelCls}>
                                {t('dailyActivities.fields.lateReason')}{rules.require_late_reason && <span className="text-red-600"> *</span>}
                            </label>
                            <textarea
                                id="late_reason"
                                rows={2}
                                className={inputCls}
                                value={form.data.late_reason}
                                onChange={(e) => form.setData('late_reason', e.target.value)}
                            />
                            {errors.late_reason && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{errors.late_reason}</p>}
                        </div>
                    )}
                    </fieldset>

                    {/* Pinned to the bottom on phones, inline on larger screens. */}
                    <div className="fixed inset-x-0 bottom-0 z-20 flex flex-wrap items-center gap-2 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur sm:sticky sm:bottom-3 sm:rounded-xl sm:border sm:p-4 sm:shadow-lg dark:border-slate-700 dark:bg-slate-950/95">
                        <p role="status" aria-live="polite" className="w-full text-xs text-gray-500 sm:me-auto sm:w-auto dark:text-slate-400">{t(form.processing ? 'dailyActivities.entry.saving' : dirty ? 'dailyActivities.entry.unsaved' : log ? 'dailyActivities.entry.saved' : 'dailyActivities.entry.notSaved')}</p>
                        <button type="submit" disabled={busy} className={`${secondaryBtn} flex-1 sm:flex-none`}>
                            {t('dailyActivities.actions.saveDraft')}
                        </button>
                        {can.submit && (
                            <button type="button" onClick={submit} disabled={busy || form.data.items.length === 0} className={`${primaryBtn} flex-1 sm:flex-none`}>
                                {t(isReturned ? 'dailyActivities.actions.resubmit' : 'dailyActivities.actions.submit')}
                            </button>
                        )}
                    </div>
                </form>
            )}

            {/* Evidence */}
            {rules.evidence_attachments_enabled && (log || can.edit) && (
                <section className={`${panelCls} p-4`}>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.evidence.title')}</h2>
                        {can.upload && (
                            <label className={`${secondaryBtn} min-h-9 cursor-pointer py-1.5`}>
                                {uploading ? t('dailyActivities.evidence.uploading') : t('dailyActivities.evidence.upload')}
                                <input
                                    ref={fileInput}
                                    type="file"
                                    className="sr-only"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt"
                                    disabled={busy}
                                    onChange={(e) => upload(e.target.files?.[0])}
                                />
                            </label>
                        )}
                    </div>
                    {!log && <p className="text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.evidence.saveFirst')}</p>}
                    {log && log.attachments.length === 0 && <p className="text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.evidence.none')}</p>}
                    {log && log.attachments.length > 0 && (
                        <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                            {log.attachments.map((file) => (
                                <li key={file.id} className="flex items-center justify-between gap-2 py-2">
                                    <a href={file.download_url} className="min-w-0 truncate text-[color:var(--color-primary)] hover:underline">
                                        {file.original_name}
                                    </a>
                                    <span className="flex shrink-0 items-center gap-2 text-xs text-gray-500 dark:text-slate-400">
                                        {formatBytes(file.file_size)}
                                        {can.upload && (
                                            <button type="button" disabled={busy} onClick={() => removeAttachment(file.id)} className="rounded p-1 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" aria-label={`${t('dailyActivities.evidence.delete')}: ${file.original_name}`}>
                                                <TrashIcon className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {uploadError && <p className="mt-2 text-sm text-red-700 dark:text-red-400">{uploadError}</p>}
                    {can.upload && (
                        <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">
                            {fill(t('dailyActivities.evidence.allowed'), { size: rules.max_attachment_size_kb })}
                        </p>
                    )}
                </section>
            )}

            {/* Recent days */}
            {log && log.history.length > 0 && (
                <details className={`${panelCls} p-5`}>
                    <summary className="cursor-pointer text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.show.history')} ({log.history.length})</summary>
                    <ol className="mt-4 space-y-4 border-s-2 border-gray-200 ps-4 dark:border-slate-700">
                        {log.history.map((event) => <li key={event.id} className="text-sm">
                            <p className="font-medium text-gray-900 dark:text-slate-100">{t(`dailyActivities.historyActions.${event.action}`)}</p>
                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{event.actor} · <LocalizedDateDisplay value={event.created_at} withTime /></p>
                            {event.comment && <p className="mt-1 whitespace-pre-line text-gray-600 dark:text-slate-300">{event.comment}</p>}
                        </li>)}
                    </ol>
                </details>
            )}
            {recent && recent.length > 0 && (
                <section>
                    <h2 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('dailyActivities.entry.recent')}</h2>
                    <ul className={`${panelCls} divide-y divide-gray-100 text-sm dark:divide-slate-800`}>
                        {recent.map((day) => (
                            <li key={day.date}>
                                <Link href={route('employee.daily-activity.entry', { date: day.date })} className="flex items-center justify-between gap-2 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                    <LocalizedDateDisplay value={day.date} className="text-gray-800 dark:text-slate-200" />
                                    <span className="flex items-center gap-2">
                                        <span className="text-xs text-gray-500 dark:text-slate-400">{day.items_count} {t('dailyActivities.summary.items')}</span>
                                        {day.is_late && <LateMark />}
                                        <LogStatusBadge status={day.status} />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <p className="text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.notAttendance')}</p>
        </div>
    );
}

type EditorProps = {
    index: number;
    item: EditableItem;
    tasks: Option[];
    kpis: Option[];
    options: { categories: string[]; progress_statuses: string[] };
    requireOutput: boolean;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<EditableItem>) => void;
    onRemove: () => void;
    t: (key: string) => string;
    locale: string;
};

function ItemEditor({ index, item, tasks, kpis, options, requireOutput, errors, onChange, onRemove, t, locale }: EditorProps) {
    const id = (field: string) => `item-${index}-${field}`;
    const error = (field: string) => errors[`items.${index}.${field}`];
    const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

    return (
        <fieldset className={`${panelCls} min-w-0 p-4 shadow-sm sm:p-6`}>
            <legend className="sr-only">{t('dailyActivities.entry.activity')} {index + 1}</legend>
            <div className="mb-5 flex items-center justify-between border-b border-gray-100 pb-4 dark:border-slate-800">
                <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('dailyActivities.entry.activity')} {index + 1}
                </span>
                <button type="button" onClick={onRemove} className={`${dangerBtn} min-h-8 px-2.5 py-1 text-xs`}>
                    {t('dailyActivities.entry.remove')}
                </button>
            </div>

            {item.reviewer_note && (
                <p className="mb-2 border-s-2 border-amber-400 ps-2 text-sm text-amber-900 dark:text-amber-200">
                    <span className="font-medium">{t('dailyActivities.entry.reviewerNote')}:</span> {item.reviewer_note}
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor={id('task')} className={labelCls}>{t('dailyActivities.fields.relatedTask')}</label>
                    <select
                        id={id('task')}
                        className={inputCls}
                        value={item.position_service_id ?? ''}
                        onChange={(e) => onChange({ position_service_id: e.target.value || null, activity_category: e.target.value ? null : item.activity_category })}
                    >
                        <option value="">{t('dailyActivities.fields.otherActivity')}</option>
                        {tasks.map((task) => <option key={task.id} value={task.id}>{named(task, locale)}</option>)}
                    </select>
                </div>
                {kpis.length > 0 && (
                    <div>
                        <label htmlFor={id('kpi')} className={labelCls}>{t('dailyActivities.fields.relatedKpi')}</label>
                        <select id={id('kpi')} className={inputCls} value={item.employee_performance_item_id ?? ''} onChange={(e) => onChange({ employee_performance_item_id: e.target.value || null })}>
                            <option value="">—</option>
                            {kpis.map((kpi) => <option key={kpi.id} value={kpi.id}>{named(kpi, locale)}</option>)}
                        </select>
                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.relatedKpiHelp')}</p>
                    </div>
                )}
                {!item.position_service_id && (
                    <div>
                        <label htmlFor={id('category')} className={labelCls}>{t('dailyActivities.fields.category')}</label>
                        <select id={id('category')} className={inputCls} value={item.activity_category ?? ''} onChange={(e) => onChange({ activity_category: e.target.value || null })}>
                            <option value="">—</option>
                            {options.categories.map((c) => <option key={c} value={c}>{t(`dailyActivities.categories.${c}`)}</option>)}
                        </select>
                    </div>
                )}
            </div>

            <div className="mt-3 space-y-3">
                <div>
                    <label htmlFor={id('title')} className={labelCls}>{t('dailyActivities.fields.title')} <span className="text-red-600">*</span></label>
                    <input id={id('title')} className={inputCls} value={item.title} maxLength={255} placeholder={t('dailyActivities.fields.titlePlaceholder')} onChange={(e) => onChange({ title: e.target.value })} />
                    {error('title') && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{error('title')}</p>}
                </div>
                <div>
                    <label htmlFor={id('description')} className={labelCls}>{t('dailyActivities.fields.description')} <span className="text-red-600">*</span></label>
                    <textarea id={id('description')} rows={2} className={inputCls} value={text(item.description)} placeholder={t('dailyActivities.fields.descriptionPlaceholder')} onChange={(e) => onChange({ description: e.target.value })} />
                    {error('description') && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{error('description')}</p>}
                </div>
                <div className="grid gap-3 sm:grid-cols-[1fr_11rem]">
                    <div>
                        <label htmlFor={id('output')} className={labelCls}>
                            {t('dailyActivities.fields.outputResult')}{requireOutput && <span className="text-red-600"> *</span>}
                        </label>
                        <textarea id={id('output')} rows={2} className={inputCls} value={text(item.output_result)} placeholder={t('dailyActivities.fields.outputPlaceholder')} onChange={(e) => onChange({ output_result: e.target.value })} />
                        {error('output_result') && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{error('output_result')}</p>}
                    </div>
                    <div>
                        <label htmlFor={id('progress')} className={labelCls}>{t('dailyActivities.fields.progressStatus')}</label>
                        <select id={id('progress')} className={inputCls} value={item.progress_status} onChange={(e) => onChange({ progress_status: e.target.value })}>
                            {options.progress_statuses.map((s) => <option key={s} value={s}>{t(`dailyActivities.progress.${s}`)}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            <button
                type="button"
                onClick={() => onChange({ open: !item.open })}
                aria-expanded={item.open}
                className="mt-2 text-sm font-medium text-[color:var(--color-primary)] hover:underline"
            >
                {item.open ? t('dailyActivities.entry.lessDetails') : t('dailyActivities.entry.moreDetails')}
            </button>

            {item.open && (
                <div className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <label htmlFor={id('start')} className={labelCls}>{t('dailyActivities.fields.startedAt')}</label>
                        <input id={id('start')} type="time" className={inputCls} value={text(item.started_at)} onChange={(e) => onChange({ started_at: e.target.value || null, duration_minutes: null })} />
                    </div>
                    <div>
                        <label htmlFor={id('end')} className={labelCls}>{t('dailyActivities.fields.endedAt')}</label>
                        <input id={id('end')} type="time" className={inputCls} value={text(item.ended_at)} onChange={(e) => onChange({ ended_at: e.target.value || null, duration_minutes: null })} />
                    </div>
                    <div>
                        <label htmlFor={id('qty')} className={labelCls}>{t('dailyActivities.fields.quantity')}</label>
                        <input id={id('qty')} type="number" min={0} step="any" inputMode="decimal" className={inputCls} value={text(item.quantity)} onChange={(e) => onChange({ quantity: e.target.value || null })} />
                    </div>
                    <div>
                        <label htmlFor={id('uom')} className={labelCls}>{t('dailyActivities.fields.unitOfMeasure')}</label>
                        <input id={id('uom')} className={inputCls} maxLength={64} value={text(item.unit_of_measure)} onChange={(e) => onChange({ unit_of_measure: e.target.value || null })} />
                    </div>
                    <div className="col-span-2">
                        <label htmlFor={id('challenge')} className={labelCls}>{t('dailyActivities.fields.challengeIssue')}</label>
                        <textarea id={id('challenge')} rows={2} className={inputCls} value={text(item.challenge_issue)} onChange={(e) => onChange({ challenge_issue: e.target.value || null })} />
                    </div>
                    <div className="col-span-2">
                        <label htmlFor={id('next')} className={labelCls}>{t('dailyActivities.fields.nextAction')}</label>
                        <textarea id={id('next')} rows={2} className={inputCls} value={text(item.next_action)} onChange={(e) => onChange({ next_action: e.target.value || null })} />
                    </div>
                </div>
            )}
        </fieldset>
    );
}
