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
            <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <header className="border-b border-[color:var(--app-border)] px-4 py-3">
                    <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                        {t('organizationalChangeRequests.timeline.reviewHistory')}
                    </h2>
                </header>
                {reviews.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-[color:var(--app-muted-foreground)]">—</p>
                ) : (
                    <ul className="divide-y divide-[color:var(--app-border)]">
                        {reviews.map(review => (
                            <li key={review.id} className="px-4 py-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="text-sm font-medium text-[color:var(--app-foreground)]">
                                        {t(`organizationalChangeRequests.actions.${toCamel(review.action)}`) === `organizationalChangeRequests.actions.${toCamel(review.action)}`
                                            ? review.action
                                            : t(`organizationalChangeRequests.actions.${toCamel(review.action)}`)}
                                    </span>
                                    <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                        {review.reviewer?.name ?? '—'}
                                        {' · '}
                                        {review.reviewed_at ? <LocalizedDateDisplay value={review.reviewed_at} withTime /> : ''}
                                        {` · r${review.revision}`}
                                    </span>
                                </div>
                                {review.comment && (
                                    <p className="mt-1 whitespace-pre-line text-sm text-[color:var(--app-muted-foreground)]">{review.comment}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <header className="border-b border-[color:var(--app-border)] px-4 py-3">
                    <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                        {t('organizationalChangeRequests.timeline.implementationHistory')}
                    </h2>
                </header>
                <ul className="divide-y divide-[color:var(--app-border)]">
                    {history.map(entry => (
                        <li key={entry.id} className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2.5">
                            <span className="text-sm text-[color:var(--app-foreground)]">
                                {entry.to_status
                                    ? t(`organizationalChangeRequests.statuses.${entry.to_status}`)
                                    : entry.action}
                            </span>
                            <span className="text-xs text-[color:var(--app-muted-foreground)]">
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
