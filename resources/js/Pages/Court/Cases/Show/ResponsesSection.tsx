import { useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, FieldError, FormLabel, Textarea, cx } from '@/Components/ui';
import type { ApplicantResponseReplyRow, RespondentResponseRow } from '@/court/types/caseDetail';
import DocumentSheet from './DocumentSheet';
import SectionCard, { EmptyBox } from './SectionCard';
import { REVIEW_TONES, useCaseText } from './useCaseText';
import { EyeIcon, RepliesIcon, ResponsesIcon } from './icons';

type ReviewForm = { decision: 'accept' | 'return'; review_note: string };

/** The accept / return form under a response or a reply; posts to the given route. */
function ReviewForm({ url, initialNote, returnLabel }: { url: string; initialNote: string | null; returnLabel: string }) {
    const { t, text } = useCaseText();
    const form = useForm<ReviewForm>({ decision: 'accept', review_note: initialNote ?? '' });

    const decide = (decision: 'accept' | 'return') => {
        form.transform((data) => ({ ...data, decision }));
        form.patch(url, { preserveScroll: true });
    };

    return (
        <form onSubmit={(event) => { event.preventDefault(); decide('accept'); }} className="space-y-2 border-t border-(--app-border) bg-(--app-surface-muted) px-4 py-4 sm:px-5 print:hidden">
            <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{text('review_title')}</p>
            <FormLabel className="text-xs">{t('cm.cases.reviewer_note')}</FormLabel>
            <Textarea rows={2} value={form.data.review_note} onChange={(event) => form.setData('review_note', event.target.value)} placeholder={t('cm.cases.show.add_reason_note')} className="w-full bg-(--app-surface)" aria-invalid={form.errors.review_note ? true : undefined} />
            <FieldError>{form.errors.review_note}</FieldError>
            <div className="flex flex-wrap items-center justify-end gap-2">
                <Button size="sm" variant="primary" className="bg-amber-600 hover:bg-amber-700" loading={form.processing && form.data.decision === 'return'} disabled={form.processing} onClick={() => decide('return')}>{returnLabel}</Button>
                <Button size="sm" variant="primary" className="bg-emerald-600 hover:bg-emerald-700" loading={form.processing && form.data.decision === 'accept'} disabled={form.processing} onClick={() => decide('accept')}>{t('cm.cases.show.accept')}</Button>
            </div>
        </form>
    );
}

function PdfFrame({ url, title }: { url: string; title: string }) {
    const { text } = useCaseText();

    return (
        <div className="space-y-2">
            <div className="overflow-hidden rounded-control border border-(--app-border) bg-(--app-surface)">
                <iframe src={`${url}#toolbar=0&navpanes=0`} loading="lazy" className="w-full" style={{ height: 'min(80vh, 980px)', minHeight: 520 }} title={title} />
            </div>
            <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                <EyeIcon className="h-4 w-4" /> {text('open_new_tab')}
            </a>
        </div>
    );
}

const escapeHtml = (value: string): string =>
    value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** A plain-text reply as sheet paragraphs: one per line, as it was typed. */
const plainTextHtml = (value: string | null): string =>
    (value ?? '').split(/\r?\n/).map((line) => line.trim()).filter((line) => line !== '').map((line) => `<p>${escapeHtml(line)}</p>`).join('');

interface PaperProps {
    index: number;
    title: string;
    status: string;
    createdAt: string | null;
    meta: Array<[string, string]>;
    html: string;
    emptyText: string;
    pdfUrl: string | null;
    reviewNote: string | null;
    review: ReactNode;
}

/**
 * One filed paper (a response or a reply), numbered in the order it was filed
 * (the lists come newest first): a numbered header with its review
 * state and details, the text as a justified sheet or the filed PDF, the
 * reviewer's note, and the review form at its foot while it waits.
 */
