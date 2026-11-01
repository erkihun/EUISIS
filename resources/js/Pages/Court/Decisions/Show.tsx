import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, Button, Card, FormField, Select, Textarea, badgeClassName, buttonClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import type { DecisionPanelSeat, DecisionReviewRow, DecisionShowProps } from '@/court/types/decisions';
import { OUTCOME_TONES, useDecisionText } from './useDecisionText';

const NOTE_OUTCOMES = ['reject', 'improve'];

/** A small "label over value" tile from the meta row. */
function MetaTile({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3">
            <p className="text-xs text-(--app-muted-foreground)">{label}</p>
            <p className="font-semibold text-(--app-foreground)">{children}</p>
        </div>
    );
}

/** One seat of the judicial panel: its number, the judge and the recorded vote. */
function PanelSeat({ seat }: { seat: DecisionPanelSeat }) {
    const { t, fill, outcomeLabel } = useDecisionText();
    return (
        <div className="space-y-1 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3">
            <div className="text-sm font-medium text-(--app-foreground)">{fill(t('cm.decisions.judges.judge'), { number: String(seat.number) })}</div>
            <div className="text-sm text-(--app-foreground)">{seat.name ?? '—'}</div>
            <div className="text-xs text-(--app-muted-foreground)">{fill(t('cm.decisions.judges.vote'), { vote: seat.vote ? outcomeLabel(seat.vote) : '—' })}</div>
        </div>
    );
}

