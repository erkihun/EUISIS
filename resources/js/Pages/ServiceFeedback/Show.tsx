import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import {
    FeedbackStatusBadge,
    ratingTone,
    statusLabelKey,
    toneClasses,
    useNameLabel,
    type FeedbackSummary,
} from '@/Components/ServiceFeedback/feedbackUi';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ArrowLeftIcon, ChevronRight, EyeIcon, EyeOffIcon, TrashIcon } from '@/Components/Icons';
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
};

type Props = {
    feedback: Feedback;
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
};

type Outcome = 'reviewed' | 'resolved';

const NOTE_LIMIT = 2000;

function initials(name: string | null | undefined): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export default function ServiceFeedbackShow({ feedback, can }: Props): JSX.Element {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = useNameLabel();

    const isHidden = feedback.status === 'hidden';
    const tone = toneClasses[ratingTone(feedback.rating)];

    // A resolved entry opens on Resolved so re-saving a note keeps its outcome.
    const [outcome, setOutcome] = useState<Outcome>(feedback.status === 'resolved' ? 'resolved' : 'reviewed');
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

    const title = feedback.employee?.name
        ? t('serviceFeedback.feedbackFor').replace(':name', feedback.employee.name)
        : t('serviceFeedback.feedbackDetail');

    const outcomes: { id: Outcome; label: string; hint: string }[] = [
        { id: 'reviewed', label: t('serviceFeedback.statusReviewed'), hint: t('serviceFeedback.reviewedHint') },
        { id: 'resolved', label: t('serviceFeedback.statusResolved'), hint: t('serviceFeedback.resolvedHint') },
    ];

    const serviceLabel = [feedback.service_no, label(feedback.service_type, '')].filter(Boolean).join(' · ') || '—';

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.feedbackDetail')} />

            <div className="space-y-6">
                {/* Header */}
                <header className="space-y-3">
                    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[13px] text-[color:var(--app-muted-foreground)]">
                        <Link href={route('service-feedback.admin.dashboard')} className="hover:text-[color:var(--app-foreground)]">{t('serviceFeedback.clientFeedback')}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <Link href={route('service-feedback.admin.index')} className="hover:text-[color:var(--app-foreground)]">{t('serviceFeedback.inbox')}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <span aria-current="page" className="font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.feedbackDetail')}</span>
                    </nav>
                    <div className="flex items-start gap-3">
                        <Link
                            href={route('service-feedback.admin.index')}
                            aria-label={t('common.back')}
                            className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-control)] border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                        >
                            <ArrowLeftIcon aria-hidden="true" className="h-4 w-4" />
                        </Link>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{title}</h1>
                                <FeedbackStatusBadge status={feedback.status} />
                            </div>
                            <p className="mt-1 text-[13px] text-[color:var(--app-muted-foreground)]">
                                {label(feedback.service_type)} · {t('serviceFeedback.submittedDate')}{' '}
                                <LocalizedDateDisplay value={feedback.created_at} withTime /> {t('serviceFeedback.viaQr')}
                            </p>
                        </div>
                    </div>
                </header>

                {isHidden && (
                    <div role="status" className="flex items-center gap-2.5 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-4 py-3 text-sm text-[color:var(--app-foreground)]">
                        <EyeOffIcon aria-hidden="true" className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" />
                        {t('serviceFeedback.hiddenNotice')}
                    </div>
                )}

                <div className="grid items-start gap-5 lg:grid-cols-12">
                    {/* Main column */}
                    <div className="space-y-5 lg:col-span-8">
                        {/* What the client said */}
                        <section aria-labelledby="client-said" className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                            <div className="flex items-center gap-5 p-5 sm:p-6">
                                <div className={cx('flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-xl sm:h-[88px] sm:w-[88px]', tone.soft)}>
                                    <span className="text-4xl font-bold leading-none tabular-nums">{feedback.rating}</span>
                                    <span className="mt-1 text-xs font-semibold">{t('serviceFeedback.outOfFive')}</span>
                                </div>
                                <div className="min-w-0 space-y-1.5">
                                    <h2 id="client-said" className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">
                                        {t('serviceFeedback.clientRating')}
                                    </h2>
                                    <RatingStars rating={feedback.rating} size="md" />
                                    <p className={cx('text-base font-semibold', tone.text)}>{t(`serviceFeedback.rating${feedback.rating}`)}</p>
                                </div>
                            </div>

                            <div className="px-5 pb-5 sm:px-6 sm:pb-6">
                                <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.comment')}</h3>
                                {feedback.comment ? (
                                    <blockquote className="whitespace-pre-wrap rounded-[var(--radius-card)] bg-[color:var(--app-surface-muted)] px-4 py-3.5 text-[15px] leading-relaxed text-[color:var(--app-foreground)]">
                                        {feedback.comment}
                                    </blockquote>
                                ) : (
                                    <p className="text-sm italic text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noCommentRatingOnly')}</p>
                                )}
                            </div>

                            {/* Volunteered client details; absent for anonymous submissions. */}
                            <dl className="flex flex-wrap gap-x-8 gap-y-3 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-3.5 text-[13px] sm:px-6">
                                <div>
                                    <dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.client')}</dt>
                                    <dd className="font-medium text-[color:var(--app-foreground)]">{feedback.client_name ?? t('serviceFeedback.anonymousClient')}</dd>
                                </div>
                                <div className="min-w-0">
                                    <dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.contact')}</dt>
                                    <dd className={cx('break-all', feedback.client_contact ? 'font-medium text-[color:var(--app-foreground)]' : 'text-[color:var(--app-muted-foreground)]')}>
                                        {feedback.client_contact ?? t('serviceFeedback.notProvided')}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        {/* Review */}
                        {can.review && (
                            <section aria-labelledby="review-heading" className="space-y-4 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 sm:p-6">
                                <div>
                                    <h2 id="review-heading" className="text-base font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.review')}</h2>
                                    <p className="mt-1 text-[13px] text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.reviewHint')}</p>
                                </div>

                                <fieldset>
                                    <legend className="mb-2 text-[13px] font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.outcome')}</legend>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {outcomes.map((option) => {
                                            const selected = outcome === option.id;
                                            return (
                                                <label
                                                    key={option.id}
                                                    className={cx(
                                                        'flex cursor-pointer gap-3 rounded-[var(--radius-card)] border px-4 py-3.5 transition-colors',
                                                        selected
                                                            ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary-50)] ring-1 ring-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-950)]'
                                                            : 'border-[color:var(--app-border)] hover:border-[color:var(--app-border-strong)]',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="outcome"
                                                        value={option.id}
                                                        checked={selected}
                                                        onChange={() => setOutcome(option.id)}
                                                        className="mt-0.5 h-4 w-4 border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]"
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-semibold text-[color:var(--app-foreground)]">{option.label}</span>
                                                        <span className="block text-xs text-[color:var(--app-muted-foreground)]">{option.hint}</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </fieldset>

                                <div>
                                    <div className="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]">
                                        <label htmlFor="review_note" className="font-semibold text-[color:var(--app-foreground)]">
                                            {t('serviceFeedback.reviewNote')}{' '}
                                            <span className="font-normal text-[color:var(--app-muted-foreground)]">({t('serviceFeedback.optional')})</span>
                                        </label>
                                        <span className="tabular-nums text-[color:var(--app-muted-foreground)]" aria-live="polite">{note.length} / {NOTE_LIMIT}</span>
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
                                        {outcome === 'resolved' ? t('serviceFeedback.markResolved') : t('serviceFeedback.markReviewed')}
                                    </Button>
                                </div>
                            </section>
                        )}
                    </div>

                    {/* Side column */}
                    <aside className="space-y-5 lg:col-span-4">
                        <section aria-labelledby="context-heading" className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                            <h2 id="context-heading" className="px-5 pt-4 text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">
                                {t('serviceFeedback.serviceProvidedBy')}
                            </h2>
                            <div className="flex items-center gap-3 border-b border-[color:var(--app-border)] px-5 pb-4 pt-3">
                                <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[color:var(--color-primary-100)] text-sm font-bold text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-900)] dark:text-[color:var(--color-primary-200)]">
                                    {initials(feedback.employee?.name)}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-[15px] font-semibold text-[color:var(--app-foreground)]">{feedback.employee?.name ?? '—'}</span>
                                    <span className="block text-xs text-[color:var(--app-muted-foreground)]">{feedback.employee?.employee_number}</span>
                                </span>
                            </div>
                            <dl className="space-y-3.5 px-5 py-4 text-sm">
                                <Detail label={t('serviceFeedback.serviceType')}>{serviceLabel}</Detail>
                                <Detail label={t('serviceFeedback.filterOrganization')}>{label(feedback.organization)}</Detail>
                                <Detail label={t('serviceFeedback.filterUnit')}>{label(feedback.organization_unit)}</Detail>
                                <Detail label={t('serviceFeedback.servicePosition')}>{label(feedback.position)}</Detail>
                            </dl>
                        </section>

                        <section aria-labelledby="activity-heading" className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                            <h2 id="activity-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.activity')}</h2>
                            <ol className="mt-3.5 space-y-3.5">
                                <TimelineItem done title={t('serviceFeedback.submittedByClient')}>
                                    <LocalizedDateDisplay value={feedback.created_at} withTime />
                                </TimelineItem>
                                {feedback.reviewed_at ? (
                                    <TimelineItem done title={`${t(statusLabelKey(feedback.status))} · ${feedback.reviewed_by ?? '—'}`}>
                                        <LocalizedDateDisplay value={feedback.reviewed_at} withTime />
                                        {feedback.review_note && (
                                            <span className="mt-1.5 block whitespace-pre-wrap rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] px-2.5 py-2 text-[color:var(--app-foreground)]">
                                                {feedback.review_note}
                                            </span>
                                        )}
                                    </TimelineItem>
                                ) : (
                                    <TimelineItem title={t('serviceFeedback.awaitingReview')}>{t('serviceFeedback.awaitingReviewDetail')}</TimelineItem>
                                )}
                            </ol>
                        </section>

                        {(can.hide || can.delete) && (
                            <section aria-labelledby="moderation-heading" className="space-y-3 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                                <h2 id="moderation-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.moderation')}</h2>
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
                                        <p className="text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">
                                            {isHidden ? t('serviceFeedback.restoreHint') : t('serviceFeedback.hideHint')}
                                        </p>
                                    </div>
                                )}
                                {can.hide && can.delete && <div className="h-px bg-[color:var(--app-border)]" />}
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
                                        <p className="text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.deleteHint')}</p>
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
            <dt className="text-xs text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 font-medium text-[color:var(--app-foreground)]">{children}</dd>
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
                    done ? 'bg-[color:var(--color-primary)]' : 'border-2 border-dashed border-[color:var(--app-border-strong)]',
                )}
            />
            <span className="min-w-0 text-[13px]">
                <span className={cx('block font-medium', done ? 'text-[color:var(--app-foreground)]' : 'text-[color:var(--app-muted-foreground)]')}>{title}</span>
                <span className="block text-[color:var(--app-muted-foreground)]">{children}</span>
            </span>
        </li>
    );
}
