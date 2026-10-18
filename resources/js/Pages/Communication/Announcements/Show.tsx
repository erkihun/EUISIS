import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useState, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { announceTotalsChanged, communicationApi } from '@/lib/communication/api';
import { dateTime, fileSize, tr } from '@/lib/communication/format';
import PriorityMark from '@/Components/Communication/PriorityMark';
import { StatusPill } from '@/Pages/Communication/Announcements/Index';
import { CheckReadIcon, DownloadIcon, PaperclipIcon, SearchIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { AnnouncementDetail, Recipient } from '@/types/communication';

type Props = PageProps<{ announcement: AnnouncementDetail }>;

const BANNER: Record<string, string> = {
    URGENT: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-100',
    EMERGENCY: 'border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--tone-danger-bg,#fef3f2)] text-[color:var(--color-danger,#b42318)]',
};

export default function AnnouncementShow({ announcement: a }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState(false);

    // Opening it marked it read: let the badge catch up.
    useEffect(() => { announceTotalsChanged(); }, [a.id]);

    const acknowledge = () => {
        setBusy(true);
        router.post(route('communication.announcements.acknowledge', a.id), {}, { preserveScroll: true, onFinish: () => { setBusy(false); announceTotalsChanged(); } });
    };

    const withdraw = async () => {
        const answer = await confirm({
            title: t('communication.announcements.cancelTitle'),
            description: t('communication.announcements.cancelMessage'),
            confirmLabel: t('communication.announcements.actions.cancel'),
            variant: 'danger',
            requireReason: true,
            reasonLabel: t('communication.announcements.cancelReason'),
        });
        if (answer.confirmed) router.post(route('communication.announcements.cancel', a.id), { reason: answer.reason ?? '' }, { preserveScroll: true });
    };

    const publish = () => router.post(route('communication.announcements.publish', a.id), {}, { preserveScroll: true });

    const actions = a.manage ? (
        <div className="flex flex-wrap gap-2">
            {a.can.edit && <Link href={route('communication.announcements.edit', a.id)} className={buttonClassName({ variant: 'secondary' })}>{t('communication.announcements.actions.edit')}</Link>}
            {a.can.publish && <button type="button" onClick={publish} className={buttonClassName({ variant: 'primary' })}>{t('communication.announcements.actions.publish')}</button>}
            {a.can.export && <a href={route('communication.announcements.export', a.id)} className={buttonClassName({ variant: 'secondary', className: 'gap-1.5' })}><DownloadIcon className="h-4 w-4" aria-hidden="true" />{t('communication.announcements.actions.export')}</a>}
            {a.can.cancel && <button type="button" onClick={() => void withdraw()} className={buttonClassName({ variant: 'destructive' })}>{t('communication.announcements.actions.cancel')}</button>}
        </div>
    ) : null;

    return (
        <AuthenticatedLayout header={<PageHeader title={a.title} actions={actions} backHref={route('communication.announcements.index')} />}>
            <Head title={a.title} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <article className="space-y-5">
                    {(a.priority === 'URGENT' || a.priority === 'EMERGENCY') && (
                        <div role="alert" className={`flex items-center gap-2 rounded-control border px-4 py-2.5 text-sm font-medium ${BANNER[a.priority]}`}>
                            <PriorityMark priority={a.priority} />
                        </div>
                    )}

                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 sm:p-6">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[color:var(--app-muted-foreground)]">
                            {a.priority !== 'URGENT' && a.priority !== 'EMERGENCY' && <PriorityMark priority={a.priority} />}
                            {a.manage && <StatusPill status={a.status} />}
                            {a.published_at && <span>{tr(t('communication.announcements.published'), { date: dateTime(a.published_at, locale, system) })}</span>}
                            {a.publisher && <span>{tr(t('communication.announcements.by'), { name: a.publisher })}</span>}
                            {a.expires_at && <span>{tr(t('communication.announcements.expires'), { date: dateTime(a.expires_at, locale, system) })}</span>}
                        </div>
                        <div className="mt-4 max-w-[70ch] whitespace-pre-wrap break-words text-[15px] leading-7 text-[color:var(--app-foreground)]">{a.body}</div>

                        {a.attachments.length > 0 && (
                            <div className="mt-6 border-t border-[color:var(--app-border)] pt-4">
                                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{t('communication.announcements.files')}</h2>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {a.attachments.map((file) => (
                                        <li key={file.id}>
                                            <a href={file.downloadable ? communicationApi.announcementFileUrl(file.id) : undefined} aria-disabled={!file.downloadable}
                                                className="flex items-center gap-2.5 rounded-control border border-[color:var(--app-border)] px-3 py-2 text-sm hover:bg-[color:var(--app-surface-muted)]">
                                                <PaperclipIcon className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                                <span className="min-w-0 flex-1 truncate text-[color:var(--app-foreground)]">{file.name}</span>
                                                <span className="shrink-0 text-xs text-[color:var(--app-muted-foreground)]">{file.downloadable ? fileSize(file.size) : t('communication.chat.attachmentWaiting')}</span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    {a.acknowledgement_required && a.status === 'PUBLISHED' && (a.can.acknowledge || a.acknowledged) && (
                        <div className={`flex flex-col gap-3 rounded-panel border p-5 sm:flex-row sm:items-center ${a.acknowledged ? 'border-[color:var(--tone-success-border,#abefc6)] bg-[color:var(--tone-success-bg,#ecfdf3)]' : 'border-[color:var(--tone-warning-border,#fedf89)] bg-[color:var(--tone-warning-bg,#fffaeb)]'}`}>
                            {a.acknowledged ? (
                                <p className="flex items-center gap-2 text-sm font-medium text-emerald-800 dark:text-emerald-200">
                                    <CheckReadIcon className="h-5 w-5" aria-hidden="true" />
                                    {tr(t('communication.announcements.acknowledgedOn'), { date: dateTime(a.acknowledged_at, locale, system) })}
                                </p>
                            ) : (
                                <>
                                    <p className="flex-1 text-sm text-[color:var(--app-foreground)]">{t('communication.announcements.acknowledgeHelp')}</p>
                                    <button type="button" disabled={busy} onClick={acknowledge} className={buttonClassName({ variant: 'primary', className: 'shrink-0' })}>
                                        {t('communication.announcements.acknowledge')}
                                    </button>
                                </>
                            )}
                        </div>
                    )}
                </article>

                {a.manage && <ManagePanel a={a} />}
            </div>
        </AuthenticatedLayout>
    );
}

function ManagePanel({ a }: { a: AnnouncementDetail }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const m = a.manage!;
    const live = a.status !== 'DRAFT' && a.status !== 'SCHEDULED';
    const stats: [string, number | null][] = [
        [t('communication.announcements.report.targeted'), m.stats.targeted],
        [t('communication.announcements.report.delivered'), m.stats.delivered],
        [t('communication.announcements.report.viewed'), m.stats.viewed],
        ...(a.acknowledgement_required ? [[t('communication.announcements.report.acknowledged'), m.stats.acknowledged] as [string, number]] : []),
    ];

    return (
        <aside className="space-y-5">
            {a.status === 'CANCELLED' && m.cancelled_at && (
                <p className="rounded-control border border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--tone-danger-bg,#fef3f2)] px-4 py-3 text-sm text-[color:var(--color-danger,#b42318)]">
                    {tr(t('communication.announcements.cancelled'), { date: dateTime(m.cancelled_at, locale, system), reason: m.cancel_reason ?? '' })}
                </p>
            )}
            {a.status === 'SCHEDULED' && m.publish_at && (
                <p className="rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-3 text-sm text-[color:var(--app-foreground)]">
                    {tr(t('communication.announcements.scheduledFor'), { date: dateTime(m.publish_at, locale, system) })}
                </p>
            )}

            <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.announcements.targetsTitle')}</h2>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {m.targets.map((target) => (
                        <li key={`${target.type}:${target.id}`} className="rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-2.5 py-1 text-xs text-[color:var(--app-foreground)]">
                            <span className="text-[color:var(--app-muted-foreground)]">{t(`communication.announcements.targetTypes.${target.type}`)}</span>
                            {target.type !== 'CITY' && <>: {target.label}</>}
                        </li>
                    ))}
                </ul>
                <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-[color:var(--app-border)] pt-4 sm:grid-cols-4 xl:grid-cols-2">
                    {stats.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-[11px] text-[color:var(--app-muted-foreground)]">{label}</dt>
                            <dd className="text-lg font-semibold tabular-nums text-[color:var(--app-foreground)]">{value === null ? '—' : value.toLocaleString()}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            {live && <Recipients id={a.id} withAck={a.acknowledgement_required} />}
        </aside>
    );
}

function Recipients({ id, withAck }: { id: string; withAck: boolean }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const filters = withAck ? (['all', 'pending', 'acknowledged', 'not_viewed'] as const) : (['all', 'viewed', 'not_viewed'] as const);
    const [status, setStatus] = useState<string>(withAck ? 'pending' : 'all');
    const [q, setQ] = useState('');
    const [rows, setRows] = useState<Recipient[] | null>(null);
    const [next, setNext] = useState<string | null>(null);

    const load = useCallback((cursor: string | null) => {
        communicationApi.recipients(id, { status, q: q.trim().length >= 2 ? q.trim() : null, cursor })
            .then((page) => { setRows((current) => (cursor && current ? [...current, ...page.data] : page.data)); setNext(page.next_cursor); })
            .catch(() => setRows([]));
    }, [id, status, q]);

    useEffect(() => {
        const timer = window.setTimeout(() => load(null), q ? 250 : 0);
        return () => window.clearTimeout(timer);
    }, [load, q]);

    const when = (iso: string | null) => (iso ? dateTime(iso, locale, system) : <span className="text-[color:var(--app-muted-foreground)]">{t('communication.announcements.report.notYet')}</span>);

    return (
        <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            <div className="space-y-2 border-b border-[color:var(--app-border)] p-3">
                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.announcements.report.recipients')}</h2>
                <div className="flex flex-wrap gap-1">
                    {filters.map((value) => (
                        <button key={value} type="button" aria-pressed={status === value} onClick={() => setStatus(value)}
                            className={`h-7 rounded-full px-2.5 text-[11px] font-medium ${status === value ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}>
                            {t(`communication.announcements.report.filters.${value}`)}
                        </button>
                    ))}
                </div>
                <label className="relative block">
                    <span className="sr-only">{t('communication.announcements.report.search')}</span>
                    <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                    <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('communication.announcements.report.search')}
                        className="h-8 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-xs text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none" />
                </label>
            </div>
            {rows === null ? (
                <p className="px-4 py-6 text-center text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.report.counting')}</p>
            ) : rows.length === 0 ? (
                <p className="px-4 py-6 text-center text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.report.none')}</p>
            ) : (
                <ul className="comm-scroll max-h-[28rem] divide-y divide-[color:var(--app-border)] overflow-y-auto">
                    {rows.map((row) => (
                        <li key={row.id} className="px-3 py-2">
                            <p className="flex items-baseline justify-between gap-2 text-sm text-[color:var(--app-foreground)]">
                                <span className="truncate">{row.name}</span>
                                {row.employee_number && <span className="shrink-0 text-[11px] tabular-nums text-[color:var(--app-muted-foreground)]">{row.employee_number}</span>}
                            </p>
                            <p className="mt-0.5 grid grid-cols-2 gap-2 text-[11px] text-[color:var(--app-foreground)]">
                                <span><span className="text-[color:var(--app-muted-foreground)]">{t('communication.announcements.report.columns.viewed')}: </span>{when(row.viewed_at)}</span>
                                {withAck && <span><span className="text-[color:var(--app-muted-foreground)]">{t('communication.announcements.report.columns.acknowledged')}: </span>{when(row.acknowledged_at)}</span>}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            {next && (
                <div className="border-t border-[color:var(--app-border)] p-2">
                    <button type="button" onClick={() => load(next)} className="h-8 w-full rounded-control text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)]">
                        {t('communication.announcements.loadMore')}
                    </button>
                </div>
            )}
        </section>
    );
}
