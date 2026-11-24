import { cx } from '@/Components/ui';
import { badge, mutedCls, timelinessTone, useReporting, workflowTone } from '@/modules/reporting/lib/helpers';
import type { ObligationRow } from '@/modules/reporting/types';
import type { JSX } from 'react';

/** Where the report stands in the workflow. Text carries the meaning; colour only supports it. */
export function WorkflowBadge({ status }: { status: string }): JSX.Element {
    const { rt } = useReporting();

    return <span className={badge(workflowTone(status))}>{rt(`workflow.${status}`)}</span>;
}

/** Timeliness of the first submission. Exempted and cancelled reports have none. */
export function TimelinessBadge({ value, applicability = 'required' }: { value: string | null; applicability?: string }): JSX.Element {
    const { rt } = useReporting();
    if (applicability !== 'required' || value === null) {
        return <span className={badge('neutral')}>{rt(`applicability.${applicability}`)}</span>;
    }

    return <span className={badge(timelinessTone(value))}>{rt(`timeliness.${value}`)}</span>;
}

/** A moment in two compact lines (see useReporting().whenParts); a dash when there is none. */
export function When({ iso, withTime = true, strong = false }: { iso: string | null | undefined; withTime?: boolean; strong?: boolean }): JSX.Element {
    const { whenParts } = useReporting();
    const parts = whenParts(iso, withTime);
    if (!parts) return <span className={mutedCls}>—</span>;

    return (
        <span className="inline-block whitespace-nowrap align-top">
            <span className={cx('block text-sm', strong && 'font-medium')}>{parts.primary}</span>
            <span className="block text-xs text-(--app-muted-foreground)">{parts.secondary}</span>
        </span>
    );
}

/**
 * A deadline, always with its zone. When the deadline was extended the
 * original one stays visible beside it.
 */
export function Deadline({ row, compact = false }: { row: Pick<ObligationRow, 'effective_due_at' | 'original_due_at' | 'deadline_extended' | 'first_submitted_at' | 'applicability'>; compact?: boolean }): JSX.Element {
    const { rt, moment, dueHint } = useReporting();
    const open = row.first_submitted_at === null && row.applicability === 'required';

    return (
        <div className="min-w-0">
            <When iso={row.effective_due_at} strong />
            {row.deadline_extended && <div className={cx(mutedCls, 'text-xs')}>{rt('common.originalDeadline')}: {moment(row.original_due_at)}</div>}
            {open && !compact && <div className={cx('text-xs', new Date(row.effective_due_at).getTime() < Date.now() ? 'font-medium text-red-700 dark:text-red-400' : 'text-(--app-muted-foreground)')}>{dueHint(row.effective_due_at)}</div>}
        </div>
    );
}
