import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { JSX } from 'react';
import type { ChangeRequestReview, ChangeRequestHistoryEntry } from './types';

type Props = {
    reviews: ChangeRequestReview[];
    history: ChangeRequestHistoryEntry[];
};

/**
 * The full trail: reviewer decisions and every status change.
 *
 * History is append-only on the server, so a correction round never
 * overwrites the previous one. Revisions are shown so a reader can tell which
 * round a comment belongs to.
 */
export default function ReviewHistoryPanel({ reviews, history }: Props): JSX.Element | null {
    const { t } = useLocale();

    if (reviews.length === 0 && history.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <header className="border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('organizationalChangeRequests.timeline.reviewHistory')}
                    </h2>
                </header>
                {reviews.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">—</p>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                        {reviews.map(review => (
                            <li key={review.id} className="px-4 py-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                        {t(`organizationalChangeRequests.actions.${toCamel(review.action)}`) === `organizationalChangeRequests.actions.${toCamel(review.action)}`
                                            ? review.action
                                            : t(`organizationalChangeRequests.actions.${toCamel(review.action)}`)}
                                    </span>
                                    <span className="text-xs text-gray-500 dark:text-slate-400">
                                        {review.reviewer?.name ?? '—'}
                                        {' · '}
                                        {review.reviewed_at ? <LocalizedDateDisplay value={review.reviewed_at} withTime /> : ''}
                                        {` · r${review.revision}`}
                                    </span>
                                </div>
                                {review.comment && (
                                    <p className="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-slate-400">{review.comment}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <header className="border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('organizationalChangeRequests.timeline.implementationHistory')}
                    </h2>
                </header>
                <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                    {history.map(entry => (
                        <li key={entry.id} className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2.5">
                            <span className="text-sm text-gray-800 dark:text-slate-200">
                                {entry.to_status
                                    ? t(`organizationalChangeRequests.statuses.${entry.to_status}`)
                                    : entry.action}
                            </span>
                            <span className="text-xs text-gray-500 dark:text-slate-400">
                                {entry.actor?.name ?? '—'}
                                {' · '}
                                {entry.created_at ? <LocalizedDateDisplay value={entry.created_at} withTime /> : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}

function toCamel(value: string): string {
    return value.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase());
}
