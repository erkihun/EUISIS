import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import OrganizationFormDetails, { type OrganizationFormDetailsData } from '@/Components/ServiceFeedback/OrganizationFormDetails';
import {
    FeedbackStatusBadge,
    ratingTone,
    statusLabelKey,
    submitterKey,
    toneClasses,
    useNameLabel,
    type FeedbackSummary,
} from '@/Components/ServiceFeedback/feedbackUi';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ArrowLeftIcon, EyeIcon, EyeOffIcon, TrashIcon } from '@/Components/Icons';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Button, Textarea, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

type Feedback = FeedbackSummary & {
    reviewed_at: string | null;
    reviewed_by: string | null;
    review_note: string | null;
    target_logo_url?: string | null;
};

type HistoryRow = { id: string; from_status: string | null; to_status: string; actor: string | null; reason: string | null; transitioned_at: string | null };

type Props = {
    feedback: Feedback;
    /** Organization form: the service received and the questionnaire answers; null otherwise. */
    organizationForm?: OrganizationFormDetailsData | null;
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
    /** Every recorded change of state, oldest first. */
    history?: HistoryRow[];
    /** The moves the workflow allows from the current status (empty without the review grant). */
    allowedTransitions?: string[];
};

type Outcome = 'reviewed' | 'resolved' | 'closed';

const NOTE_LIMIT = 2000;

