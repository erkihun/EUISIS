import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import StatusBadge from '@/Components/StatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, CardDescription, CardHeader, CardTitle, FormField, Input, Textarea, actionLinkClassName, buttonClassName } from '@euisis/ui';

type Workspace = {
    case: {
        id: number;
        case_number: string;
        status: string;
        review_status: string;
        case_type: string | null;
        filed_at: string | null;
        respondent_name: string | null;
        respondent_address: string | null;
        description_html: string;
        relief_html: string | null;
    };
    docs: Array<{ id: number; title: string | null; created_at: string | null; has_file: boolean }>;
    witnesses: Array<{ id: number; full_name: string; phone: string | null; email: string | null; address: string | null }>;
    hearings: Array<{ id: number; hearing_at: string | null; type: string | null; location: string | null; notes: string | null }>;
    letters: Array<{ id: number; subject: string | null; reference_number: string | null; created_at: string | null }>;
    decisions: Array<{ id: number; title: string; decided_at: string | null }>;
    respondentResponses: Array<{ id: number; title: string | null; response_number: string | null; created_at: string | null; excerpt: string | null }>;
    messages: Array<{ id: number; body: string; mine: boolean; sender_name: string | null; created_at: string | null }>;
    files: Array<{ id: number; name: string; created_at: string | null; can_delete: boolean }>;
    review: { status: string; note: string | null };
    /** A pending case the registry has not accepted may still be corrected, by its lead applicant. */
    can: { modify: boolean };
    /** The applicant's side (ADR 0013 #13): the applicant, co-applicants, admitted interveners and their lawyers. */
    parties?: Array<{
        id: number;
        name: string;
        role: 'lead' | 'co_applicant' | 'intervener';
        status: string;
        is_you: boolean;
        lawyers: Array<{ name: string; license_number: string | null; status: string }>;
    }>;
    /** How the employee is a party: lead, co_applicant, intervener (or lawyer). */
    role?: string | null;
    merged_into?: { id: number; case_number: string; note: string | null } | null;
    merged_from?: string[];
};

type Props = {
    workspace: Workspace;
    /** Why court-ms refused the last step (courtCases.portal.errors.*). */
    error: string | null;
    /** The step just done (courtCases.case.done.*). */
    done: string | null;
};

/**
 * My Portal > My court cases > a case (court-ms Flow 6). The employee follows and
 * works on their tribunal case here: its review, hearings, documents, messages
 * with the court, respondent responses, letters and decisions. Every change runs
 * court-ms's own rules; the court portal itself is not used.
 */