function FiledPaper({ index, title, status, createdAt, meta, html, emptyText, pdfUrl, reviewNote, review }: PaperProps) {
    const { t, text, reviewLabel } = useCaseText();
    const hasText = html !== '';
    const [view, setView] = useState<'text' | 'pdf'>(pdfUrl ? 'pdf' : 'text');
    const tone = REVIEW_TONES[status] ?? 'success';

    return (
        <article className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface)">
            <header className="flex flex-wrap items-start gap-3 border-b border-(--app-border) bg-(--app-surface-muted) px-4 py-3 sm:px-5">
                <span aria-hidden="true" className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-bold text-primary">{index}</span>
                <div className="min-w-0 flex-1 space-y-1.5">
                    <h4 className="text-[15px] font-semibold leading-snug text-(--app-foreground)">{title}</h4>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                        <UiStatusBadge tone={tone}>{reviewLabel(status)}</UiStatusBadge>
                        <LocalizedDateDisplay value={createdAt} withTime fallback="" />
                        {meta.map(([label, value]) => <span key={label}><span className="text-(--app-muted-foreground)">{label}:</span> <span className="font-medium text-(--app-foreground)">{value}</span></span>)}
                    </div>
                </div>
                {pdfUrl && hasText && (
                    <div role="tablist" aria-label={title} className="inline-flex shrink-0 rounded-control border border-(--app-border) bg-(--app-surface) p-0.5 text-xs font-semibold print:hidden">
                        {(['pdf', 'text'] as const).map((option) => (
                            <button
                                key={option}
                                type="button"
                                role="tab"
                                aria-selected={view === option}
                                onClick={() => setView(option)}
                                className={cx('rounded-[calc(var(--radius-control,0.5rem)-2px)] px-3 py-1.5 transition-colors', view === option ? 'bg-primary text-white' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}
                            >
                                {option === 'pdf' ? text('view_pdf') : text('view_text')}
                            </button>
                        ))}
                    </div>
                )}
            </header>

            <div className="space-y-3 p-3 sm:p-5">
                {reviewNote && (
                    <div className={cx('rounded-control border-l-4 px-3 py-2 text-sm', status === 'accepted' ? 'border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100')}>
                        <span className="font-semibold">{t('cm.cases.reviewer_note')}</span> {reviewNote}
                    </div>
                )}
                {view === 'pdf' && pdfUrl
                    ? <PdfFrame url={pdfUrl} title={title} />
                    : <DocumentSheet html={html} empty={emptyText} foldAt={32} />}
            </div>

            {review}
        </article>
    );
}

/** The respondent's responses to the case, each with its PDF and the reviewer's accept / return. */
export function RespondentResponsesSection({ caseNumber, responses, canReview }: { caseNumber: string; responses: RespondentResponseRow[]; canReview: boolean }) {
    const { t, text } = useCaseText();

    return (
        <SectionCard id="respondent-responses" title={t('cm.cases.navigation.respondent_responses')} icon={<ResponsesIcon />} count={`${responses.length} ${t('cm.cases.respondent_responses')}`}>
            {responses.length === 0 ? <EmptyBox>{t('cm.recordes.messages.no_responses')}</EmptyBox> : (
                <div className="space-y-5">
                    {responses.map((response, i) => {
                        const meta: Array<[string, string]> = [[t('cm.cases.case_number'), response.case_number ?? caseNumber]];
                        if (response.response_number) meta.unshift([text('response_number'), response.response_number]);

                        return (
                            <FiledPaper
                                key={response.id}
                                index={responses.length - i}
                                title={response.title ?? t('cm.cases.respondent_response')}
                                status={response.review_status}
                                createdAt={response.created_at}
                                meta={meta}
                                html={response.description_html}
                                emptyText={t('cm.cases.no_description')}
                                pdfUrl={response.pdf_url}
                                reviewNote={response.review_note}
                                review={canReview && ['awaiting_review', 'returned'].includes(response.review_status)
                                    ? <ReviewForm url={route('court.respondent-responses.review', response.id)} initialNote={response.review_note} returnLabel={t('cm.cases.show.return')} />
                                    : null}
                            />
                        );
                    })}
                </div>
            )}
        </SectionCard>
    );
}

/** The applicant's replies to respondent responses (people with cases.response-replies.manage). */
export function ResponseRepliesSection({ caseNumber, replies }: { caseNumber: string; replies: ApplicantResponseReplyRow[] }) {
    const { t, text } = useCaseText();
    const notAvailable = t('cm.cases.labels.not_available');

    return (
        <SectionCard id="response-of-response" title={t('cm.cases.navigation.response_of_response')} icon={<RepliesIcon />} count={`${replies.length} ${text('replies_count')}`}>
            {replies.length === 0 ? <EmptyBox>{t('cm.respondent.no_applicant_response_replies')}</EmptyBox> : (
                <div className="space-y-5">
                    {replies.map((reply, i) => (
                        <FiledPaper
                            key={reply.id}
                            index={replies.length - i}
                            title={t('cm.respondent.response_of_response')}
                            status={reply.review_status}
                            createdAt={reply.created_at}
                            meta={[
                                [t('cm.respondent.response_number_label'), reply.response_number || notAvailable],
                                [t('cm.cases.applicant_full_name'), reply.applicant_name || notAvailable],
                                [t('cm.cases.case_number'), caseNumber],
                            ]}
                            html={plainTextHtml(reply.description)}
                            emptyText={t('cm.cases.no_description')}
                            pdfUrl={reply.pdf_url}
                            reviewNote={reply.review_note}
                            review={['awaiting_review', 'returned'].includes(reply.review_status)
                                ? <ReviewForm url={route('court.applicant-response-replies.review', reply.id)} initialNote={reply.review_note} returnLabel={t('cm.cases.show.give_correction')} />
                                : null}
                        />
                    ))}
                </div>
            )}
        </SectionCard>
    );
}
