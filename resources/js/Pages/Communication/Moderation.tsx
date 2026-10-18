import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import { dateTime, tr } from '@/lib/communication/format';
import { FlagIcon, PaperclipIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { ReportDetail, ReportItem, ReportStatus } from '@/types/communication';

type Props = PageProps<{ initial: { data: ReportItem[] }; counts: Record<string, number> }>;
type Filter = 'active' | 'closed' | 'all';

const STATUS_TONE: Record<ReportStatus, string> = {
    OPEN: 'bg-[color:var(--tone-warning-bg,#fffaeb)] text-amber-800 dark:text-amber-200',
    UNDER_REVIEW: 'bg-[color:var(--tone-info-bg,#eff8ff)] text-[color:var(--color-primary)]',
    ACTIONED: 'bg-[color:var(--tone-success-bg,#ecfdf3)] text-emerald-800 dark:text-emerald-200',
    DISMISSED: 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
};

export default function Moderation({ initial }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [filter, setFilter] = useState<Filter>('active');
    const [rows, setRows] = useState(initial.data);
    const [selected, setSelected] = useState<string | null>(null);
    const [detail, setDetail] = useState<ReportDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) { first.current = false; return; }
        communicationApi.reports(filter).then(setRows).catch(() => undefined);
    }, [filter]);

    useEffect(() => {
        if (!selected) { setDetail(null); return; }
        setLoading(true);
        communicationApi.report(selected).then((d) => {
            setDetail(d);
            setRows((current) => current.map((r) => (r.id === d.report.id ? d.report : r)));
        }).catch(() => toast.error(t('communication.errors.generic'))).finally(() => setLoading(false));
    }, [selected, t]);

    const resolved = (report: ReportItem) => {
        setRows((current) => (filter === 'active' ? current.filter((r) => r.id !== report.id) : current.map((r) => (r.id === report.id ? report : r))));
        setDetail((current) => current && { ...current, report });
    };

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={t('communication.moderation.title')} description={t('communication.moderation.description')} />}>
            <Head title={t('communication.moderation.title')} />
            <div className="grid gap-5 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <div className="flex gap-1 border-b border-[color:var(--app-border)] p-3">
                        {(['active', 'closed', 'all'] as const).map((value) => (
                            <button key={value} type="button" aria-pressed={filter === value} onClick={() => setFilter(value)}
                                className={`h-7 rounded-full px-3 text-xs font-medium ${filter === value ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}>
                                {t(`communication.moderation.filters.${value}`)}
                            </button>
                        ))}
                    </div>
                    {rows.length === 0 ? (
                        <p className="px-4 py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">{filter === 'closed' ? t('communication.moderation.emptyClosed') : t('communication.moderation.empty')}</p>
                    ) : (
                        <ul className="comm-scroll max-h-[70vh] divide-y divide-[color:var(--app-border)] overflow-y-auto">
                            {rows.map((r) => (
                                <li key={r.id}>
                                    <button type="button" onClick={() => setSelected(r.id)} aria-current={selected === r.id ? 'true' : undefined}
                                        className={`block w-full px-4 py-3 text-start ${selected === r.id ? 'bg-[color:color-mix(in_srgb,var(--color-primary)_10%,var(--app-surface))]' : 'hover:bg-[color:var(--app-surface-muted)]'}`}>
                                        <span className="flex items-center gap-2">
                                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-[color:var(--app-foreground)]">{t(`communication.report.categories.${r.category}`)}</span>
                                            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_TONE[r.status]}`}>{t(`communication.moderation.statuses.${r.status}`)}</span>
                                        </span>
                                        <span className="mt-0.5 block truncate text-xs text-[color:var(--app-muted-foreground)]">
                                            {t(`communication.moderation.subjects.${r.subject}`)}{r.reported_user ? ` · ${tr(t('communication.moderation.about'), { name: r.reported_user })}` : ''}
                                        </span>
                                        <span className="mt-0.5 block text-[11px] tabular-nums text-[color:var(--app-muted-foreground)]">{dateTime(r.created_at, locale, system)}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="min-h-[24rem] rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {!selected ? (
                        <div className="flex h-full flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                            <FlagIcon className="h-6 w-6 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('communication.moderation.select')}</p>
                        </div>
                    ) : loading || !detail ? (
                        <div aria-hidden="true" className="animate-pulse space-y-3 p-5">{[0, 1, 2, 3].map((k) => <div key={k} className="h-10 rounded-control bg-[color:var(--app-surface-muted)]" />)}</div>
                    ) : (
                        <ReportView detail={detail} onResolved={resolved} />
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function ReportView({ detail, onResolved }: { detail: ReportDetail; onResolved: (report: ReportItem) => void }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const r = detail.report;
    const closed = r.status === 'ACTIONED' || r.status === 'DISMISSED';
    const [note, setNote] = useState('');
    const [remove, setRemove] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => { setNote(''); setRemove(false); }, [r.id]);

    const decide = (status: 'ACTIONED' | 'DISMISSED') => async (event?: FormEvent) => {
        event?.preventDefault();
        setSaving(true);
        try {
            onResolved(await communicationApi.resolveReport(r.id, { status, note: note.trim(), remove_message: status === 'ACTIONED' && remove }));
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-5 p-5">
            <div className="space-y-1">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t(`communication.report.categories.${r.category}`)}</h2>
                <p className="text-xs text-[color:var(--app-muted-foreground)]">
                    {[tr(t('communication.moderation.reportedBy'), { name: r.reporter ?? '—' }), dateTime(r.created_at, locale, system),
                        detail.conversation ? (detail.conversation.title ?? t('communication.moderation.directConversation')) : null].filter(Boolean).join(' · ')}
                </p>
                {r.details && <p className="rounded-control bg-[color:var(--app-surface-muted)] px-3 py-2 text-sm text-[color:var(--app-foreground)]">{r.details}</p>}
                <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{t('communication.moderation.recorded')}</p>
            </div>

            <div>
                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{t('communication.moderation.context')}</h3>
                <ol className="space-y-2">
                    {detail.messages.map((m) => (
                        <li key={m.id} className={`rounded-control border px-3 py-2 ${m.reported ? 'border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--tone-danger-bg,#fef3f2)]' : 'border-[color:var(--app-border)]'}`}>
                            <p className="flex flex-wrap items-baseline gap-x-2 text-xs">
                                <span className="font-semibold text-[color:var(--app-foreground)]">{m.sender ?? '—'}</span>
                                <span className="tabular-nums text-[color:var(--app-muted-foreground)]">{dateTime(m.created_at, locale, system)}</span>
                                {m.reported && <span className="font-semibold text-[color:var(--color-danger,#b42318)]">{t('communication.moderation.reportedTag')}</span>}
                                {m.deleted && <span className="text-[color:var(--app-muted-foreground)]">{t('communication.moderation.deleted')}</span>}
                            </p>
                            {m.body && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-[color:var(--app-foreground)]">{m.body.replace(/<@\d+>/g, '@…').replace(/<!(everyone|channel)>/g, '@everyone')}</p>}
                            {m.attachments.length > 0 && (
                                <p className="mt-1 flex items-center gap-1 text-xs text-[color:var(--app-muted-foreground)]">
                                    <PaperclipIcon className="h-3.5 w-3.5" aria-hidden="true" />{m.attachments.map((a) => a.name).join(', ')}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            </div>

            {closed ? (
                <div className="rounded-control border border-[color:var(--app-border)] px-4 py-3 text-sm text-[color:var(--app-foreground)]">
                    <p className="font-medium">{tr(t('communication.moderation.resolvedBy'), { status: t(`communication.moderation.statuses.${r.status}`), name: r.reviewer ?? '—' })}</p>
                    {r.resolution_note && <p className="mt-1 text-[color:var(--app-muted-foreground)]">{r.resolution_note}</p>}
                </div>
            ) : (
                <form onSubmit={decide('ACTIONED')} className="space-y-3 border-t border-[color:var(--app-border)] pt-4">
                    <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.moderation.resolve')}</h3>
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.moderation.note')}</span>
                        <textarea value={note} onChange={(e) => setNote(e.target.value)} required minLength={5} maxLength={2000} rows={3}
                            className="w-full resize-none rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 py-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none" />
                    </label>
                    {r.subject === 'MESSAGE' && (
                        <label className="flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
                            <input type="checkbox" checked={remove} onChange={(e) => setRemove(e.target.checked)} className="h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]" />
                            {t('communication.moderation.removeMessage')}
                        </label>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" disabled={saving || note.trim().length < 5} className={buttonClassName({ variant: 'primary' })}>{t('communication.moderation.action')}</button>
                        <button type="button" disabled={saving || note.trim().length < 5} onClick={() => void decide('DISMISSED')()} className={buttonClassName({ variant: 'secondary' })}>{t('communication.moderation.dismiss')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
