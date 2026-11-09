import { Link } from '@inertiajs/react';
import type { JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';

/**
 * Clients' star ratings of this employee, from the public feedback QR.
 *
 * The server sends the summary only to a viewer whose feedback grants and
 * organization scope cover these ratings (and never to the employee about
 * themselves), so this component renders whatever it is given and is never
 * the gate. Hidden (moderated) entries are already excluded server-side.
 */
export type EmployeeFeedbackRating = {
    total: number;
    average: number | null;
    /** Five stars first; every star is present, zero counts included. */
    distribution: Array<{ stars: number; count: number }>;
};

const STAR_PATH = 'M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z';

const STAR_BAR: Record<number, string> = {
    5: 'bg-emerald-600 dark:bg-emerald-500',
    4: 'bg-emerald-400 dark:bg-emerald-600',
    3: 'bg-amber-500',
    2: 'bg-orange-500',
    1: 'bg-red-600 dark:bg-red-500',
};

/** Five stars filled to the average, a partial star included (4.3 fills 30% of the fifth). */
function AverageStars({ value }: { value: number }): JSX.Element {
    return (
        <span className="inline-flex gap-0.5" aria-hidden="true">
            {[1, 2, 3, 4, 5].map((star) => {
                const fill = Math.max(0, Math.min(1, value - (star - 1)));

                return (
                    <span key={star} className="relative inline-block h-6 w-6">
                        <svg viewBox="0 0 24 24" fill="currentColor" className="absolute inset-0 h-6 w-6 text-(--app-border-strong)">
                            <path d={STAR_PATH} />
                        </svg>
                        <span className="absolute inset-y-0 left-0 overflow-hidden" style={{ width: `${fill * 100}%` }}>
                            <svg viewBox="0 0 24 24" fill="currentColor" className="h-6 w-6 text-amber-400">
                                <path d={STAR_PATH} />
                            </svg>
                        </span>
                    </span>
                );
            })}
        </span>
    );
}

function percent(part: number, whole: number): number {
    return whole === 0 ? 0 : Math.round((part / whole) * 100);
}

export default function EmployeeFeedbackRatingCard({
    employeeId,
    rating,
}: {
    employeeId: string;
    rating?: EmployeeFeedbackRating | null;
}): JSX.Element | null {
    const { t } = useLocale();

    if (!rating) {
        return null;
    }

    const maxCount = Math.max(...rating.distribution.map((row) => row.count), 1);

    return (
        <section
            aria-labelledby="employee-feedback-rating-heading"
            className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5"
        >
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 id="employee-feedback-rating-heading" className="text-sm font-semibold text-(--app-foreground)">
                    {t('employees.feedbackRatingTitle')}
                </h3>
                {rating.total > 0 && (
                    <Link
                        href={route('service-feedback.admin.index', { employee_id: employeeId })}
                        className="text-sm text-primary hover:underline"
                    >
                        {t('employees.feedbackRatingOpen')}
                    </Link>
                )}
            </div>

            {rating.total === 0 || rating.average === null ? (
                <p className="text-sm text-(--app-muted-foreground)">{t('employees.feedbackRatingNone')}</p>
            ) : (
                <div className="grid items-center gap-6 sm:grid-cols-[auto_1fr]">
                    <div className="flex flex-col items-start gap-1.5">
                        <span className="text-4xl font-semibold tabular-nums text-(--app-foreground)">
                            {rating.average.toFixed(1)}
                            <span className="ml-1 text-base font-normal text-(--app-muted-foreground)">/ 5</span>
                        </span>
                        <AverageStars value={rating.average} />
                        <span className="sr-only">
                            {t('serviceFeedback.ratingOutOfFive').replace(':rating', rating.average.toFixed(1))}
                        </span>
                        <span className="text-xs text-(--app-muted-foreground)">
                            {t('employees.feedbackRatingCount').replace(':count', rating.total.toLocaleString())}
                        </span>
                    </div>

                    <ul className="space-y-2" aria-label={t('employees.feedbackRatingBreakdown')}>
                        {rating.distribution.map((row) => (
                            <li key={row.stars} className="flex items-center gap-3 text-[13px]">
                                <span className="inline-flex w-9 shrink-0 items-center gap-1 font-semibold text-(--app-foreground)">
                                    {row.stars}
                                    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-3.5 w-3.5 fill-amber-400">
                                        <path d={STAR_PATH} />
                                    </svg>
                                    <span className="sr-only">{t(`serviceFeedback.rating${row.stars}`)}</span>
                                </span>
                                <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-(--app-surface-muted)">
                                    <span
                                        className={`block h-full rounded-full ${STAR_BAR[row.stars] ?? 'bg-amber-500'}`}
                                        style={{ width: `${(row.count / maxCount) * 100}%` }}
                                    />
                                </span>
                                <span className="w-10 shrink-0 text-right font-semibold tabular-nums text-(--app-foreground)">
                                    {row.count.toLocaleString()}
                                </span>
                                <span className="w-10 shrink-0 text-right text-xs tabular-nums text-(--app-muted-foreground)">
                                    {percent(row.count, rating.total)}%
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