/** One review: the reviewer, the outcome chip, the note, when, and the owner's update / delete actions. */
function ReviewCard({ decisionId, review, locked }: { decisionId: number; review: DecisionReviewRow; locked: boolean }) {
    const { t, text, outcomeLabel } = useDecisionText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState(false);

    const remove = async () => {
        const { confirmed } = await confirm({
            title: text('review_delete_title'),
            description: t('cm.decisions.reviews.delete_confirm'),
            confirmLabel: t('cm.decisions.reviews.delete'),
            cancelLabel: t('cm.decisions.confirm_cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.delete(route('court.decisions.reviews.destroy', [decisionId, review.id]), { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <div className="space-y-2 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <p className="text-xs text-(--app-muted-foreground)">{t('cm.decisions.reviews.reviewer')}</p>
                    <p className="text-sm font-semibold text-(--app-foreground)">{review.reviewer_name ?? '—'}</p>
                </div>
                <span className={badgeClassName(OUTCOME_TONES[review.outcome] ?? 'neutral')}>{outcomeLabel(review.outcome)}</span>
            </div>
            <div className="whitespace-pre-line text-sm text-(--app-foreground)">{review.review_note ?? '—'}</div>
            <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={review.updated_at} withTime /></div>
            {review.is_owner && !locked && (
                <div className="flex items-center gap-2 border-t border-(--app-border) pt-2">
                    <Link href={route('court.decisions.reviews.edit', [decisionId, review.id])} className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.decisions.reviews.update')}</Link>
                    <button type="button" disabled={busy} onClick={() => void remove()} className={buttonClassName({ variant: 'destructiveOutline', size: 'xs' })}>{t('cm.decisions.reviews.delete')}</button>
                </div>
            )}
        </div>
    );
}

/** The "Add Review" form: the outcome, and the note it requires for a difference or an improvement. */
function AddReviewForm({ decisionId }: { decisionId: number }) {
    const { t } = useDecisionText();
    const form = useForm<{ outcome: string; review_note: string }>({ outcome: 'approve', review_note: '' });
    const needsNote = NOTE_OUTCOMES.includes(form.data.outcome);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.decisions.reviews.store', decisionId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="rounded-control border border-dashed border-(--app-border) p-4">
            <h3 className="mb-2 text-sm font-semibold text-(--app-foreground)">{t('cm.decisions.reviews.add')}</h3>
            <form onSubmit={submit} className="space-y-3">
                <FormField id="review-outcome" label={t('cm.decisions.reviews.outcome')} error={form.errors.outcome}>
                    {({ id }) => (
                        <Select id={id} value={form.data.outcome} onChange={(event) => form.setData('outcome', event.target.value)} className="w-full">
                            <option value="approve">{t('cm.decisions.reviews.approve')}</option>
                            <option value="reject">{t('cm.decisions.reviews.reject')}</option>
                            <option value="improve">{t('cm.decisions.reviews.improve')}</option>
                        </Select>
                    )}
                </FormField>
                {needsNote && (
                    <FormField id="review-note" label={t('cm.decisions.reviews.review_note')} required error={form.errors.review_note}>
                        {({ id }) => (
                            <Textarea id={id} rows={3} required value={form.data.review_note} onChange={(event) => form.setData('review_note', event.target.value)}
                                placeholder={form.data.outcome === 'improve' ? t('cm.decisions.reviews.note_placeholder_improve') : t('cm.decisions.reviews.note_placeholder_reject')} className="w-full" />
                        )}
                    </FormField>
                )}
                <div className="flex justify-end">
                    <Button type="submit" variant="primary" loading={form.processing}>{t('cm.decisions.reviews.submit')}</Button>
                </div>
            </form>
        </div>
    );
}

/**
 * The decision page: header with the case and date, the meta and parties, the
 * judicial panel (author first), the status, the final-output card (approve
 * once published; change the status until then; generate or download the PDF
 * from a template), the decision text and the reviews with their form.
 */
export default function DecisionShow({ decision, reviews, templates, statuses, can }: DecisionShowProps) {
    const { t, text, fill, locale } = useDecisionText();
    const { confirm } = useConfirm();
    const calendar = useCalendarSystem();
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    // "Approved by :user on :date": the date is a string here because Amharic places it mid-sentence.
    const approvedBadge = decision.approved
        ? fill(t('cm.decisions.approved_badge'), { user: decision.approver_name ?? '—', date: decision.approved_at ? (formatDateDisplay(decision.approved_at, calendar, locale) || '—') : '—' })
        : null;
    const title = decision.name ?? t('cm.decisions.show.title');
    const isPublished = decision.status === 'published';
    const [selectedStatus, setSelectedStatus] = useState(decision.status);
    const [busy, setBusy] = useState(false);

    const statusLabel = (status: string) => {
        const label = t(`cm.decisions.status.${status}`);
        return label.startsWith('cm.') ? status : label;
    };

    const changeStatus = async () => {
        const publishing = selectedStatus === 'published';
        const { confirmed } = await confirm({
            title: t('cm.decisions.status_change'),
            description: publishing ? t('cm.decisions.publish_confirm') : t('cm.decisions.status_change_confirm'),
            confirmLabel: publishing ? t('cm.decisions.publish') : t('cm.decisions.status_change'),
            cancelLabel: t('cm.decisions.confirm_cancel'),
            variant: publishing ? 'warning' : 'default',
        });
        if (!confirmed) return;
        router.patch(route('court.decisions.status', decision.id), { status: selectedStatus }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    const approve = async () => {
        const { confirmed } = await confirm({
            title: t('cm.decisions.approve'),
            description: t('cm.decisions.approve_confirm'),
            confirmLabel: t('cm.decisions.approve'),
            cancelLabel: t('cm.decisions.confirm_cancel'),
            variant: 'success',
        });
        if (!confirmed) return;
        router.patch(route('court.decisions.approve', decision.id), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                description={`${text('case_prefix')}: ${decision.case_number ?? '—'}`}
                backHref={route('court.decisions.index')}
                actions={can.edit ? <Link href={route('court.decisions.edit', decision.id)} className={buttonClassName({})}>{t('cm.decisions.index.edit')}</Link> : undefined}
            />
        )}>
            <Head title={title} />

            <div className="space-y-6">
                <Card className="space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-(--app-muted-foreground)"><LocalizedDateDisplay value={decision.decision_date} /></p>
                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-(--app-muted-foreground)">{t('cm.app.Status')}:</span>
                            <span className={badgeClassName(isPublished ? 'success' : 'neutral')}>{statusLabel(decision.status)}</span>
                        </div>
                    </div>

                    <div className="grid gap-4 text-sm md:grid-cols-3">
                        <MetaTile label={t('cm.decisions.fields.case_file_number')}>{decision.case_file_number ?? decision.case_number ?? '—'}</MetaTile>
                        <MetaTile label={t('cm.decisions.fields.case')}>
                            {decision.case_id
                                ? <Link href={route('court.cases.show', decision.case_id)} className="text-primary hover:underline">{decision.case_number ?? '—'}</Link>
                                : (decision.case_number ?? '—')}
                        </MetaTile>
                        <MetaTile label={t('cm.decisions.fields.decision_date')}><LocalizedDateDisplay value={decision.decision_date} /></MetaTile>
                    </div>

                    <div className="grid gap-4 text-sm md:grid-cols-2">
                        <div className="rounded-control border border-(--app-border) p-4">
                            <p className="text-(--app-muted-foreground)">{t('cm.decisions.fields.applicant_label')}</p>
                            <p className="mt-1 font-semibold text-(--app-foreground)">{decision.applicant_full_name ?? '—'}</p>
                        </div>
                        <div className="rounded-control border border-(--app-border) p-4">
                            <p className="text-(--app-muted-foreground)">{t('cm.decisions.fields.respondent_label')}</p>
                            <p className="mt-1 font-semibold text-(--app-foreground)">{decision.respondent_full_name ?? '—'}</p>
                        </div>
                    </div>

                    <section className="space-y-3 rounded-control border border-(--app-border) p-4">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.decisions.judges.judicial_panel')}</h2>
                            <span className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.decisions.judges.panel')}</span>
                        </div>
                        <div className="grid gap-3 md:grid-cols-3">
                            {decision.panel.map((seat) => <PanelSeat key={seat.number} seat={seat} />)}
                        </div>
                    </section>

                    {(pageErrors.approve || pageErrors.status) && <Alert tone="danger">{pageErrors.approve ?? pageErrors.status}</Alert>}
                    {!decision.panel_complete && !pageErrors.approve && !pageErrors.status && (can.changeStatus || can.approve) && (
                        <Alert tone="warning">{t('cm.decisions.validation.publish_requires_panel')}</Alert>
                    )}

                    <section className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div className="space-y-3">
                                <div>
                                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.decision_templates.output.heading')}</h2>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.decision_templates.output.choose_template')}</p>
                                </div>

                                {approvedBadge && (
                                    <p className={badgeClassName('success', 'inline-flex px-3 py-2 text-xs normal-case tracking-normal')}>{approvedBadge}</p>
                                )}

                                {isPublished ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Alert tone="warning" className="text-xs">{t('cm.decisions.published_locked')}</Alert>
                                        {can.approve && !decision.approved && (
                                            <Button type="button" variant="primary" onClick={() => void approve()} loading={busy}>{t('cm.decisions.approve')}</Button>
                                        )}
                                    </div>
                                ) : can.changeStatus && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <label htmlFor="decision-status" className="text-xs font-medium text-(--app-muted-foreground)">{t('cm.decisions.fields.status')}:</label>
                                        <Select id="decision-status" value={selectedStatus} onChange={(event) => setSelectedStatus(event.target.value)} className="w-auto">
                                            {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                                        </Select>
                                        <Button type="button" variant="secondary" onClick={() => void changeStatus()} loading={busy}>{t('cm.decisions.status_change')}</Button>
                                    </div>
                                )}
                            </div>

                            {/* A plain GET form: the PDF streams or downloads in a new tab, outside Inertia. */}
                            <form method="get" action={route('court.decisions.output', decision.id)} target="_blank" className="flex flex-wrap items-center gap-2" title={text('output_new_tab')}>
                                <label htmlFor="decision-output-template" className="sr-only">{t('cm.decision_templates.output.select')}</label>
                                <Select id="decision-output-template" name="template_id" defaultValue="" className="w-auto">
                                    <option value="">{t('cm.decision_templates.output.default_layout')}</option>
                                    {templates.map((template) => <option key={template.id} value={template.id}>{template.title}</option>)}
                                </Select>
                                <button type="submit" name="mode" value="stream" className={buttonClassName({ variant: 'primary' })}>{t('cm.decision_templates.output.generate')}</button>
                                <button type="submit" name="mode" value="download" className={buttonClassName({ variant: 'outline' })}>{t('cm.decision_templates.output.download')}</button>
                            </form>
                        </div>
                    </section>

                    <div className="grid items-start gap-6 lg:grid-cols-3">
                        <Card className="overflow-hidden p-0 lg:col-span-2">
                            <div className="flex items-center justify-between gap-3 border-b border-(--app-border) bg-(--app-surface-muted) px-5 py-4">
                                <h2 className="text-base font-bold text-(--app-foreground)">{t('cm.decisions.fields.decision_content')}</h2>
                                <span className={cx(badgeClassName(isPublished ? 'success' : 'neutral'), 'hidden sm:inline-flex')}>{statusLabel(decision.status)}</span>
                            </div>
                            <div className="px-4 py-5 sm:px-6">
                                <div className="overflow-x-auto rounded-control border border-(--app-border) bg-(--app-surface) p-4 shadow-inner sm:p-6">
                                    <RichTextViewer html={decision.decision_content} empty={<span className="text-sm text-(--app-muted-foreground)">—</span>} className="text-justify leading-[1.85]" />
                                </div>
                            </div>
                        </Card>

                        <section className="space-y-4 rounded-control border border-(--app-border) p-4 lg:col-span-1">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.decisions.reviews.heading')}</h2>
                                <span className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.decisions.reviews.legend')}</span>
                            </div>
                            {reviews.length === 0
                                ? <p className="text-sm text-(--app-muted-foreground)">{t('cm.decisions.reviews.none')}</p>
                                : <div className="flex flex-col gap-3">{reviews.map((review) => <ReviewCard key={review.id} decisionId={decision.id} review={review} locked={isPublished} />)}</div>}
                            {can.review && !isPublished && <AddReviewForm decisionId={decision.id} />}
                        </section>
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
