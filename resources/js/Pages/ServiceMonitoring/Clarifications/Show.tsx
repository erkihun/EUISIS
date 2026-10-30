import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { errorCls, labelCls, panelCls, primaryBtn, secondaryBtn, textareaCls, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ClarificationStatus, Named } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

interface Props {
    clarification: {
        id: string; subject: string; status: ClarificationStatus; section: number | null; submission_id: string | null; period_label: string | null; organization: Named;
        messages: { id: string; body: string; author: string | null; mine: boolean; created_at: string | null }[];
    };
    can: { reply: boolean; close: boolean };
}

/** One clarification thread: plain-text messages, a reply, closing (D22). */
export default function ClarificationShow({ clarification, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const form = useForm({ body: '' });

    function reply(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-monitoring.clarifications.reply', clarification.id), { preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={clarification.subject} description={`${name(clarification.organization)}${clarification.period_label ? ` · ${clarification.period_label}` : ''}`}
            backHref={route('service-monitoring.clarifications.index')}
            actions={can.close ? <button type="button" className={secondaryBtn} onClick={() => router.post(route('service-monitoring.clarifications.close', clarification.id))}>{sm('clarifications.closeThread')}</button> : undefined} />}>
            <Head title={clarification.subject} />
            <div className="space-y-4">
                <p className="text-sm">{sm(`enums.clarification_status.${clarification.status}`)}{clarification.section ? ` · ${sm('edit.section', { n: clarification.section })}` : ''}
                    {clarification.submission_id && <> · <Link className="underline" href={route('service-monitoring.submissions.show', clarification.submission_id)}>{sm('dashboard.view')}</Link></>}
                </p>
                <ol className="space-y-3" aria-live="polite">
                    {clarification.messages.map((m) => (
                        <li key={m.id} className={`${panelCls} p-3 ${m.mine ? 'ms-8' : 'me-8'}`}>
                            <p className="whitespace-pre-line text-sm text-(--app-foreground)">{m.body}</p>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{m.mine ? sm('clarifications.mine') : m.author} · <LocalizedDateDisplay value={m.created_at} withTime /></p>
                        </li>
                    ))}
                </ol>
                {can.reply && (
                    <form onSubmit={reply} className={`${panelCls} space-y-2 p-4`}>
                        <label htmlFor="sm-reply" className={labelCls}>{sm('clarifications.reply')}</label>
                        <textarea id="sm-reply" rows={4} className={textareaCls} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} required />
                        {form.errors.body && <p className={errorCls}>{form.errors.body}</p>}
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('clarifications.send')}</button>
                    </form>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
