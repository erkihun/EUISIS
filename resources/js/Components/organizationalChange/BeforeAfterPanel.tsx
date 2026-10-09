import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import type { ChangeRequestItem } from './types';

type Props = {
    item: ChangeRequestItem | undefined;
    /** Lookup maps so ids render as names rather than UUIDs. */
    unitNames?: Record<string, string>;
};

/**
 * Current value vs proposed value, field by field.
 *
 * Only fields the request actually proposes are listed, so a reviewer sees
 * the change and not the whole record. Fields whose proposed value equals the
 * current one are marked unchanged rather than hidden, because "they asked for
 * this and it is already true" is itself useful to a reviewer.
 */
export default function BeforeAfterPanel({ item, unitNames = {} }: Props): JSX.Element | null {
    const { t } = useLocale();

    if (!item) {
        return null;
    }

    const before = item.before_data ?? {};
    const proposed = item.proposed_data ?? {};

    // Internal bookkeeping that is not a user-facing field.
    const hidden = new Set(['preserve_code', 'abolish', 'organization_id']);
    const fields = Object.keys(proposed).filter(key => !hidden.has(key));

    const isNew = item.before_data === null;

    function render(value: unknown): string {
        if (value === null || value === undefined || value === '') {
            return t('organizationalChangeRequests.compare.notSet');
        }
        if (typeof value === 'boolean') {
            return value ? t('organizationalChangeRequests.form.active') : t('organizationalChangeRequests.form.inactive');
        }
        const asString = String(value);
        return unitNames[asString] ?? asString;
    }

    return (
        <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <header className="border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationalChangeRequests.compare.heading')}
                </h2>
            </header>

            {isNew && (
                <p className="border-b border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-slate-800 dark:text-slate-400">
                    {t('organizationalChangeRequests.compare.newRecord')}
                </p>
            )}

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                        <tr>
                            <th className="px-4 py-2.5 w-1/3">{t('organizationalChangeRequests.fields.requestType')}</th>
                            <th className="px-4 py-2.5">{t('organizationalChangeRequests.compare.current')}</th>
                            <th className="px-4 py-2.5">{t('organizationalChangeRequests.compare.proposed')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {fields.map(field => {
                            const currentValue = before[field];
                            const proposedValue = proposed[field];
                            const unchanged = !isNew && String(currentValue ?? '') === String(proposedValue ?? '');

                            return (
                                <tr key={field}>
                                    <td className="px-4 py-2.5 font-medium text-gray-700 dark:text-slate-300">
                                        {t(`organizationalChangeRequests.form.${field}`) === `organizationalChangeRequests.form.${field}`
                                            ? field
                                            : t(`organizationalChangeRequests.form.${field}`)}
                                    </td>
                                    <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400">
                                        {isNew ? '—' : render(currentValue)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        {unchanged ? (
                                            <span className="text-gray-500 dark:text-slate-400">
                                                {t('organizationalChangeRequests.compare.unchanged')}
                                            </span>
                                        ) : (
                                            <span className="font-medium text-gray-900 dark:text-slate-100">
                                                {render(proposedValue)}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