export default function CourtCaseShow({ workspace, error, done }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), t(`courtCases.case.${key}`));
    const { case: courtCase, can, review } = workspace;
    const download = (kind: string, id?: number) => route('employee.court-cases.download', id === undefined ? { case: courtCase.id, kind } : { case: courtCase.id, kind, id });

    const message = useForm({ body: '' });
    const upload = useForm({ label: '', file: null as File | null });

    const sendMessage = (event: FormEvent) => {
        event.preventDefault();
        message.post(route('employee.court-cases.messages.store', courtCase.id), { preserveScroll: true, onSuccess: () => message.reset() });
    };

    const sendFile = (event: FormEvent) => {
        event.preventDefault();
        upload.post(route('employee.court-cases.files.store', courtCase.id), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
    };

    async function remove(url: string, question: string) {
        const { confirmed } = await confirm({ title: label('removeTitle'), description: question, confirmLabel: label('remove'), cancelLabel: label('cancel'), variant: 'danger' });
        if (confirmed) {
            router.post(url, {}, { preserveScroll: true });
        }
    }

    const fact = (name: string, value: ReactNode) => (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{label(`fields.${name}`)}</dt>
            <dd className="mt-1 text-sm text-(--app-foreground)">{value}</dd>
        </div>
    );

    const fileRow = (key: string, name: string, at: string | null, href: string, onRemove?: () => void) => (
        <li key={key} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium text-(--app-foreground)">{name}</span>
                <LocalizedDateDisplay value={at} className="text-xs text-(--app-muted-foreground)" />
            </span>
            <span className="flex gap-3">
                <a href={href} className={actionLinkClassName()}>{label('download')}</a>
                {onRemove && <button type="button" onClick={onRemove} className={actionLinkClassName({ tone: 'danger' })}>{label('remove')}</button>}
            </span>
        </li>
    );

    const actions = (
        <div className="flex flex-wrap gap-2">
            {can.modify && <Link href={route('employee.court-cases.correct', courtCase.id)} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{label('correct')}</Link>}
            <a href={download('receipt')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('receipt')}</a>
        </div>
    );

    return (
        <PortalPage title={label('title', { number: courtCase.case_number })} description={courtCase.case_type ?? undefined} backHref={route('employee.court-cases')} actions={actions}>
            {error && <Alert tone="danger">{t(`courtCases.portal.errors.${error}`)}</Alert>}
            {done && <Alert tone="success">{label(`done.${done}`)}</Alert>}
            {workspace.merged_into && (
                <Alert tone="warning" title={label('mergedInto', { number: workspace.merged_into.case_number })}>
                    {workspace.merged_into.note && <p>{workspace.merged_into.note}</p>}
                    <Link href={route('employee.court-cases.show', workspace.merged_into.id)} className={actionLinkClassName()}>{label('openMerged')}</Link>
                </Alert>
            )}
            {review.status !== 'accepted' && (
                <Alert tone={review.status === 'rejected' ? 'danger' : review.status === 'returned' ? 'warning' : 'info'} title={label(`reviewNotice.${review.status}`)}>
                    {review.note && <p><span className="font-medium">{label('reviewNote')}:</span> {review.note}</p>}
                </Alert>
            )}

            <Card className="space-y-5">
                <CardTitle>{label('summary')}</CardTitle>
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {fact('status', <StatusBadge status={courtCase.status} label={t(`courtCases.portal.status.${courtCase.status}`)} />)}
                    {fact('review', <StatusBadge status={courtCase.review_status} label={t(`courtCases.portal.review.${courtCase.review_status}`)} />)}
                    {fact('filed', <LocalizedDateDisplay value={courtCase.filed_at} />)}
                    {fact('respondent', courtCase.respondent_name ?? '—')}
                </dl>
                <div className="space-y-1">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{label('fields.description')}</h3>
                    <RichTextViewer html={courtCase.description_html} className="text-sm text-(--app-foreground)" empty="—" />
                </div>
                {courtCase.relief_html && (
                    <div className="space-y-1">
                        <h3 className="text-sm font-semibold text-(--app-foreground)">{label('fields.relief')}</h3>
                        <RichTextViewer html={courtCase.relief_html} className="text-sm text-(--app-foreground)" />
                    </div>
                )}
            </Card>

            {(workspace.parties?.length ?? 0) > 0 && (
                <Card>
                    <CardHeader className="mb-2">
                        <CardTitle>{label('parties.title')}</CardTitle>
                        {workspace.role && <CardDescription>{label('parties.yourRole', { role: label(`parties.roles.${workspace.role}`) })}</CardDescription>}
                    </CardHeader>
                    <ul className="divide-y divide-(--app-border)">
                        {workspace.parties?.map((party) => (
                            <li key={party.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                <span className="min-w-0 space-y-0.5">
                                    <span className="block text-sm font-semibold text-(--app-foreground)">
                                        {party.name}{party.is_you && <span className="ml-1 font-normal text-(--app-muted-foreground)">({label('parties.you')})</span>}
                                    </span>
                                    {party.lawyers.map((lawyer) => (
                                        <span key={`${party.id}-${lawyer.license_number}`} className="block text-xs text-(--app-muted-foreground)">
                                            {label('parties.lawyer', { name: lawyer.name, license: lawyer.license_number ?? '—' })}
                                            {lawyer.status === 'pending' && <> · {label('parties.lawyerPending')}</>}
                                        </span>
                                    ))}
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    <StatusBadge status={party.role === 'lead' ? 'active' : 'info'} label={label(`parties.roles.${party.role}`)} />
                                    {party.status === 'invited' && <StatusBadge status="pending" label={label('parties.invited')} />}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {(workspace.merged_from?.length ?? 0) > 0 && (
                        <p className="mt-3 border-t border-(--app-border) pt-3 text-sm text-(--app-muted-foreground)">{label('mergedFrom', { numbers: (workspace.merged_from ?? []).join(', ') })}</p>
                    )}
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader className="mb-2"><CardTitle>{label('hearings')}</CardTitle></CardHeader>
                    {workspace.hearings.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{label('noHearings')}</p>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {workspace.hearings.map((hearing) => (
                                <li key={hearing.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                    <span className="min-w-0 space-y-0.5">
                                        <LocalizedDateDisplay value={hearing.hearing_at} withTime className="block text-sm font-semibold text-(--app-foreground)" />
                                        {hearing.type && <span className="block text-sm text-(--app-foreground)">{hearing.type}</span>}
                                        {hearing.location && <span className="block text-sm text-(--app-muted-foreground)">{label('hearingLocation', { place: hearing.location })}</span>}
                                        {hearing.notes && <span className="block text-sm text-(--app-muted-foreground)">{hearing.notes}</span>}
                                    </span>
                                    <a href={download('hearing', hearing.id)} className={actionLinkClassName()}>{label('addToCalendar')}</a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card>
                    <CardHeader className="mb-2"><CardTitle>{label('letters')}</CardTitle></CardHeader>
                    {workspace.letters.length === 0 && workspace.decisions.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{label('noLetters')}</p>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {workspace.decisions.map((decision) => fileRow(`decision-${decision.id}`, `${label('decision')}: ${decision.title}`, decision.decided_at, download('decision', decision.id)))}
                            {workspace.letters.map((letter) => fileRow(`letter-${letter.id}`, [letter.reference_number, letter.subject].filter(Boolean).join(' — ') || label('letter'), letter.created_at, download('letter', letter.id)))}
                        </ul>
                    )}
                </Card>
            </div>

            <Card className="space-y-4">
                <div>
                    <CardTitle>{label('documents')}</CardTitle>
                    <CardDescription>{label('documentsHelp')}</CardDescription>
                </div>
                {workspace.docs.length === 0 && workspace.files.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{label('noDocuments')}</p>
                ) : (
                    <ul className="divide-y divide-(--app-border)">
                        {workspace.docs.filter((doc) => doc.has_file).map((doc) => fileRow(`doc-${doc.id}`, doc.title ?? label('document'), doc.created_at, download('evidence', doc.id),
                            can.modify ? () => void remove(route('employee.court-cases.evidences.destroy', [courtCase.id, doc.id]), label('removeDocument')) : undefined))}
                        {workspace.files.map((file) => fileRow(`file-${file.id}`, file.name, file.created_at, download('file', file.id),
                            file.can_delete ? () => void remove(route('employee.court-cases.files.destroy', [courtCase.id, file.id]), label('removeDocument')) : undefined))}
                    </ul>
                )}
                <form onSubmit={sendFile} className="grid gap-4 border-t border-(--app-border) pt-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                    <FormField label={label('upload.name')} error={upload.errors.label}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={255} value={upload.data.label} onChange={(e) => upload.setData('label', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={label('upload.file')} required description={label('upload.fileHelp')} error={upload.errors.file}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2"
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)} />
                        )}
                    </FormField>
                    <Button type="submit" variant="outline" loading={upload.processing} disabled={upload.processing || upload.data.file === null}>{label('upload.submit')}</Button>
                </form>
            </Card>

            {workspace.witnesses.length > 0 && (
                <Card>
                    <CardHeader className="mb-2"><CardTitle>{label('witnesses')}</CardTitle></CardHeader>
                    <ul className="divide-y divide-(--app-border)">
                        {workspace.witnesses.map((witness) => (
                            <li key={witness.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium text-(--app-foreground)">{witness.full_name}</span>
                                    <span className="block text-xs text-(--app-muted-foreground)">{[witness.phone, witness.email, witness.address].filter(Boolean).join(' · ') || '—'}</span>
                                </span>
                                {can.modify && (
                                    <button type="button" onClick={() => void remove(route('employee.court-cases.witnesses.destroy', [courtCase.id, witness.id]), label('removeWitness'))}
                                        className={actionLinkClassName({ tone: 'danger' })}>{label('remove')}</button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Card className="space-y-4">
                <div>
                    <CardTitle>{label('responses')}</CardTitle>
                    <CardDescription>{label('responsesHelp')}</CardDescription>
                </div>
                {workspace.respondentResponses.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{label('noResponses')}</p>
                ) : (
                    <ul className="divide-y divide-(--app-border)">
                        {workspace.respondentResponses.map((response) => (
                            <li key={response.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                <span className="min-w-0 space-y-0.5">
                                    <span className="block text-sm font-medium text-(--app-foreground)">{[response.response_number, response.title].filter(Boolean).join(' — ') || label('response')}</span>
                                    {response.excerpt && <span className="block text-sm text-(--app-muted-foreground)">{response.excerpt}</span>}
                                    <LocalizedDateDisplay value={response.created_at} className="text-xs text-(--app-muted-foreground)" />
                                </span>
                                <Link href={route('employee.court-cases.responses.show', [courtCase.id, response.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('openResponse')}</Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card className="space-y-4">
                <CardTitle>{label('messages')}</CardTitle>
                {workspace.messages.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{label('noMessages')}</p>
                ) : (
                    <ol className="space-y-3">
                        {workspace.messages.map((item) => (
                            <li key={item.id} className={item.mine ? 'flex justify-end' : 'flex justify-start'}>
                                <div className={`max-w-[85%] rounded-lg px-4 py-3 text-sm ${item.mine ? 'bg-primary text-(--color-on-primary,#fff)' : 'bg-(--app-surface-muted) text-(--app-foreground)'}`}>
                                    <p className="text-xs font-semibold opacity-80">{item.mine ? label('you') : item.sender_name ?? label('courtStaff')}</p>
                                    <p className="mt-1 whitespace-pre-line">{item.body}</p>
                                    <LocalizedDateDisplay value={item.created_at} withTime className="mt-1 block text-xs opacity-75" />
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                <form onSubmit={sendMessage} className="space-y-3 border-t border-(--app-border) pt-4">
                    <FormField label={label('messageLabel')} required error={message.errors.body}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required rows={3} maxLength={5000} value={message.data.body} onChange={(e) => message.setData('body', e.target.value)} />
                        )}
                    </FormField>
                    <div className="flex justify-end">
                        <Button type="submit" variant="primary" loading={message.processing} disabled={message.processing || message.data.body.trim() === ''}>{label('send')}</Button>
                    </div>
                </form>
            </Card>
        </PortalPage>
    );
}
