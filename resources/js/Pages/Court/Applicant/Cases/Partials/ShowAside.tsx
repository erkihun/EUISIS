import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, FormField, Textarea, badgeClassName, buttonClassName, cx } from '@/Components/ui';
import { LETTER_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type {
    ApplicantCaseAudit, ApplicantCaseHearing, ApplicantCaseLetter, ApplicantCaseMessage, ApplicantCaseRespondentResponse,
} from '@/court/types/applicantCases';
import SectionCard, { EmptyBox } from '../../../Cases/Show/SectionCard';
import { AuditIcon, CalendarIcon, ChatIcon, LetterIcon, ResponsesIcon, UserIcon } from '../../../Cases/Show/icons';

/** The case's hearings, each with its calendar (.ics) download. */
export function HearingsCard({ caseId, hearings }: { caseId: number; hearings: ApplicantCaseHearing[] }) {
    const { t } = useApplicantCaseText();

    return (
        <SectionCard id="hearings" title={t('cm.cases.hearings.title')} icon={<CalendarIcon className="h-4 w-4" />} count={hearings.length}>
            {hearings.length === 0 ? <EmptyBox>{t('cm.cases.no_hearings_scheduled')}</EmptyBox> : (
                <ul className="max-h-80 space-y-3 overflow-y-auto pr-1">
                    {hearings.map((hearing) => (
                        <li key={hearing.id} className="rounded-control border border-(--app-border) p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium text-(--app-foreground)"><LocalizedDateDisplay value={hearing.hearing_at} withTime /></span>
                                {hearing.type && <span className={badgeClassName('info')}>{hearing.type}</span>}
                            </div>
                            <div className="mt-1 text-xs text-(--app-muted-foreground)">{hearing.location || '—'}</div>
                            {hearing.notes && <p className="mt-2 text-xs text-(--app-muted-foreground)">{hearing.notes}</p>}
                            <a href={route('court.applicant.cases.hearings.ics', [caseId, hearing.id])} className={buttonClassName({ variant: 'outline', size: 'xs', className: 'mt-3' })}>
                                <CalendarIcon className="mr-1 h-3 w-3" />{t('cm.cases.add_to_calendar')}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

/** The approved letters of the case, each opening its signed preview. */
export function LettersCard({ letters }: { letters: ApplicantCaseLetter[] }) {
    const { t, tx } = useApplicantCaseText();

    return (
        <SectionCard id="letters" title={t('cm.cases.letters_section.title')} icon={<LetterIcon className="h-4 w-4" />} count={letters.length}>
            {letters.length === 0 ? <EmptyBox>{t('cm.cases.letters_section.empty')}</EmptyBox> : (
                <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
                    {letters.map((letter) => (
                        <div key={letter.id} className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-(--app-foreground)">{letter.subject ?? letter.template_title ?? t('cm.cases.letters_section.letter')}</span>
                                        <span className={badgeClassName(LETTER_STATUS_TONES[letter.approval_status] ?? 'neutral')}>
                                            {tx(`cm.cases.letters_section.status.${letter.approval_status}`, letter.approval_status)}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-(--app-muted-foreground)">
                                        <span>{t('cm.cases.letters_section.reference')}: {letter.reference_number || '—'}</span>
                                        <span>{t('cm.cases.letters_section.template')}: {letter.template_title || '—'}</span>
                                        <span>{t('cm.cases.letters_section.author')}: {letter.author_name || '—'}</span>
                                        <span>{t('cm.cases.letters_section.created')}: <LocalizedDateDisplay value={letter.created_at} withTime /></span>
                                    </div>
                                </div>
                                <a href={letter.preview_url} target="_blank" rel="noopener" className="shrink-0 text-xs font-semibold text-primary underline hover:no-underline">{t('cm.cases.view')}</a>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

/** The respondent's accepted responses: open one (and reply to it there), or download its PDF. */
export function RespondentResponsesCard({ caseId, caseNumber, responses }: { caseId: number; caseNumber: string; responses: ApplicantCaseRespondentResponse[] }) {
    const { t } = useApplicantCaseText();

    return (
        <SectionCard id="respondent-responses" title={t('cm.cases.respondent_responses')} icon={<ResponsesIcon className="h-4 w-4" />} count={responses.length}>
            {responses.length === 0 ? <EmptyBox>{t('cm.cases.respondent_responses_empty')}</EmptyBox> : (
                <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
                    {responses.map((response) => (
                        <div key={response.id} className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 space-y-1">
                                    <div className="font-semibold text-(--app-foreground)">{response.title ?? t('cm.cases.respondent_response')}</div>
                                    <div className="text-xs text-(--app-muted-foreground)">{t('cm.cases.case_number')}: {response.case_number || caseNumber}</div>
                                    {response.response_number && (
                                        <div className="text-xs font-semibold text-(--app-muted-foreground)">{t('cm.respondent.response_number_label')}: {response.response_number}</div>
                                    )}
                                </div>
                                <div className="shrink-0 text-right text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={response.created_at} withTime /></div>
                            </div>
                            {response.excerpt && <p className="mt-2 text-sm text-(--app-foreground)">{response.excerpt}</p>}
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <Link href={route('court.applicant.cases.respondentResponses.show', [caseId, response.id])} className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.cases.view')}</Link>
                                {response.has_pdf && (
                                    <a href={route('court.applicant.respondent.responses.download', response.id)} className={buttonClassName({ variant: 'ghost', size: 'xs' })}>{t('cm.cases.download_pdf')}</a>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

/** The message thread with the court, and the applicant's composer. */
export function MessagesCard({ caseId, messages }: { caseId: number; messages: ApplicantCaseMessage[] }) {
    const { t, fill } = useApplicantCaseText();
    const form = useForm<{ body: string }>({ body: '' });

    const send = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.cases.messages.post', caseId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <SectionCard
            id="messages"
            icon={<ChatIcon className="h-4 w-4" />}
            count={`${messages.length} ${t('cm.cases.navigation.messages')}`}
            title={(
                <span>
                    <span className="block">{t('cm.cases.messages_section.title')}</span>
                    <span className="block text-[11px] font-normal text-(--app-muted-foreground)">{fill(t('cm.cases.messages_section.subtitle'), { count: messages.length })}</span>
                </span>
            )}
        >
            <div className="max-h-96 space-y-3 overflow-y-auto pr-1.5">
                {messages.length === 0 ? <EmptyBox>{t('cm.cases.no_messages_yet')}</EmptyBox> : messages.map((message) => (
                    <div key={message.id} className={cx('flex', message.mine ? 'justify-end' : 'justify-start')}>
                        <div className={cx('w-full max-w-[85%] rounded-2xl border px-4 py-3', message.mine ? 'border-primary/30 bg-primary/5' : 'border-(--app-border) bg-(--app-surface)')}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-3">
                                    <div className="grid h-8 w-8 place-items-center rounded-full border border-(--app-border) bg-(--app-surface) text-[11px] font-semibold text-(--app-muted-foreground)">
                                        {message.mine ? t('cm.cases.you') : <UserIcon className="h-4 w-4 text-orange-500" />}
                                    </div>
                                    <div>
                                        <p className="text-[11px] uppercase tracking-wide text-(--app-muted-foreground)">
                                            {message.mine ? t('cm.cases.you') : (message.sender_name ?? t('cm.cases.court_staff'))}
                                        </p>
                                        <p className="text-xs font-semibold leading-tight text-(--app-foreground)"><LocalizedDateDisplay value={message.created_at} withTime /></p>
                                    </div>
                                </div>
                                <span className={badgeClassName(message.mine ? 'info' : 'warning')}>{message.mine ? t('cm.cases.sent') : t('cm.cases.received')}</span>
                            </div>
                            <p className="mt-2 whitespace-pre-line break-words text-sm text-(--app-foreground)">{message.body}</p>
                        </div>
                    </div>
                ))}
            </div>

            <form onSubmit={send} className="space-y-3 border-t border-(--app-border) pt-4">
                <FormField label={t('cm.cases.new_message')} error={form.errors.body}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} name="body" rows={4} value={form.data.body} onChange={(event) => form.setData('body', event.target.value)}
                            placeholder={t('cm.cases.write_message_placeholder')} required maxLength={5000} aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                    )}
                </FormField>
                <Button type="submit" variant="primary" className="w-full" loading={form.processing} disabled={!form.data.body.trim()}>{t('cm.cases.send')}</Button>
            </form>
        </SectionCard>
    );
}

/** The case audit trail: what happened, when, by whom, with its details. */
export function AuditCard({ audits }: { audits: ApplicantCaseAudit[] }) {
    const { t } = useApplicantCaseText();
    const actionLabel = (action: string) => {
        const words = action.replace(/_/g, ' ');
        return words.charAt(0).toUpperCase() + words.slice(1);
    };
    const actor = (audit: ApplicantCaseAudit) => {
        if (audit.actor_name) return audit.actor_id ? `${audit.actor_name} (#${audit.actor_id})` : audit.actor_name;
        const type = audit.actor_type ?? t('cm.cases.system');
        return audit.actor_id ? `${type} (#${audit.actor_id})` : type;
    };

    return (
        <SectionCard id="audits" title={t('cm.cases.case_audit_trail')} icon={<AuditIcon className="h-4 w-4" />} count={audits.length}>
            {audits.length === 0 ? <EmptyBox>{t('cm.cases.no_audit_records')}</EmptyBox> : (
                <div className="max-h-80 space-y-3 overflow-y-auto">
                    {audits.map((audit) => (
                        <div key={audit.id} className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3">
                            <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                                <LocalizedDateDisplay value={audit.created_at} withTime />
                                <span className={badgeClassName('neutral')}>{actionLabel(audit.action)}</span>
                            </div>
                            <div className="mt-1 text-[11px] text-(--app-muted-foreground)">{t('cm.cases.audit_actor')} {actor(audit)}</div>
                            {audit.meta && (
                                <pre className="mt-2 overflow-x-auto whitespace-pre-wrap rounded border border-(--app-border) bg-(--app-surface) px-2 py-1 text-[11px]">{audit.meta}</pre>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}
