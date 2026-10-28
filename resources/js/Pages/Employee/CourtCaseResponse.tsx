import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import StatusBadge from '@/Components/StatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, CardDescription, CardTitle, FormField, Textarea, actionLinkClassName, buttonClassName } from '@euisis/ui';

type Reply = { id: number; review_status: string; review_note: string | null; excerpt: string; created_at: string | null; editable: boolean };

type Props = {
    page: {
        case: { id: number; case_number: string };
        response: {
            id: number;
            response_number: string | null;
            respondent_name: string | null;
            respondent_org: string | null;
            respondent_email: string | null;
            respondent_phone: string | null;
            title: string | null;
            summary: string | null;
            description_html: string;
            created_at: string | null;
            has_pdf: boolean;
        };
        replies: Reply[];
    };
    /** The reply opened for changes (`?edit=`), with its full text. */
    editing: { id: number; description: string; has_pdf: boolean } | null;
    error: string | null;
    done: string | null;
};

/**
 * My Portal > My court cases > a case > a respondent response (court-ms Flow 6):
 * the institution's response once the court accepted it, the employee's replies
 * with the court's review of each, and the reply form. A reply the court has
 * accepted is locked.
 */
export default function CourtCaseResponse({ page, editing, error, done }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), t(`courtCases.response.${key}`));
    const { case: courtCase, response, replies } = page;
    const here = route('employee.court-cases.responses.show', [courtCase.id, response.id]);
    const form = useForm({ description: editing?.description ?? '', pdf: null as File | null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() };
        if (editing) {
            form.post(route('employee.court-cases.replies.update', [courtCase.id, response.id, editing.id]), options);
        } else {
            form.post(route('employee.court-cases.replies.store', [courtCase.id, response.id]), options);
        }
    };

    async function removeReply(reply: Reply) {
        const { confirmed } = await confirm({ title: label('removeTitle'), description: label('removeConfirm'), confirmLabel: label('remove'), cancelLabel: label('cancel'), variant: 'danger' });
        if (confirmed) {
            router.post(route('employee.court-cases.replies.destroy', [courtCase.id, response.id, reply.id]), {}, { preserveScroll: true });
        }
    }

    const from = [response.respondent_name, response.respondent_org].filter(Boolean).join(', ');

    return (
        <PortalPage title={label('title', { number: response.response_number ?? response.id })} description={label('onCase', { number: courtCase.case_number })}
            backHref={route('employee.court-cases.show', courtCase.id)}
            actions={response.has_pdf ? <a href={route('employee.court-cases.download', { case: courtCase.id, kind: 'response', id: response.id })} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('downloadPdf')}</a> : undefined}>
            {error && <Alert tone="danger">{t(`courtCases.portal.errors.${error}`)}</Alert>}
            {done && <Alert tone="success">{t(`courtCases.case.done.${done}`)}</Alert>}

            <Card className="space-y-4">
                <CardTitle>{response.title ?? label('response')}</CardTitle>
                <dl className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{label('from')}</dt>
                        <dd className="mt-1 text-sm text-(--app-foreground)">{from || '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{label('contact')}</dt>
                        <dd className="mt-1 text-sm text-(--app-foreground)">{[response.respondent_phone, response.respondent_email].filter(Boolean).join(' · ') || '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{label('received')}</dt>
                        <dd className="mt-1 text-sm text-(--app-foreground)"><LocalizedDateDisplay value={response.created_at} /></dd>
                    </div>
                </dl>
                {response.summary && <p className="text-sm font-medium text-(--app-foreground)">{response.summary}</p>}
                <RichTextViewer html={response.description_html} className="text-sm text-(--app-foreground)" empty="—" />
            </Card>

            <Card className="space-y-4">
                <CardTitle>{label('replies')}</CardTitle>
                {replies.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{label('noReplies')}</p>
                ) : (
                    <ul className="divide-y divide-(--app-border)">
                        {replies.map((reply) => (
                            <li key={reply.id} className="space-y-2 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={reply.review_status} label={t(`courtCases.portal.review.${reply.review_status}`)} />
                                        <LocalizedDateDisplay value={reply.created_at} withTime className="text-xs text-(--app-muted-foreground)" />
                                    </span>
                                    <span className="flex gap-3">
                                        <a href={route('employee.court-cases.download', { case: courtCase.id, kind: 'reply', id: reply.id, response: response.id })} className={actionLinkClassName()}>{label('replyPdf')}</a>
                                        {reply.editable && <Link href={`${here}?edit=${reply.id}`} preserveScroll className={actionLinkClassName()}>{label('edit')}</Link>}
                                        {reply.editable && <button type="button" onClick={() => void removeReply(reply)} className={actionLinkClassName({ tone: 'danger' })}>{label('remove')}</button>}
                                    </span>
                                </div>
                                <p className="whitespace-pre-line text-sm text-(--app-foreground)">{reply.excerpt}</p>
                                {reply.review_note && <p className="text-sm text-(--app-muted-foreground)"><span className="font-medium">{t('courtCases.case.reviewNote')}:</span> {reply.review_note}</p>}
                                {!reply.editable && <p className="text-xs text-(--app-muted-foreground)">{label('locked')}</p>}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card className="space-y-4">
                <div>
                    <CardTitle>{editing ? label('form.editTitle') : label('form.title')}</CardTitle>
                    <CardDescription>{label('form.help')}</CardDescription>
                </div>
                <form onSubmit={submit} className="space-y-4">
                    <FormField label={label('form.description')} required error={form.errors.description}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required rows={6} maxLength={20000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={label('form.pdf')} required={!editing} description={editing ? label('form.pdfKeep') : label('form.pdfHelp')} error={form.errors.pdf}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" required={!editing} accept=".pdf,application/pdf"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2"
                                onChange={(e) => form.setData('pdf', e.target.files?.[0] ?? null)} />
                        )}
                    </FormField>
                    <div className="flex flex-wrap items-center justify-end gap-3">
                        {editing && <Link href={here} preserveScroll className={buttonClassName({ variant: 'ghost' })}>{label('cancel')}</Link>}
                        <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing}>{editing ? label('form.save') : label('form.submit')}</Button>
                    </div>
                </form>
            </Card>
        </PortalPage>
    );
}
