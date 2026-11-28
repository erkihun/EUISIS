import { cx } from '@/Components/ui';
import { Notice } from '@/modules/leave/components/Common';
import { formatDays, mutedCls, panelCls, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveSummary, TypeSummary } from '@/modules/leave/types';
import type { JSX } from 'react';

/** Why a leave type shows no yearly grant yet, in the reader's words. */
function GrantNote({ type }: { type: TypeSummary }): JSX.Element | null {
    const { lt } = useLeave();
    if (!type.has_rule) return <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">{lt('balance.noRule')}</p>;
    if (!type.no_grant_reason) return null;

    return <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">{lt(`balance.noGrant.${type.no_grant_reason}`)}</p>;
}

function BalanceCard({ type }: { type: TypeSummary }): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const waiting = type.waiting ?? [];

    return (
        <div className={cx(panelCls, 'p-4')}>
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{name(type)}</h3>
                <span className={cx(mutedCls, 'text-xs')}>{lt(type.day_unit === 'working' ? 'common.unitWorking' : 'common.unitCalendar')}</span>
            </div>
            <p className="mt-3 text-3xl font-semibold text-primary">{formatDays(type.free ?? 0)}</p>
            <p className={cx(mutedCls, 'text-xs')}>{lt('balance.free')}</p>
            <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                <dt className="text-(--app-muted-foreground)">{lt('balance.granted')}</dt><dd className="text-end font-medium">{formatDays(type.granted ?? 0)}</dd>
                {(type.carried ?? 0) > 0 && (<><dt className="text-(--app-muted-foreground)">{lt('balance.carried')}</dt><dd className="text-end font-medium">{formatDays(type.carried ?? 0)}</dd></>)}
                <dt className="text-(--app-muted-foreground)">{lt('balance.taken')}</dt><dd className="text-end font-medium">{formatDays(type.taken)}</dd>
                <dt className="text-(--app-muted-foreground)">{lt('balance.pending')}</dt><dd className="text-end font-medium">{formatDays(type.pending)}</dd>
            </dl>
            {type.carry_over_mode === 'none' && <p className={cx(mutedCls, 'mt-2 text-xs')}>{lt('balance.notCarried')}</p>}
            {waiting.map((bucket) => (
                <p key={bucket.year} className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                    {lt('balance.waiting', { days: dayCount(bucket.days, type.day_unit), year: bucket.year, last: bucket.last_year })}
                </p>
            ))}
            <GrantNote type={type} />
        </div>
    );
}

function LimitCard({ type }: { type: TypeSummary }): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const limits = [
        type.max_days_per_request !== null ? lt('balance.limitPerRequest', { days: dayCount(type.max_days_per_request, type.day_unit) }) : null,
        type.max_days_per_year !== null ? lt('balance.limitPerYear', { days: dayCount(type.max_days_per_year, type.day_unit) }) : null,
    ].filter((line): line is string => line !== null);

    return (
        <div className={cx(panelCls, 'p-4')}>
            <h3 className="text-sm font-semibold text-(--app-foreground)">{name(type)}</h3>
            <p className="mt-2 text-sm text-(--app-foreground)">{lt('balance.takenThisYear', { days: dayCount(type.taken, type.day_unit) })}</p>
            {type.pending > 0 && <p className={cx(mutedCls, 'text-xs')}>{lt('balance.pendingThisYear', { days: dayCount(type.pending, type.day_unit) })}</p>}
            {limits.map((line) => <p key={line} className={cx(mutedCls, 'mt-1 text-xs')}>{line}</p>)}
            <p className={cx(mutedCls, 'mt-1 text-xs')}>{lt(`pay.${type.pay_class}`)}</p>
            {!type.has_rule && <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">{lt('balance.noRule')}</p>}
        </div>
    );
}

/** An employee's leave for the budget year: what is free to ask for, and what was taken. */
export function BalanceCards({ summary }: { summary: LeaveSummary }): JSX.Element {
    const { lt } = useLeave();
    const withBalance = summary.types.filter((type) => type.uses_balance);
    const others = summary.types.filter((type) => !type.uses_balance);

    return (
        <div className="space-y-4">
            {!summary.has_rules && <Notice tone="warning">{lt('balance.noRulesAtAll')}</Notice>}
            <p className={mutedCls}>{lt('balance.budgetYear', { year: summary.budget_year })}</p>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {withBalance.map((type) => <BalanceCard key={type.id} type={type} />)}
            </div>
            {others.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {others.map((type) => <LimitCard key={type.id} type={type} />)}
                </div>
            )}
        </div>
    );
}
