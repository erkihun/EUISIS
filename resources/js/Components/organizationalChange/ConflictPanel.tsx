import { useLocale } from '@/hooks/useLocale';
import { AlertTriangle } from '@/Components/Icons';
import type { JSX } from 'react';
import type { Conflict } from './types';

type Props = { conflicts: Conflict[]; showEmptyState?: boolean };

/**
 * Conflicts detected between approval and implementation.
 *
 * Each one blocks the whole request: the implementation service never applies
 * part of a change, so the list below is a list of things to resolve, not a
 * list of warnings to click past.
 */
export default function ConflictPanel({ conflicts, showEmptyState = false }: Props): JSX.Element | null {
    const { t } = useLocale();

    if (conflicts.length === 0) {
        if (!showEmptyState) {
            return null;
        }

        return (
            <section className="rounded-panel border border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                <p className="text-sm text-emerald-800 dark:text-emerald-300">
                    {t('organizationalChangeRequests.implementation.noConflicts')}
                </p>
            </section>
        );
    }

    return (
        <section className="rounded-panel border border-red-200 bg-red-50/50 dark:border-red-900/40 dark:bg-red-950/20">
            <header className="flex items-center gap-2 border-b border-red-200 px-4 py-3 dark:border-red-900/40">
                <AlertTriangle className="h-4 w-4 text-red-700 dark:text-red-400" aria-hidden="true" />
                <h2 className="text-sm font-semibold text-red-800 dark:text-red-300">
                    {t('organizationalChangeRequests.implementation.conflictsHeading')}
                </h2>
            </header>
            <div className="px-4 py-3">
                <p className="text-sm text-red-800 dark:text-red-300">
                    {t('organizationalChangeRequests.implementation.conflictsIntro')}
                </p>
                <ul className="mt-2 space-y-1.5">
                    {conflicts.map((conflict, index) => (
                        <li key={`${conflict.code}-${index}`} className="text-sm text-red-900 dark:text-red-200">
                            &bull;{' '}
                            {t(`organizationalChangeRequests.conflicts.${conflict.code}`) === `organizationalChangeRequests.conflicts.${conflict.code}`
                                ? conflict.code
                                : t(`organizationalChangeRequests.conflicts.${conflict.code}`)}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
