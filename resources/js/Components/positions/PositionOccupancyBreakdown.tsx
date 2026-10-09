import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { Card, EmptyState, cx } from '@euisis/ui';

export type BreakdownSlice = {
    key: string;
    label_en: string | null;
    label_am: string | null;
    total: number;
    filled: number;
    vacant: number;
};

export type Breakdowns = {
    grouping: 'organization' | 'unit';
    by_group: BreakdownSlice[];
    by_grade_level: BreakdownSlice[];
    by_job_family: BreakdownSlice[];
};

type Props = {
    breakdowns: Breakdowns;
};

type TabKey = 'group' | 'grade_level' | 'job_family';

/** Keys the backend uses for the rolled-up tail and for rows missing the grouping value. */
const OTHER_KEY = '__other__';

const FILLED = 'bg-emerald-500 dark:bg-emerald-400';
const VACANT = 'bg-amber-400 dark:bg-amber-500';

/**
 * Occupancy per group as a ranked list of stacked bars. Each bar's length is the group's size relative
 * to the largest group, and its split is filled vs vacant within that group, so both scale and occupancy
 * read at a glance. Full labels wrap instead of being truncated, which a chart axis could not do.
 */
export default function PositionOccupancyBreakdown({ breakdowns }: Props) {
    const { locale, t } = useLocale();
    const [tab, setTab] = useState<TabKey>('group');
    const useAmharic = locale === 'am';

    const tabs: { key: TabKey; label: string; data: BreakdownSlice[] }[] = [
        {
            key: 'group',
            label: breakdowns.grouping === 'unit' ? t('positions.organizationUnit') : t('positions.organization'),
            data: breakdowns.by_group,
        },
        { key: 'grade_level', label: t('positions.gradeLevel'), data: breakdowns.by_grade_level },
        { key: 'job_family', label: t('positions.jobFamily'), data: breakdowns.by_job_family },
    ];

    const active = tabs.find((entry) => entry.key === tab) ?? tabs[0];

    const rows = useMemo(() => {
        const largest = Math.max(1, ...active.data.map((slice) => slice.total));
        return active.data.map((slice) => {
            const named = (useAmharic ? slice.label_am : slice.label_en) ?? slice.label_en;
            // Percentages are relative to the row's own total: each bar reads as its own occupancy split.
            const share = (value: number) => (slice.total > 0 ? (value / slice.total) * 100 : 0);
            return {
                key: slice.key,
                label: slice.key === OTHER_KEY ? t('positions.otherGroups') : named ?? t('positions.notSpecified'),
                filled: slice.filled,
                vacant: slice.vacant,
                total: slice.total,
                filledPercent: share(slice.filled),
                vacantPercent: share(slice.vacant),
                scale: (slice.total / largest) * 100,
            };
        });
    }, [active.data, useAmharic, t]);

    return (
        <Card className="p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('positions.occupancyBreakdown')}</h2>
                    <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[color:var(--app-muted-foreground)]">
                        <span className="inline-flex items-center gap-1.5"><span className={cx('h-2 w-2 rounded-full', FILLED)} aria-hidden="true" />{t('positions.filled')}</span>
                        <span className="inline-flex items-center gap-1.5"><span className={cx('h-2 w-2 rounded-full', VACANT)} aria-hidden="true" />{t('positions.vacant')}</span>
                    </p>
                </div>
                <div role="group" aria-label={t('positions.occupancyBreakdown')} className="inline-flex flex-wrap rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] p-0.5">
                    {tabs.map((entry) => (
                        <button key={entry.key} type="button" aria-pressed={entry.key === active.key} onClick={() => setTab(entry.key)}
                            className={cx('rounded-[calc(var(--radius-control)-2px)] px-3 py-1 text-xs font-medium transition-colors',
                                entry.key === active.key ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm'
                                    : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]')}>
                            {entry.label}
                        </button>
                    ))}
                </div>
            </div>

            {rows.length === 0 ? (
                <EmptyState title={t('positions.noPositionStatusFound')} />
            ) : (
                <ul className="space-y-3.5">
                    {rows.map((row) => (
                        <li key={row.key}>
                            <div className="flex items-baseline justify-between gap-4 text-sm">
                                <span className="min-w-0 font-medium text-[color:var(--app-foreground)]">{row.label}</span>
                                <span className="shrink-0 tabular-nums text-[color:var(--app-muted-foreground)]">
                                    {row.filled.toLocaleString()} / {row.total.toLocaleString()} · {Math.round(row.filledPercent)}%
                                </span>
                            </div>
                            <div className="mt-1.5 h-2.5 rounded-full bg-[color:var(--app-surface-muted)]">
                                <div className="flex h-full overflow-hidden rounded-full" style={{ width: `${Math.max(row.scale, 2)}%` }}
                                    role="img" aria-label={`${row.label}: ${row.filled} ${t('positions.filled')}, ${row.vacant} ${t('positions.vacant')}`}>
                                    <div className={FILLED} style={{ width: `${row.filledPercent}%` }} title={`${t('positions.filled')}: ${row.filled}`} />
                                    <div className={VACANT} style={{ width: `${row.vacantPercent}%` }} title={`${t('positions.vacant')}: ${row.vacant}`} />
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
