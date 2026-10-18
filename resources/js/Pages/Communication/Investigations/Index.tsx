import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { communicationApi } from '@/lib/communication/api';
import { dateTime, tr } from '@/lib/communication/format';
import { PlusIcon, SearchIcon, ShieldCheck, XMarkIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { TargetOption } from '@/types/communication';

export type AccessRequestRow = {
    id: string;
    status: 'REQUESTED' | 'APPROVED' | 'REJECTED' | 'ACTIVE' | 'EXPIRED' | 'REVOKED';
    in_force: boolean;
    scope_type: 'EMPLOYEE' | 'CONVERSATION';
    conversation_id: string | null;
    target: string | null;
    reason: string;
    case_reference: string;
    requested_hours: number | null;
    requester: string | null;
    decider: string | null;
    decision_note: string | null;
    created_at: string | null;
    decided_at: string | null;
    starts_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    mine: boolean;
    can: { decide: boolean; revoke: boolean };
};

type Props = PageProps<{ requests: AccessRequestRow[]; can: { request: boolean }; maxHours: number }>;

export const ACCESS_TONE: Record<AccessRequestRow['status'], string> = {
    REQUESTED: 'bg-(--tone-warning-bg,#fffaeb) text-amber-800 dark:text-amber-200',
    APPROVED: 'bg-(--tone-info-bg,#eff8ff) text-primary',
    ACTIVE: 'bg-(--tone-danger-bg,#fef3f2) text-(--color-danger,#b42318)',
    REJECTED: 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
    EXPIRED: 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
    REVOKED: 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
};

export default function InvestigationsIndex({ requests, can, maxHours }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [creating, setCreating] = useState(false);

    const actions = can.request && !creating ? (
        <button type="button" onClick={() => setCreating(true)} className={buttonClassName({ variant: 'primary', className: 'gap-1.5' })}>
            <PlusIcon className="h-4 w-4" aria-hidden="true" />{t('communication.investigations.request')}
        </button>
    ) : null;

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={t('communication.investigations.title')} description={t('communication.investigations.description')} actions={actions} />}>
            <Head title={t('communication.investigations.title')} />
            <div className="space-y-5">
                {creating && <RequestForm maxHours={maxHours} onCancel={() => setCreating(false)} />}
                <section className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
                    {requests.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                            <ShieldCheck className="h-6 w-6 text-(--app-muted-foreground)" aria-hidden="true" />
                            <p className="text-sm text-(--app-muted-foreground)">{t('communication.investigations.empty')}</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {requests.map((r) => (
                                <li key={r.id}>
                                    <Link href={route('communication.investigations.show', r.id)} className="flex flex-col gap-1 px-4 py-3.5 hover:bg-(--app-surface-muted) sm:flex-row sm:items-center sm:gap-4">
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold text-(--app-foreground)">{tr(t('communication.investigations.caseRef'), { ref: r.case_reference })}</span>
                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${ACCESS_TONE[r.status]}`}>{t(`communication.investigations.statuses.${r.status}`)}</span>
                                            </span>
                                            <span className="mt-0.5 block truncate text-xs text-(--app-muted-foreground)">
                                                {[t(`communication.investigations.scopes.${r.scope_type}`), r.target ? tr(t('communication.investigations.target'), { name: r.target }) : null,
                                                    tr(t('communication.investigations.requestedBy'), { name: r.requester ?? '—' })].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-[11px] tabular-nums text-(--app-muted-foreground)">
                                            {r.in_force && r.expires_at ? tr(t('communication.investigations.until'), { date: dateTime(r.expires_at, locale, system) }) : dateTime(r.created_at, locale, system)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function RequestForm({ maxHours, onCancel }: { maxHours: number; onCancel: () => void }): JSX.Element {
    const { t } = useLocale();
    const [scope, setScope] = useState<'EMPLOYEE' | 'CONVERSATION'>('EMPLOYEE');
    const [person, setPerson] = useState<TargetOption | null>(null);
    const [q, setQ] = useState('');
    const [options, setOptions] = useState<TargetOption[]>([]);
    const [conversationId, setConversationId] = useState('');
    const [caseRef, setCaseRef] = useState('');
    const [reason, setReason] = useState('');
    const [hours, setHours] = useState(Math.min(24, maxHours));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const field = 'w-full rounded-control border border-(--app-border) bg-(--app-background) px-2.5 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/15';

    useEffect(() => {
        if (q.trim().length < 2) { setOptions([]); return; }
        const timer = window.setTimeout(() => communicationApi.investigationPeople(q.trim()).then(setOptions).catch(() => setOptions([])), 250);
        return () => window.clearTimeout(timer);
    }, [q]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        router.post(route('communication.investigations.store'), {
            scope_type: scope,
            target_user_id: scope === 'EMPLOYEE' ? person?.id ?? null : null,
            conversation_id: scope === 'CONVERSATION' ? conversationId.trim() : null,
            case_reference: caseRef, reason, hours,
        }, { onError: setErrors, onFinish: () => setSaving(false) });
    };

    return (
        <section aria-labelledby="access-form" className="rounded-panel border border-(--app-border) bg-(--app-surface)">
            <h2 id="access-form" className="border-b border-(--app-border) px-4 py-3 text-sm font-semibold text-(--app-foreground)">{t('communication.investigations.form.title')}</h2>
            <form onSubmit={submit} className="grid gap-5 p-4 lg:grid-cols-2">
                <div className="space-y-4">
                    <fieldset className="space-y-1.5">
                        <legend className="mb-1 text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.scope')}</legend>
                        {(['EMPLOYEE', 'CONVERSATION'] as const).map((value) => (
                            <label key={value} className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="radio" name="scope" checked={scope === value} onChange={() => setScope(value)} className="h-4 w-4 text-primary" />
                                {t(`communication.investigations.scopes.${value}`)}
                            </label>
                        ))}
                    </fieldset>
                    {scope === 'EMPLOYEE' ? (
                        <div className="space-y-1">
                            <span className="text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.person')}</span>
                            {person ? (
                                <div className="flex h-9 items-center justify-between rounded-control border border-(--app-border) px-2.5 text-sm text-(--app-foreground)">
                                    <span className="truncate">{person.name}{person.hint ? ` · ${person.hint}` : ''}</span>
                                    <button type="button" onClick={() => setPerson(null)} aria-label={t('communication.announcements.form.remove')} className="rounded-sm p-0.5 text-(--app-muted-foreground) hover:text-(--app-foreground)"><XMarkIcon className="h-4 w-4" /></button>
                                </div>
                            ) : (
                                <div className="relative">
                                    <SearchIcon className="pointer-events-none absolute inset-s-2.5 top-4.5 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden="true" />
                                    <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('communication.investigations.form.personSearch')} aria-label={t('communication.investigations.form.personSearch')} className={`${field} h-9 ps-8`} />
                                    {options.length > 0 && (
                                        <ul className="comm-scroll absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-control border border-(--app-border) bg-(--app-surface) py-1 shadow-lg">
                                            {options.map((o) => (
                                                <li key={o.id}><button type="button" onClick={() => { setPerson(o); setQ(''); }} className="flex w-full justify-between gap-2 px-3 py-1.5 text-start text-sm text-(--app-foreground) hover:bg-(--app-surface-muted)">
                                                    <span className="truncate">{o.name}</span>{o.hint && <span className="shrink-0 text-[11px] text-(--app-muted-foreground)">{o.hint}</span>}
                                                </button></li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                            {errors.target_user_id && <p className="text-xs text-(--color-danger,#b42318)">{errors.target_user_id}</p>}
                        </div>
                    ) : (
                        <label className="block space-y-1">
                            <span className="text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.conversation')}</span>
                            <input value={conversationId} onChange={(e) => setConversationId(e.target.value)} className={`${field} h-9 font-mono text-xs`} />
                            <span className="block text-[11px] text-(--app-muted-foreground)">{t('communication.investigations.form.conversationHelp')}</span>
                            {errors.conversation_id && <span className="block text-xs text-(--color-danger,#b42318)">{errors.conversation_id}</span>}
                        </label>
                    )}
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.caseReference')}</span>
                        <input value={caseRef} onChange={(e) => setCaseRef(e.target.value)} required maxLength={100} className={`${field} h-9`} />
                        {errors.case_reference && <span className="block text-xs text-(--color-danger,#b42318)">{errors.case_reference}</span>}
                    </label>
                </div>
                <div className="space-y-4">
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.reason')}</span>
                        <textarea value={reason} onChange={(e) => setReason(e.target.value)} required minLength={30} maxLength={4000} rows={6} className={`${field} resize-none py-2`} />
                        <span className="block text-[11px] text-(--app-muted-foreground)">{t('communication.investigations.form.reasonHelp')}</span>
                        {errors.reason && <span className="block text-xs text-(--color-danger,#b42318)">{errors.reason}</span>}
                    </label>
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-(--app-foreground)">{t('communication.investigations.form.hours')}</span>
                        <input type="number" min={1} max={maxHours} value={hours} onChange={(e) => setHours(Number(e.target.value))} className={`${field} h-9 w-28`} />
                        <span className="block text-[11px] text-(--app-muted-foreground)">{tr(t('communication.investigations.form.hoursHelp'), { max: maxHours })}</span>
                    </label>
                </div>
                <div className="flex justify-end gap-2 border-t border-(--app-border) pt-4 lg:col-span-2">
                    <button type="button" onClick={onCancel} className={buttonClassName({ variant: 'secondary' })}>{t('communication.channels.cancel')}</button>
                    <button type="submit" disabled={saving || reason.trim().length < 30 || !caseRef.trim() || (scope === 'EMPLOYEE' ? !person : !conversationId.trim())} className={buttonClassName({ variant: 'primary' })}>
                        {t('communication.investigations.form.submit')}
                    </button>
                </div>
            </form>
        </section>
    );
}
