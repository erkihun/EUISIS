import { Head, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { communicationApi } from '@/lib/communication/api';
import { dateTime, tr } from '@/lib/communication/format';
import { DangerCircleIcon, PaperclipIcon } from '@/Components/NavIcons';
import { ACCESS_TONE, type AccessRequestRow } from '@/Pages/Communication/Investigations/Index';
import type { PageProps } from '@/types';
import type { ConversationType } from '@/types/communication';

type CoveredConversation = { id: string; type: ConversationType; title: string | null; members: string[]; member_count: number; last_message_at: string | null };
type Props = PageProps<{ request: AccessRequestRow; conversations: CoveredConversation[] | null }>;
type Line = Awaited<ReturnType<typeof communicationApi.investigationMessages>>['data'][number];

export default function InvestigationShow({ request: r, conversations }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const { confirm } = useConfirm();

    const revoke = async () => {
        const answer = await confirm({
            title: t('communication.investigations.revokeTitle'),
            description: t('communication.investigations.revokeMessage'),
            confirmLabel: t('communication.investigations.revoke'),
            variant: 'danger',
        });
        if (answer.confirmed) router.post(route('communication.investigations.revoke', r.id), {}, { preserveScroll: true });
    };

    const actions = r.can.revoke ? <button type="button" onClick={() => void revoke()} className={buttonClassName({ variant: 'destructive' })}>{t('communication.investigations.revoke')}</button> : null;
    const facts: [string, string | null][] = [
        [t('communication.investigations.form.scope'), `${t(`communication.investigations.scopes.${r.scope_type}`)}${r.target ? ` — ${r.target}` : ''}${r.conversation_id ? ` — ${r.conversation_id}` : ''}`],
        [t('communication.investigations.requestedBy').replace(':name', '').trim(), `${r.requester ?? '—'} · ${dateTime(r.created_at, locale, system)}`],
        [t('communication.investigations.form.hours'), r.requested_hours ? tr(t('communication.investigations.hoursAsked'), { n: r.requested_hours }) : null],
        [t('communication.investigations.decidedBy').replace(':name', '').trim(), r.decider ? `${r.decider} · ${dateTime(r.decided_at, locale, system)}` : null],
        [t('communication.investigations.until').replace(':date', '').trim(), r.expires_at ? dateTime(r.expires_at, locale, system) : null],
    ];

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={tr(t('communication.investigations.caseRef'), { ref: r.case_reference })} actions={actions} backHref={route('communication.investigations.index')} />}>
            <Head title={tr(t('communication.investigations.caseRef'), { ref: r.case_reference })} />
            <div className="space-y-5">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${ACCESS_TONE[r.status]}`}>{t(`communication.investigations.statuses.${r.status}`)}</span>
                    <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        {facts.filter(([, v]) => v).map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-[11px] text-[color:var(--app-muted-foreground)]">{label}</dt>
                                <dd className="text-sm text-[color:var(--app-foreground)]">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-4">
                        <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{t('communication.investigations.form.reason')}</p>
                        <p className="mt-0.5 max-w-[75ch] whitespace-pre-wrap text-sm leading-relaxed text-[color:var(--app-foreground)]">{r.reason}</p>
                    </div>
                    {r.decision_note && (
                        <div className="mt-3">
                            <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{t('communication.investigations.decide.note').replace(/\s*\(.*\)/, '')}</p>
                            <p className="mt-0.5 whitespace-pre-wrap text-sm text-[color:var(--app-foreground)]">{r.decision_note}</p>
                        </div>
                    )}
                </section>

                {r.can.decide && <DecisionForm request={r} />}

                {r.mine && !r.in_force && r.status !== 'REQUESTED' && (
                    <p className="rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 py-3 text-sm text-[color:var(--app-muted-foreground)]">{t('communication.investigations.notInForce')}</p>
                )}

                {conversations && <Reader request={r} conversations={conversations} />}
            </div>
        </AuthenticatedLayout>
    );
}

function DecisionForm({ request }: { request: AccessRequestRow }): JSX.Element {
    const { t } = useLocale();
    const [note, setNote] = useState('');
    const [hours, setHours] = useState(request.requested_hours ?? 24);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const decide = (approve: boolean) => (event?: FormEvent) => {
        event?.preventDefault();
        setSaving(true);
        router.post(route('communication.investigations.decide', request.id), { approve, note, hours: approve ? hours : null },
            { preserveScroll: true, onError: setErrors, onFinish: () => setSaving(false) });
    };

    return (
        <form onSubmit={decide(true)} className="space-y-3 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.investigations.decide.title')}</h2>
            <label className="block space-y-1">
                <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.investigations.decide.note')}</span>
                <textarea value={note} onChange={(e) => setNote(e.target.value)} required minLength={10} rows={3}
                    className="w-full resize-none rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 py-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none" />
                {errors.note && <span className="block text-xs text-[color:var(--color-danger,#b42318)]">{errors.note}</span>}
            </label>
            <label className="block space-y-1">
                <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.investigations.decide.hours')}</span>
                <input type="number" min={1} max={request.requested_hours ?? 72} value={hours} onChange={(e) => setHours(Number(e.target.value))}
                    className="h-9 w-28 rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)]" />
                {errors.hours && <span className="block text-xs text-[color:var(--color-danger,#b42318)]">{errors.hours}</span>}
            </label>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={saving || note.trim().length < 10} className={buttonClassName({ variant: 'primary' })}>{t('communication.investigations.decide.approve')}</button>
                <button type="button" disabled={saving || note.trim().length < 10} onClick={() => decide(false)()} className={buttonClassName({ variant: 'secondary' })}>{t('communication.investigations.decide.reject')}</button>
            </div>
        </form>
    );
}

function Reader({ request, conversations }: { request: AccessRequestRow; conversations: CoveredConversation[] }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [active, setActive] = useState<string | null>(conversations[0]?.id ?? null);
    const [lines, setLines] = useState<Line[] | null>(null);
    const [before, setBefore] = useState<string | null>(null);

    useEffect(() => {
        if (!active) return;
        setLines(null);
        communicationApi.investigationMessages(request.id, active).then((page) => { setLines(page.data); setBefore(page.next_before); }).catch(() => setLines([]));
    }, [active, request.id]);

    const older = () => {
        if (!active || !before) return;
        communicationApi.investigationMessages(request.id, active, before).then((page) => { setLines((c) => [...page.data, ...(c ?? [])]); setBefore(page.next_before); });
    };

    return (
        <section className="overflow-hidden rounded-panel border border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--app-surface)]">
            <p role="status" className="flex items-center gap-2 border-b border-[color:var(--tone-danger-border,#f2b8b5)] bg-[color:var(--tone-danger-bg,#fef3f2)] px-4 py-2.5 text-sm font-medium text-[color:var(--color-danger,#b42318)]">
                <DangerCircleIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                {tr(t('communication.investigations.banner'), { ref: request.case_reference })}
            </p>
            <div className="grid lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                <div className="border-b border-[color:var(--app-border)] lg:border-b-0 lg:border-e">
                    <h2 className="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{t('communication.investigations.conversations')}</h2>
                    {conversations.length === 0 ? <p className="px-4 py-4 text-sm text-[color:var(--app-muted-foreground)]">{t('communication.investigations.noConversations')}</p> : (
                        <ul className="comm-scroll max-h-[60vh] overflow-y-auto py-1">
                            {conversations.map((c) => (
                                <li key={c.id}>
                                    <button type="button" onClick={() => setActive(c.id)} aria-current={active === c.id ? 'true' : undefined}
                                        className={`block w-full px-4 py-2 text-start ${active === c.id ? 'bg-[color:var(--app-surface-muted)]' : 'hover:bg-[color:var(--app-surface-muted)]'}`}>
                                        <span className="block truncate text-sm font-medium text-[color:var(--app-foreground)]">{c.title ?? c.members.join(', ')}</span>
                                        <span className="block truncate text-[11px] text-[color:var(--app-muted-foreground)]">{t(`communication.types.${c.type}`)} · {tr(t('communication.investigations.members'), { n: c.member_count })}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="comm-scroll max-h-[70vh] min-h-[16rem] overflow-y-auto p-4">
                    {!active ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('communication.investigations.selectConversation')}</p>
                        : lines === null ? <div aria-hidden="true" className="animate-pulse space-y-2">{[0, 1, 2].map((k) => <div key={k} className="h-10 rounded-control bg-[color:var(--app-surface-muted)]" />)}</div>
                            : (
                                <>
                                    {before && <button type="button" onClick={older} className="mb-3 h-7 rounded-full px-3 text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)]">{t('communication.investigations.olderMessages')}</button>}
                                    <ol className="space-y-2">
                                        {lines.map((m) => (
                                            <li key={m.id} className="rounded-control border border-[color:var(--app-border)] px-3 py-2">
                                                <p className="flex flex-wrap items-baseline gap-x-2 text-xs">
                                                    <span className="font-semibold text-[color:var(--app-foreground)]">{m.sender ?? '—'}</span>
                                                    <span className="tabular-nums text-[color:var(--app-muted-foreground)]">{dateTime(m.created_at, locale, system)}</span>
                                                    {m.deleted && <span className="font-medium text-[color:var(--color-danger,#b42318)]">{t('communication.investigations.deletedTag')}</span>}
                                                    {m.edited_at && <span className="text-[color:var(--app-muted-foreground)]">{t('communication.investigations.editedTag')}</span>}
                                                </p>
                                                {m.body && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-[color:var(--app-foreground)]">{m.body}</p>}
                                                {m.attachments.length > 0 && <p className="mt-1 flex items-center gap-1 text-xs text-[color:var(--app-muted-foreground)]"><PaperclipIcon className="h-3.5 w-3.5" aria-hidden="true" />{m.attachments.map((a) => a.name).join(', ')}</p>}
                                            </li>
                                        ))}
                                    </ol>
                                </>
                            )}
                </div>
            </div>
        </section>
    );
}
