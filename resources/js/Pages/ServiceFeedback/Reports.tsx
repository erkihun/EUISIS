import FeedbackFilterBar, { type FeedbackFilterOptions, type FeedbackFilters } from '@/Components/ServiceFeedback/FeedbackFilterBar';
import {
    FeedbackPageHeader,
    RatingBar,
    RatingPill,
    exportHref,
    percent,
    ratingTone,
    toneClasses,
    useNameLabel,
    type FeedbackSummary,
} from '@/Components/ServiceFeedback/feedbackUi';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { DownloadIcon } from '@/Components/Icons';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName, cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import { useState, type JSX, type ReactNode } from 'react';

type PerformanceRow = {
    id: string | null;
    name: string | null;
    employee_number?: string | null;
    total: number;
    average: number;
    low_rated?: number;
};

type Props = {
    summary: { total: number; average: number; low_rated: number; pending: number };
    byEmployee: PerformanceRow[];
    byOrganization: PerformanceRow[];
    byServiceType: PerformanceRow[];
    lowRated: FeedbackSummary[];
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    statuses: string[];
    can: { export: boolean };
};

type GroupKey = 'employee' | 'organization' | 'serviceType';

export default function ServiceFeedbackReports({
    summary,
    byEmployee,
    byOrganization,
    byServiceType,
    lowRated,
    filters,
    filterOptions,
    statuses,
    can,
}: Props): JSX.Element {
    const { t } = useLocale();
    const label = useNameLabel();
    const [group, setGroup] = useState<GroupKey>('employee');

    /*
     * Each breakdown is ordered worst-average-first by the query service: the
     * point of these tables is to surface the desks that need attention, not
     * to rank the best. They share one panel because they are the same shape.
     */
    const groups: Record<GroupKey, { title: string; tab: string; rows: PerformanceRow[]; filterKey: keyof FeedbackFilters; showLow: boolean }> = {
        employee: { title: t('serviceFeedback.averageRatingByEmployee'), tab: t('serviceFeedback.filterEmployee'), rows: byEmployee, filterKey: 'employee_id', showLow: true },
        organization: { title: t('serviceFeedback.averageRatingByOrganization'), tab: t('serviceFeedback.filterOrganization'), rows: byOrganization, filterKey: 'organization_id', showLow: false },
        serviceType: { title: t('serviceFeedback.serviceTypePerformance'), tab: t('serviceFeedback.filterServiceType'), rows: byServiceType, filterKey: 'service_type_id', showLow: false },
    };
    const active = groups[group];

    // A row opens the inbox narrowed to it, keeping the scope chosen here.
    const inboxHref = (row: PerformanceRow) => {
        const params = Object.fromEntries(
            Object.entries({ ...filters, [active.filterKey]: row.id ?? '' }).filter(([, value]) => value !== undefined && value !== ''),
        );
        return route('service-feedback.admin.index', params);
    };

    const lowShare = percent(summary.low_rated, summary.total);

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.reports')} />

            <div className="space-y-6">
                <FeedbackPageHeader
                    current="reports"
                    title={t('serviceFeedback.reports')}
                    description={t('serviceFeedback.reportsSubtitle')}
                    pendingCount={summary.pending}
                    actions={can.export ? (
                        <a href={exportHref(filters)} className={buttonClassName({ variant: 'outline' })}>
                            <DownloadIcon aria-hidden="true" className="h-4 w-4" />
                            {t('serviceFeedback.exportCsv')}
                        </a>
                    ) : undefined}
                />

                <FeedbackFilterBar
                    routeName="service-feedback.admin.reports"
                    filters={filters}
                    filterOptions={filterOptions}
                    statuses={statuses}
                    presets
                />

                <section aria-label={t('serviceFeedback.reports')} className="grid gap-4 sm:grid-cols-3">
                    <Stat label={t('serviceFeedback.totalFeedback')} value={summary.total.toLocaleString()}>
                        <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.inSelectedScope')}</span>
                    </Stat>
                    <Stat
                        label={t('serviceFeedback.averageRating')}
                        value={(
                            <span className="flex items-baseline gap-1.5">
                                <span className={summary.total > 0 ? toneClasses[ratingTone(summary.average)].text : undefined}>
                                    {summary.total > 0 ? summary.average.toFixed(2) : '—'}
                                </span>
                                <span className="text-sm font-normal text-[color:var(--app-muted-foreground)]">/ 5</span>
                            </span>
                        )}
                    >
                        <RatingBar value={summary.total > 0 ? summary.average : 0} />
                    </Stat>
                    <Stat
                        label={t('serviceFeedback.lowRatings')}
                        value={(
                            <span className="flex items-baseline gap-2">
                                <span className={summary.low_rated > 0 ? toneClasses.low.text : undefined}>{summary.low_rated.toLocaleString()}</span>
                                {summary.total > 0 && <span className={cx('text-sm font-semibold', toneClasses.low.text)}>{lowShare}%</span>}
                            </span>
                        )}
                    >
                        <span aria-hidden="true" className="block h-1.5 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]">
                            <span className={cx('block h-full rounded-full', toneClasses.low.bar)} style={{ width: `${lowShare}%` }} />
                        </span>
                    </Stat>
                </section>

                {/* Performance breakdowns share one panel, switched by the segmented control. */}
                <section aria-labelledby="performance-heading" className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="performance-heading" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{active.title}</h2>
                            <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.lowestFirstHint')}</p>
                        </div>
                        <div role="group" aria-label={t('serviceFeedback.groupBy')} className="flex gap-0.5 self-start rounded-lg bg-[color:var(--app-surface-muted)] p-[3px] sm:self-auto">
                            {(Object.keys(groups) as GroupKey[]).map((key) => {
                                const selected = key === group;
                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => setGroup(key)}
                                        className={cx(
                                            'h-[30px] whitespace-nowrap rounded-md px-3.5 text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                            selected
                                                ? 'bg-[color:var(--app-surface)] font-semibold text-[color:var(--app-foreground)] shadow-sm ring-1 ring-[color:var(--app-border)]'
                                                : 'font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]',
                                        )}
                                    >
                                        {groups[key].tab}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {active.rows.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noFeedbackYet')}</p>
                    ) : (
                        <div className="max-h-[36rem] overflow-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead className="sticky top-0 z-[1] bg-[color:var(--app-surface-muted)]">
                                    <tr className="text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                                        <th scope="col" className="w-12 px-5 py-2.5 text-left">#</th>
                                        <th scope="col" className="px-3 py-2.5 text-left">{active.tab}</th>
                                        <th scope="col" className="w-28 px-3 py-2.5 text-right">{t('serviceFeedback.responses')}</th>
                                        {active.showLow && <th scope="col" className="w-28 px-3 py-2.5 text-right">{t('serviceFeedback.lowShort')}</th>}
                                        <th scope="col" className="w-56 py-2.5 pl-3 pr-5 text-right">{t('serviceFeedback.averageRating')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[color:var(--app-border)]">
                                    {active.rows.map((row, index) => (
                                        <tr key={row.id ?? row.name ?? index} className="transition-colors hover:bg-[color:var(--app-surface-muted)]">
                                            <td className="px-5 py-3 text-xs tabular-nums text-[color:var(--app-muted-foreground)]">{index + 1}</td>
                                            <td className="px-3 py-3">
                                                {row.id ? (
                                                    <Link href={inboxHref(row)} className="font-medium text-[color:var(--app-foreground)] hover:text-[color:var(--color-primary)] hover:underline">
                                                        {row.name ?? '—'}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium text-[color:var(--app-foreground)]">{row.name ?? '—'}</span>
                                                )}
                                                {row.employee_number && <div className="text-xs text-[color:var(--app-muted-foreground)]">{row.employee_number}</div>}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-[color:var(--app-foreground)]">{row.total.toLocaleString()}</td>
                                            {active.showLow && (
                                                <td className="px-3 py-3 text-right">
                                                    <span className={cx(
                                                        'inline-flex h-[22px] min-w-[1.75rem] items-center justify-center rounded-full px-2 text-xs font-semibold tabular-nums',
                                                        (row.low_rated ?? 0) > 0 ? toneClasses.low.pill : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
                                                    )}>
                                                        {row.low_rated ?? 0}
                                                    </span>
                                                </td>
                                            )}
                                            <td className="py-3 pl-3 pr-5">
                                                {/* Bar first, number second: the bar is what makes a weak desk visible without reading decimals. */}
                                                <div className="flex items-center justify-end gap-3">
                                                    <RatingBar value={row.average} className="h-2 w-24 sm:w-36" />
                                                    <span className={cx('w-10 text-right font-bold tabular-nums', toneClasses[ratingTone(row.average)].text)}>{row.average.toFixed(2)}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {/* Low rating watchlist */}
                <section aria-labelledby="watchlist-heading" className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <div className="flex items-center gap-2.5 border-b border-[color:var(--app-border)] px-5 py-4">
                        <h2 id="watchlist-heading" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.lowRatingWatchlist')}</h2>
                        {summary.low_rated > 0 && (
                            <span className={cx('inline-flex h-5 items-center rounded-full px-2 text-xs font-bold tabular-nums', toneClasses.low.pill)}>
                                {summary.low_rated.toLocaleString()}
                            </span>
                        )}
                    </div>

                    {lowRated.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noLowRatings')}</p>
                    ) : (
                        // Capped so a long watchlist scrolls inside the card instead of pushing the page away.
                        <ul className="max-h-[32rem] divide-y divide-[color:var(--app-border)] overflow-y-auto">
                            {lowRated.map((row) => (
                                <li key={row.id}>
                                    <Link href={route('service-feedback.admin.show', row.id)} className="flex items-start gap-3.5 px-5 py-3.5 transition-colors hover:bg-[color:var(--app-surface-muted)]">
                                        <RatingPill rating={row.rating} />
                                        <span className="min-w-0 flex-1">
                                            <span className={cx('block text-sm leading-relaxed', row.comment ? 'text-[color:var(--app-foreground)]' : 'italic text-[color:var(--app-muted-foreground)]')}>
                                                {row.comment || t('serviceFeedback.noCommentRatingOnly')}
                                            </span>
                                            <span className="mt-1 block text-xs text-[color:var(--app-muted-foreground)]">
                                                {row.employee?.name ?? '—'} · {label(row.service_type)} · {label(row.organization)}
                                            </span>
                                        </span>
                                        <span className="hidden shrink-0 whitespace-nowrap text-xs text-[color:var(--app-muted-foreground)] sm:block">
                                            <LocalizedDateDisplay value={row.created_at} />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, children }: { label: string; value: ReactNode; children: ReactNode }): JSX.Element {
    return (
        <div className="flex flex-col gap-2 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <span className="text-[13px] font-medium text-[color:var(--app-muted-foreground)]">{label}</span>
            <span className="text-3xl font-bold leading-none tabular-nums text-[color:var(--app-foreground)]">{value}</span>
            {children}
        </div>
    );
}
