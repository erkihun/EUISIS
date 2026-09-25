import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { named } from './helpers';
import type { ActivityItem } from './types';

/**
 * Read-only activity list. Dense definition rows rather than cards so a day
 * with eight activities still fits on one screen.
 */
export default function ItemsReadOnly({ items, showReviewerNotes = true }: { items: ActivityItem[]; showReviewerNotes?: boolean }) {
    const { t, locale } = useLocale();

    if (!items.length) {
        return <p className="px-1 py-3 text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.entry.noItems')}</p>;
    }

    return (
        <ol className="divide-y divide-gray-100 dark:divide-slate-800">
            {items.map((item, index) => {
                const task = item.position_service
                    ? named(item.position_service, locale)
                    : t(`dailyActivities.categories.${item.activity_category ?? 'other'}`);
                const time = [item.started_at, item.ended_at].filter(Boolean).join('–');
                const extra = [
                    time,
                    item.duration_minutes ? `${item.duration_minutes} min` : '',
                    item.quantity !== null && item.quantity !== '' ? `${item.quantity} ${item.unit_of_measure ?? ''}`.trim() : '',
                ].filter(Boolean).join(' · ');

                return (
                    <li key={item.id ?? index} className="py-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                    {index + 1}. {task}
                                </p>
                                <p className="break-words text-sm font-semibold text-gray-900 dark:text-slate-100">{item.title}</p>
                            </div>
                            <StatusBadge status={item.progress_status} label={t(`dailyActivities.progress.${item.progress_status}`)} />
                        </div>
                        <dl className="mt-1.5 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr]">
                            {item.description && (
                                <>
                                    <dt className="text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.description')}</dt>
                                    <dd className="whitespace-pre-line break-words text-gray-800 dark:text-slate-200">{item.description}</dd>
                                </>
                            )}
                            {item.output_result && (
                                <>
                                    <dt className="text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.outputResult')}</dt>
                                    <dd className="whitespace-pre-line break-words text-gray-800 dark:text-slate-200">{item.output_result}</dd>
                                </>
                            )}
                            {extra && (
                                <>
                                    <dt className="text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.startedAt')}</dt>
                                    <dd className="text-gray-800 dark:text-slate-200">{extra}</dd>
                                </>
                            )}
                            {item.challenge_issue && (
                                <>
                                    <dt className="text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.challengeIssue')}</dt>
                                    <dd className="whitespace-pre-line break-words text-gray-800 dark:text-slate-200">{item.challenge_issue}</dd>
                                </>
                            )}
                            {item.next_action && (
                                <>
                                    <dt className="text-gray-500 dark:text-slate-400">{t('dailyActivities.fields.nextAction')}</dt>
                                    <dd className="whitespace-pre-line break-words text-gray-800 dark:text-slate-200">{item.next_action}</dd>
                                </>
                            )}
                        </dl>
                        {showReviewerNotes && item.reviewer_note && (
                            <p className="mt-2 border-s-2 border-amber-400 ps-2 text-sm text-amber-900 dark:text-amber-200">
                                <span className="font-medium">{t('dailyActivities.entry.reviewerNote')}:</span> {item.reviewer_note}
                            </p>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