function initials(name: string | null | undefined): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export default function ServiceFeedbackShow({ feedback, organizationForm = null, can, history = [], allowedTransitions = [] }: Props): JSX.Element {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = useNameLabel();

    const isHidden = feedback.status === 'hidden';
    const tone = toneClasses[ratingTone(feedback.rating)];

    // Only the moves the workflow allows from the current status; none once closed or hidden.
    const allowed: Outcome[] = (['reviewed', 'resolved', 'closed'] as const).filter((id) => allowedTransitions.includes(id));
    const [outcome, setOutcome] = useState<Outcome>(allowed[0] ?? 'reviewed');
    const [note, setNote] = useState(feedback.review_note ?? '');
    const [processing, setProcessing] = useState(false);

    const busy = { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) };

    function submitReview() {
        router.post(
            route('service-feedback.admin.review', feedback.id),
            { status: outcome, review_note: note },
            { preserveScroll: true, ...busy },
        );
    }

    function toggleHide() {
        router.post(route('service-feedback.admin.hide', feedback.id), {}, { preserveScroll: true, ...busy });
    }

    async function destroy() {
        const result = await confirm({ title: t('confirmations.deleteWarning'), variant: 'danger' });
        if (!result.confirmed) return;

        router.delete(route('service-feedback.admin.destroy', feedback.id), busy);
    }

    const isOrganization = feedback.target_type === 'organization';
    const targetName = isOrganization ? label(feedback.target_organization ?? null, '') : feedback.employee?.name ?? '';
    const title = targetName
        ? t('serviceFeedback.feedbackFor').replace(':name', targetName)
        : t('serviceFeedback.feedbackDetail');

    const outcomes = ([
        { id: 'reviewed', label: t('serviceFeedback.statusReviewed'), hint: t('serviceFeedback.reviewedHint') },
        { id: 'resolved', label: t('serviceFeedback.statusResolved'), hint: t('serviceFeedback.resolvedHint') },
        { id: 'closed', label: t('serviceFeedback.statusClosed'), hint: t('serviceFeedback.closedHint') },
    ] as { id: Outcome; label: string; hint: string }[]).filter((option) => allowed.includes(option.id));

    const serviceLabel = [feedback.service_no, label(feedback.service_type, '') || feedback.service_name].filter(Boolean).join(' · ') || '—';
    const submitter = submitterKey(feedback);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-start gap-3">
                    <Link
                        href={route('service-feedback.admin.index')}
                        aria-label={t('common.back')}
                        className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control border border-(--app-border-strong) bg-(--app-surface) text-(--app-foreground) hover:bg-(--app-surface-muted)"
                    >
                        <ArrowLeftIcon aria-hidden="true" className="h-4 w-4" />
                    </Link>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="wrap-break-word text-page-title text-(--app-foreground)">{title}</h1>
                            <FeedbackStatusBadge status={feedback.status} />
                        </div>
                        <p className="mt-1 text-[13px] text-(--app-muted-foreground)">
                            {t(isOrganization ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')} · {t('serviceFeedback.submittedDate')}{' '}
                            <LocalizedDateDisplay value={feedback.created_at} withTime /> {t('serviceFeedback.viaQr')}
                        </p>
                    </div>
                </div>
            }
        >
            <Head title={t('serviceFeedback.feedbackDetail')} />

            <div className="space-y-6">
                {isHidden && (
                    <div role="status" className="flex items-center gap-2.5 rounded-card border border-(--app-border) bg-(--app-surface-muted) px-4 py-3 text-sm text-(--app-foreground)">
                        <EyeOffIcon aria-hidden="true" className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" />
                        {t('serviceFeedback.hiddenNotice')}
                    </div>
                )}

                <div className="grid items-start gap-5 lg:grid-cols-12">
                    {/* Main column */}
                    <div className="space-y-5 lg:col-span-8">
                        {/* What the client said */}
                        <section aria-labelledby="client-said" className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
                            <div className="flex items-center gap-5 p-5 sm:p-6">
                                <div className={cx('flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-panel sm:h-[88px] sm:w-[88px]', tone.soft)}>
                                    <span className="text-4xl font-bold leading-none tabular-nums">{feedback.rating}</span>
                                    <span className="mt-1 text-xs font-semibold">{t('serviceFeedback.outOfFive')}</span>
                                </div>
                                <div className="min-w-0 space-y-1.5">
                                    <h2 id="client-said" className="text-xs font-semibold uppercase tracking-[0.06em] text-(--app-muted-foreground)">
                                        {t('serviceFeedback.clientRating')}
                                    </h2>
                                    <RatingStars rating={feedback.rating} size="md" />
                                    <p className={cx('text-base font-semibold', tone.text)}>{t(`serviceFeedback.rating${feedback.rating}`)}</p>
                                </div>
                            </div>

                            <div className="px-5 pb-5 sm:px-6 sm:pb-6">
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.06em] text-(--app-muted-foreground)">{t('serviceFeedback.comment')}</h3>
                                {feedback.comment ? (
                                    <blockquote className="whitespace-pre-wrap rounded-card bg-(--app-surface-muted) px-4 py-3.5 text-[15px] leading-relaxed text-(--app-foreground)">
                                        {feedback.comment}
                                    </blockquote>
                                ) : (
                                    <p className="text-sm italic text-(--app-muted-foreground)">{t('serviceFeedback.noCommentRatingOnly')}</p>
                                )}
                            </div>

                            {/* Volunteered client details; absent for anonymous submissions. */}
                            <dl className="flex flex-wrap gap-x-8 gap-y-3 border-t border-(--app-border) bg-(--app-surface-muted) px-5 py-3.5 text-[13px] sm:px-6">
                                <div>
                                    <dt className="text-(--app-muted-foreground)">{t('serviceFeedback.client')}</dt>
                                    <dd className="font-medium text-(--app-foreground)">{submitter ? t(submitter) : feedback.client_name}</dd>
                                </div>
                                <div className="min-w-0">
                                    <dt className="text-(--app-muted-foreground)">{t('serviceFeedback.contact')}</dt>
                                    <dd className={cx('break-all', feedback.client_contact ? 'font-medium text-(--app-foreground)' : 'text-(--app-muted-foreground)')}>
                                        {feedback.identity_hidden ? t('serviceFeedback.identityHidden') : feedback.client_contact ?? t('serviceFeedback.notProvided')}
                                    </dd>
                                </div>
                                {!feedback.identity_hidden && feedback.client_email && (
                                    <div className="min-w-0">
                                        <dt className="text-(--app-muted-foreground)">{t('serviceFeedback.clientEmail')}</dt>
                                        <dd className="break-all font-medium text-(--app-foreground)">{feedback.client_email}</dd>
                                    </div>
                                )}
                                {!feedback.identity_hidden && feedback.submitter_organization && (
                                    <div className="min-w-0">
                                        <dt className="text-(--app-muted-foreground)">{t('serviceFeedback.submitterOrganization')}</dt>
                                        <dd className="font-medium text-(--app-foreground)">{feedback.submitter_organization}</dd>
                                    </div>
                                )}
                            </dl>
                        </section>

                        {organizationForm && <OrganizationFormDetails details={organizationForm} />}

                        {/* Review */}
                        {can.review && outcomes.length > 0 && (
                            <section aria-labelledby="review-heading" className="space-y-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-5 sm:p-6">
                                <div>
                                    <h2 id="review-heading" className="text-base font-semibold text-(--app-foreground)">{t('serviceFeedback.review')}</h2>
                                    <p className="mt-1 text-[13px] text-(--app-muted-foreground)">{t('serviceFeedback.reviewHint')}</p>
                                </div>

                                <fieldset>
                                    <legend className="mb-2 text-[13px] font-semibold text-(--app-foreground)">{t('serviceFeedback.outcome')}</legend>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        {outcomes.map((option) => {
                                            const selected = outcome === option.id;
                                            return (
                                                <label
                                                    key={option.id}
                                                    className={cx(
                                                        'flex cursor-pointer gap-3 rounded-card border px-4 py-3.5 transition-colors',
                                                        selected
                                                            ? 'border-primary bg-primary-50 ring-1 ring-primary dark:bg-primary-950'
                                                            : 'border-(--app-border) hover:border-(--app-border-strong)',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="outcome"
                                                        value={option.id}
                                                        checked={selected}
                                                        onChange={() => setOutcome(option.id)}
                                                        className="mt-0.5 h-4 w-4 border-(--app-border-strong) text-primary focus:ring-primary"
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-semibold text-(--app-foreground)">{option.label}</span>
                                                        <span className="block text-xs text-(--app-muted-foreground)">{option.hint}</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </fieldset>

                                <div>
                                    <div className="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]">
                                        <label htmlFor="review_note" className="font-semibold text-(--app-foreground)">
                                            {t('serviceFeedback.reviewNote')}{' '}
                                            <span className="font-normal text-(--app-muted-foreground)">({t('serviceFeedback.optional')})</span>
                                        </label>
                                        <span className="tabular-nums text-(--app-muted-foreground)" aria-live="polite">{note.length} / {NOTE_LIMIT}</span>
                                    </div>
                                    <Textarea
                                        id="review_note"
                                        rows={4}
                                        maxLength={NOTE_LIMIT}
                                        value={note}
                                        onChange={(event) => setNote(event.target.value)}
                                        placeholder={t('serviceFeedback.reviewNotePlaceholder')}
                                        className="resize-y leading-relaxed"
                                    />
                                </div>

                                <div className="flex justify-end">
                                    <Button type="button" variant="primary" size="lg" loading={processing} onClick={submitReview}>
                                        {outcome === 'resolved' ? t('serviceFeedback.markResolved') : outcome === 'closed' ? t('serviceFeedback.markClosed') : t('serviceFeedback.markReviewed')}
                                    </Button>
                                </div>
                            </section>
                        )}
                    </div>

                    {/* Side column */}
                    <aside className="space-y-5 lg:col-span-4">
                        <section aria-labelledby="context-heading" className="rounded-panel border border-(--app-border) bg-(--app-surface)">
                            <h2 id="context-heading" className="px-5 pt-4 text-xs font-semibold uppercase tracking-[0.06em] text-(--app-muted-foreground)">
                                {t(isOrganization ? 'serviceFeedback.targetInstitutionHeading' : 'serviceFeedback.serviceProvidedBy')}
                            </h2>
                            <div className="flex items-center gap-3 border-b border-(--app-border) px-5 pb-4 pt-3">
                                {isOrganization && feedback.target_logo_url ? (
                                    <img src={feedback.target_logo_url} alt="" className="h-10 w-10 shrink-0 rounded-control border border-(--app-border) bg-white object-contain p-0.5" />
                                ) : (
                                    <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary dark:bg-primary-900 dark:text-primary-200">
                                        {initials(isOrganization ? targetName : feedback.employee?.name)}
                                    </span>
                                )}
                                <span className="min-w-0">
                                    <span className="block text-[15px] font-semibold text-(--app-foreground) wrap-anywhere">{(isOrganization ? targetName : feedback.employee?.name) || '—'}</span>
                                    <span className="block text-xs text-(--app-muted-foreground)">{isOrganization ? feedback.target_organization?.code : feedback.employee?.employee_number}</span>
                                </span>
                            </div>
                            <dl className="space-y-3.5 px-5 py-4 text-sm">
                                <Detail label={t('serviceFeedback.serviceType')}>{serviceLabel}</Detail>
                                <Detail label={t('serviceFeedback.filterOrganization')}>{label(feedback.organization)}</Detail>
                                <Detail label={t(isOrganization ? 'serviceFeedback.servicePoint' : 'serviceFeedback.filterUnit')}>{label(feedback.organization_unit)}</Detail>
                                {!isOrganization && <Detail label={t('serviceFeedback.servicePosition')}>{label(feedback.position)}</Detail>}
                                <Detail label={t('serviceFeedback.category')}>{label(feedback.category ?? null)}</Detail>
                            </dl>
                        </section>

                        <section aria-labelledby="activity-heading" className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                            <h2 id="activity-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-(--app-muted-foreground)">{t('serviceFeedback.activity')}</h2>
                            <ol className="mt-3.5 space-y-3.5">
                                {history.length === 0 && (
                                    // A row written before the history existed: what the record itself says.
                                    <TimelineItem done title={t('serviceFeedback.submittedByClient')}>
                                        <LocalizedDateDisplay value={feedback.created_at} withTime />
                                    </TimelineItem>
                                )}
                                {history.map((row) => (
                                    <TimelineItem key={row.id} done title={row.from_status === null ? t('serviceFeedback.submittedByClient') : `${t(statusLabelKey(row.to_status))} · ${row.actor ?? t('serviceFeedback.historySystem')}`}>
                                        <LocalizedDateDisplay value={row.transitioned_at} withTime />
                                        {row.reason && (
                                            <span className="mt-1.5 block whitespace-pre-wrap rounded-control bg-(--app-surface-muted) px-2.5 py-2 text-(--app-foreground)">
                                                {row.reason}
                                            </span>
                                        )}
                                    </TimelineItem>
                                ))}
                                {history.length === 0 && feedback.reviewed_at && (
                                    <TimelineItem done title={`${t(statusLabelKey(feedback.status))} · ${feedback.reviewed_by ?? '—'}`}>
                                        <LocalizedDateDisplay value={feedback.reviewed_at} withTime />
                                    </TimelineItem>
                                )}
                                {feedback.status === 'pending' && (
                                    <TimelineItem title={t('serviceFeedback.awaitingReview')}>{t('serviceFeedback.awaitingReviewDetail')}</TimelineItem>
                                )}
                            </ol>
                        </section>

                        {(can.hide || can.delete) && (
                            <section aria-labelledby="moderation-heading" className="space-y-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                                <h2 id="moderation-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-(--app-muted-foreground)">{t('serviceFeedback.moderation')}</h2>
                                {can.hide && (
                                    <div className="space-y-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="w-full"
                                            disabled={processing}
                                            onClick={toggleHide}
                                            icon={isHidden ? <EyeIcon className="h-4 w-4" /> : <EyeOffIcon className="h-4 w-4" />}
                                        >
                                            {isHidden ? t('serviceFeedback.restoreComment') : t('serviceFeedback.hideComment')}
                                        </Button>
                                        <p className="text-xs leading-relaxed text-(--app-muted-foreground)">
                                            {isHidden ? t('serviceFeedback.restoreHint') : t('serviceFeedback.hideHint')}
                                        </p>
                                    </div>
                                )}
                                {can.hide && can.delete && <div className="h-px bg-(--app-border)" />}
                                {can.delete && (
                                    <div className="space-y-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="w-full border-red-300 text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                            disabled={processing}
                                            onClick={destroy}
                                            icon={<TrashIcon className="h-4 w-4" />}
                                        >
                                            {t('serviceFeedback.deletePermanently')}
                                        </Button>
                                        <p className="text-xs leading-relaxed text-(--app-muted-foreground)">{t('serviceFeedback.deleteHint')}</p>
                                    </div>
                                )}
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 font-medium text-(--app-foreground)">{children}</dd>
        </div>
    );
}

function TimelineItem({ title, done = false, children }: { title: string; done?: boolean; children: ReactNode }): JSX.Element {
    return (
        <li className="flex gap-3">
            <span
                aria-hidden="true"
                className={cx(
                    'mt-[5px] h-2.5 w-2.5 shrink-0 rounded-full',
                    done ? 'bg-primary' : 'border-2 border-dashed border-(--app-border-strong)',
                )}
            />
            <span className="min-w-0 text-[13px]">
                <span className={cx('block font-medium', done ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)')}>{title}</span>
                <span className="block text-(--app-muted-foreground)">{children}</span>
            </span>
        </li>
    );
}
