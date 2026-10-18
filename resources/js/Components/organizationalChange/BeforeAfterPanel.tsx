import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import type { ChangeRequestItem } from './types';

import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
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
        <section className="rounded-panel border border-(--app-border) bg-(--app-surface)">
            <header className="border-b border-(--app-border) px-4 py-3">
                <h2 className="text-sm font-semibold text-(--app-foreground)">
                    {t('organizationalChangeRequests.compare.heading')}
                </h2>
            </header>

            {isNew && (
                <p className="border-b border-(--app-border) px-4 py-2.5 text-xs text-(--app-muted-foreground)">
                    {t('organizationalChangeRequests.compare.newRecord')}
                </p>
            )}

            <div className="overflow-x-auto">
                <table className={tableClassName}>
                    <thead className={cx(tableHeadClassName, 'text-left uppercase')}>
                        <tr>
                            <th className={cx(tableHeaderCellClassName, 'text-left w-1/3')}>{t('organizationalChangeRequests.fields.requestType')}</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('organizationalChangeRequests.compare.current')}</th>
                            <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('organizationalChangeRequests.compare.proposed')}</th>
                        </tr>
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {fields.map(field => {
                            const currentValue = before[field];
                            const proposedValue = proposed[field];
                            const unchanged = !isNew && String(currentValue ?? '') === String(proposedValue ?? '');

                            return (
                                <tr key={field}>
                                    <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                        {t(`organizationalChangeRequests.form.${field}`) === `organizationalChangeRequests.form.${field}`
                                            ? field
                                            : t(`organizationalChangeRequests.form.${field}`)}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                        {isNew ? '—' : render(currentValue)}
                                    </td>
                                    <td className={tableCellClassName}>
                                        {unchanged ? (
                                            <span className="text-(--app-muted-foreground)">
                                                {t('organizationalChangeRequests.compare.unchanged')}
                                            </span>
                                        ) : (
                                            <span className="font-medium text-(--app-foreground)">
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
