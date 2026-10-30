import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, textareaCls, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ClarificationStatus, Named, NamedWithId, Paginated } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

type Thread = { id: string; subject: string; status: ClarificationStatus; section: number | null; messages: number; opened_by: string | null; updated_at: string | null; organization: Named };

/** Clarification threads in scope, and a new question to the support officers (D22). */
export default function ClarificationsIndex({ threads, filters, own_participants }: { threads: Paginated<Thread>; filters: { status: string | null }; own_participants: NamedWithId[] }): JSX.Element {
    const { sm, name } = useSm();
    const fromSubmission = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('submission_id') ?? '' : '';
    const [asking, setAsking] = useState(fromSubmission !== '');
    const form = useForm({ participant_id: own_participants[0]?.id ?? '', submission_id: fromSubmission, section: '', subject: '', body: '' });

    function send(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, section: d.section === '' ? null : Number(d.section), submission_id: d.submission_id || null }));
        form.post(route('service-monitoring.clarifications.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('clarifications.title')} description={sm('clarifications.description')}
            actions={own_participants.length > 0 ? <button type="button" className={primaryBtn} onClick={() => setAsking(true)}>{sm('clarifications.ask')}</button> : undefined} />}>
            <Head title={sm('clarifications.title')} />
            <ActionErrors />
            <div className="space-y-4">
                {asking && (
                    <form onSubmit={send} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`} aria-label={sm('clarifications.ask')}>
                        <div>
                            <label htmlFor="sm-cl-p" className={labelCls}>{sm('clarifications.institution')}</label>
                            <select id="sm-cl-p" className={inputCls} value={form.data.participant_id} onChange={(e) => form.setData('participant_id', e.target.value)}>
                                {own_participants.map((p) => <option key={p.id} value={p.id}>{name(p)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sm-cl-sec" className={labelCls}>{sm('clarifications.section')}</label>
                            <select id="sm-cl-sec" className={inputCls} value={form.data.section} onChange={(e) => form.setData('section', e.target.value)}>
                                <option value="">—</option>
                                {[1, 2, 3, 4].map((n) => <option key={n} value={n}>{sm('edit.section', { n })}</option>)}
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="sm-cl-subject" className={labelCls}>{sm('clarifications.subject')}</label>
                            <input id="sm-cl-subject" className={inputCls} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} required />
                            {form.errors.subject && <p className={errorCls}>{form.errors.subject}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="sm-cl-body" className={labelCls}>{sm('clarifications.message')}</label>
                            <textarea id="sm-cl-body" rows={4} className={textareaCls} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} required />
                            {form.errors.body && <p className={errorCls}>{form.errors.body}</p>}
                        </div>
                        <div className="flex gap-2 sm:col-span-2">
                            <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('clarifications.send')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setAsking(false)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}
                <div className="flex flex-wrap gap-2" role="group" aria-label={sm('common.status')}>
                    {['', 'open', 'answered', 'closed'].map((s) => (
                        <button key={s} type="button" aria-pressed={(filters.status ?? '') === s} className={(filters.status ?? '') === s ? primaryBtn : secondaryBtn}
                            onClick={() => router.get(route('service-monitoring.clarifications.index'), s ? { status: s } : {}, { preserveState: true })}>
                            {s ? sm(`enums.clarification_status.${s}`) : sm('common.all')}
                        </button>
                    ))}
                </div>
                {threads.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('clarifications.empty')}</p> : (
                    <ul className={`${panelCls} divide-y divide-(--app-border)`}>
                        {threads.data.map((t) => (
                            <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <div className="min-w-0">
                                    <Link className="text-sm font-medium underline" href={route('service-monitoring.clarifications.show', t.id)}>{t.subject}</Link>
                                    <p className="text-xs text-(--app-muted-foreground)">{name(t.organization)} · {sm('clarifications.messages', { count: t.messages })} · <LocalizedDateDisplay value={t.updated_at} withTime /></p>
                                </div>
                                <span className="text-xs">{sm(`enums.clarification_status.${t.status}`)}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={threads} />
            </div>
        </AuthenticatedLayout>
    );
}
