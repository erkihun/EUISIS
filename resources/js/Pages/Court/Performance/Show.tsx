import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import UserAvatar from '@/Components/UserAvatar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, FormField, Textarea, badgeClassName, buttonClassName, cx } from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import type { PerformanceShowProps } from '@/court/types/performance';
import { STATUS_TONES, criterionBarClass, criterionTextClass, formatScore, overallBarClass, overallLabelKey, overallTextClass } from './score';

/**
 * One evaluation: the member, period and state, the weighted overall score
 * with its rating, who evaluated and reviewed it, each criterion's score and
 * comment, the reviewer's notes, and (for a submitted evaluation, with
 * performance-evaluations.review) the review form that marks it reviewed.
 */
export default function PerformanceShow({ evaluation, scores, can }: PerformanceShowProps) {
    const { p } = usePerformanceText();
    const review = useForm({ reviewer_notes: '' });
    const na = p('not_available');
    const score = evaluation.overall_score;
    const title = evaluation.member_name ?? p('detail_title');

    const submitReview = (event: FormEvent) => {
        event.preventDefault();
        review.post(route('court.performance-evaluations.review', evaluation.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={p('detail_title')} backHref={route('court.performance-evaluations.index')} actions={(
                evaluation.status !== 'reviewed' && can.update
                    ? <Link href={route('court.performance-evaluations.edit', evaluation.id)} className={buttonClassName({ variant: 'outline' })}>{p('actions.edit')}</Link>
                    : undefined
            )} />
        )}>
            <Head title={`${p('detail_title')} · ${title}`} />

            <div className="mx-auto max-w-3xl space-y-4">
                <Card>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <UserAvatar src={evaluation.member_avatar_url} name={evaluation.member_name ?? '?'} size={64} />
                            <div className="min-w-0">
                                <h2 className="text-xl font-bold text-(--app-foreground)">{evaluation.member_name ?? na}</h2>
                                <p className="text-sm text-(--app-muted-foreground)">
                                    <LocalizedDateDisplay value={evaluation.period_start} fallback={na} /> – <LocalizedDateDisplay value={evaluation.period_end} fallback={na} />
                                    {' · '}
                                    <span>{p(`periods.${evaluation.period_type}`)}</span>
                                </p>
                                <span className={badgeClassName(STATUS_TONES[evaluation.status] ?? 'neutral', 'mt-1')}>{p(`statuses.${evaluation.status}`)}</span>
                            </div>
                        </div>

                        <div className="flex flex-col items-center">
                            <div className={cx('text-5xl font-black tabular-nums', overallTextClass(score))}>
                                {formatScore(score)}<span className="text-2xl text-(--app-muted-foreground)">%</span>
                            </div>
                            <div className="mt-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{p(`score_labels.${overallLabelKey(score)}`)}</div>
                        </div>
                    </div>

                    <div className="mt-5 h-3 w-full overflow-hidden rounded-full bg-(--app-surface-muted)" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.min(100, score)} aria-label={p('fields.score')}>
                        <div className={cx('h-full rounded-full transition-all duration-700', overallBarClass(score))} style={{ width: `${Math.min(100, Math.max(0, score))}%` }} />
                    </div>

                    <dl className="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                        <div><dt className="font-medium text-(--app-foreground)">{p('fields.evaluator')}</dt><dd className="text-(--app-muted-foreground)">{evaluation.evaluator_name ?? na}</dd></div>
                        <div><dt className="font-medium text-(--app-foreground)">{p('fields.created')}</dt><dd className="text-(--app-muted-foreground)"><LocalizedDateDisplay value={evaluation.created_at} fallback={na} /></dd></div>
                        {evaluation.reviewer_name && (
                            <>
                                <div><dt className="font-medium text-(--app-foreground)">{p('fields.reviewed_by')}</dt><dd className="text-(--app-muted-foreground)">{evaluation.reviewer_name}</dd></div>
                                <div><dt className="font-medium text-(--app-foreground)">{p('fields.reviewed_at')}</dt><dd className="text-(--app-muted-foreground)"><LocalizedDateDisplay value={evaluation.reviewed_at} fallback={na} /></dd></div>
                            </>
                        )}
                    </dl>

                    {evaluation.notes && (
                        <div className="mt-4 rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-3">
                            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{p('fields.notes')}</p>
                            <p className="whitespace-pre-line text-sm text-(--app-foreground)">{evaluation.notes}</p>
                        </div>
                    )}
                </Card>

                <Card className="space-y-4">
                    <h2 className="text-sm font-semibold text-(--app-foreground)">{p('criteria_breakdown')}</h2>
                    {scores.map((row) => (
                        <div key={row.id}>
                            <div className="mb-1 flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <span className="text-sm font-semibold text-(--app-foreground)">{row.criterion_name ?? na}</span>
                                    <span className="ml-2 text-[10px] uppercase text-(--app-muted-foreground)">
                                        {row.category_label || na} · {row.weight ?? 0}%
                                    </span>
                                </div>
                                <span className={cx('text-lg font-extrabold tabular-nums', criterionTextClass(row.score))}>
                                    {row.score}<span className="text-xs text-(--app-muted-foreground)">/10</span>
                                </span>
                            </div>
                            <div className="h-2.5 w-full overflow-hidden rounded-full bg-(--app-surface-muted)">
                                <div className={cx('h-full rounded-full', criterionBarClass(row.score))} style={{ width: `${Math.min(100, Math.max(0, row.score * 10))}%` }} />
                            </div>
                            {row.comment && <p className="mt-1 text-xs italic text-(--app-muted-foreground)">{row.comment}</p>}
                        </div>
                    ))}
                </Card>

                {evaluation.reviewer_notes && (
                    <div className="rounded-card border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{p('fields.reviewer_notes')}</p>
                        <p className="whitespace-pre-line text-sm text-emerald-900 dark:text-emerald-100">{evaluation.reviewer_notes}</p>
                    </div>
                )}

                {evaluation.status === 'submitted' && can.review && (
                    <Card>
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{p('review.title')}</h2>
                        <form onSubmit={submitReview} className="mt-3 space-y-3">
                            <FormField label={p('fields.reviewer_notes')} error={review.errors.reviewer_notes}>
                                {({ id, describedBy, invalid }) => (
                                    <Textarea id={id} rows={3} value={review.data.reviewer_notes} onChange={(event) => review.setData('reviewer_notes', event.target.value)} maxLength={2000}
                                        placeholder={p('placeholders.reviewer_notes')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                                )}
                            </FormField>
                            <div className="flex justify-end">
                                <Button type="submit" variant="primary" loading={review.processing}>{p('actions.approve_reviewed')}</Button>
                            </div>
                        </form>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
