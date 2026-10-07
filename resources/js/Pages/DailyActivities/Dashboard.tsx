import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ActivityFilters from '@/Components/dailyActivity/ActivityFilters';
import LogTable from '@/Components/dailyActivity/LogTable';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import type { FilterOptions, LogSummary, ManagementAbilities } from '@/Components/dailyActivity/types';
import { ChevronRight } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Card, cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

type DayFigures = { date: string; expected: number; submitted: number; missing: number; late: number; leave: number };

type Props = {
    today: string;
    todayFigures: DayFigures;
    days: DayFigures[];
    pendingReview: number;
    returned: number;
    awaitingReviewList: LogSummary[];
    reviewRequired: boolean;
    filters: Record<string, string>;
    options: FilterOptions;
    can: ManagementAbilities;
};

type Figure = { label: string; value: number; warn?: boolean; href?: string };

/** One count. Warnings are colour only when there is something to act on; links lead to that work. */
function FigureCell({ label, value, warn = false, href }: Figure) {
    const content = (
        <>
            <dt className="text-xs text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className={cx('mt-1 flex items-center gap-1 text-2xl font-semibold tabular-nums',
                warn ? 'text-amber-700 dark:text-amber-400' : 'text-[color:var(--app-foreground)]')}>
                {value}
                {href && <ChevronRight className="h-4 w-4 text-[color:var(--app-muted-foreground)] transition-transform group-hover:translate-x-0.5" aria-hidden="true" />}
            </dd>
        </>
    );
    return href
        ? <Link href={href} className="group block px-4 py-3 transition-colors hover:bg-[color:var(--app-surface-muted)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)]">{content}</Link>
        : <div className="px-4 py-3">{content}</div>;
}

function FigureGroup({ title, aside, figures, columns }: { title: string; aside?: JSX.Element; figures: Figure[]; columns: string }) {
    return (
        <Card className="overflow-hidden p-0">
            <div className="flex items-baseline justify-between gap-3 border-b border-[color:var(--app-border)] px-4 py-2.5">
                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                {aside && <span className="text-xs text-[color:var(--app-muted-foreground)]">{aside}</span>}
            </div>
            <dl className={cx('grid divide-[color:var(--app-border)]', columns)}>
                {figures.map((figure) => <div key={figure.label}><FigureCell {...figure} /></div>)}
            </dl>
        </Card>
    );
}

/**
 * Activity Dashboard. Real, scoped counts only: no trend arrows, no
 * percentages, no ranking. Activity volume is not productivity.
 */
export default function DailyActivitiesDashboard({ today, todayFigures, days, pendingReview, returned, awaitingReviewList, reviewRequired, filters, options, can }: Props): JSX.Element {
    const { t } = useLocale();
    const reviewQueue = can.review ? route('daily-activities.review-queue') : undefined;

    const registration: Figure[] = [
        { label: t('dailyActivities.dashboard.expectedToday'), value: todayFigures.expected },
        { label: t('dailyActivities.dashboard.submittedToday'), value: todayFigures.submitted },
        { label: t('dailyActivities.dashboard.notYetToday'), value: todayFigures.missing, warn: todayFigures.missing > 0 },
        { label: t('dailyActivities.dashboard.lateToday'), value: todayFigures.late },
        { label: t('dailyActivities.dashboard.onLeaveToday'), value: todayFigures.leave },
    ];
    const review: Figure[] = [
        { label: t('dailyActivities.dashboard.pendingReview'), value: pendingReview, href: reviewQueue },
        { label: t('dailyActivities.dashboard.returned'), value: returned },
    ];

    const th = 'px-4 py-2.5 text-left text-xs font-medium text-[color:var(--app-muted-foreground)]';
    const td = 'px-4 py-2.5 text-sm tabular-nums text-[color:var(--app-foreground)]';
    // Zero reads as a quiet dash-weight value; non-zero missing registrations stand out.
    const count = (value: number, alert = false) => (
        <span className={value === 0 ? 'text-[color:var(--app-muted-foreground)]' : alert ? 'font-medium text-red-700 dark:text-red-400' : ''}>{value}</span>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.dashboard.title')} description={t('dailyActivities.dashboard.description')} />}>
            <Head title={t('dailyActivities.dashboard.title')} />
            <div className="space-y-5">
                <ManagementNav can={can} current="daily-activities.dashboard" />
                {options.organizations.length > 1 || options.units.length > 0 ? (
                    <ActivityFilters routeName="daily-activities.dashboard" filters={filters} options={options} fields={['organization', 'unit']} />
                ) : null}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,2fr)]">
                    <FigureGroup title={t('dailyActivities.dashboard.registrationToday')} aside={<LocalizedDateDisplay value={today} />}
                        figures={registration} columns="grid-cols-2 sm:grid-cols-5 sm:divide-x" />
                    <FigureGroup title={t('dailyActivities.dashboard.reviewSection')} figures={review} columns="grid-cols-2 divide-x" />
                </div>

                <section className="grid gap-4 lg:grid-cols-[minmax(0,28rem)_1fr]">
                    <Card className="overflow-hidden p-0">
                        <h2 className="border-b border-[color:var(--app-border)] px-4 py-2.5 text-sm font-semibold text-[color:var(--app-foreground)]">{t('dailyActivities.dashboard.lastSevenDays')}</h2>
                        <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-[color:var(--app-surface-muted)]">
                                <tr>
                                    <th scope="col" className={th}>{t('dailyActivities.columns.date')}</th>
                                    <th scope="col" className={`${th} text-right`}>{t('dailyActivities.dashboard.expected')}</th>
                                    <th scope="col" className={`${th} text-right`}>{t('dailyActivities.dashboard.submitted')}</th>
                                    <th scope="col" className={`${th} text-right`}>{t('dailyActivities.dashboard.missing')}</th>
                                    <th scope="col" className={`${th} text-right`}>{t('dailyActivities.dashboard.late')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[color:var(--app-border)]">
                                {days.map((day) => (
                                    <tr key={day.date}>
                                        <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={day.date} /></td>
                                        <td className={`${td} text-right`}>{count(day.expected)}</td>
                                        <td className={`${td} text-right`}>{count(day.submitted)}</td>
                                        <td className={`${td} text-right`}>{count(day.missing, true)}</td>
                                        <td className={`${td} text-right`}>{count(day.late)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>
                    </Card>

                    <div className="min-w-0">
                        <div className="mb-2 flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                                {t('dailyActivities.dashboard.awaitingReview')}
                                {reviewRequired && pendingReview > 0 && <span className="ms-2 font-normal tabular-nums text-[color:var(--app-muted-foreground)]">({pendingReview})</span>}
                            </h2>
                            {reviewRequired && reviewQueue && (
                                <Link href={reviewQueue} className="inline-flex items-center gap-1 text-sm font-medium text-[color:var(--color-primary)] hover:underline">
                                    {t('dailyActivities.dashboard.openReviewQueue')}<ChevronRight className="h-4 w-4" aria-hidden="true" />
                                </Link>
                            )}
                        </div>
                        {!reviewRequired
                            ? <Alert tone="neutral">{t('dailyActivities.dashboard.reviewOff')}</Alert>
                            : <LogTable logs={awaitingReviewList} emptyText={t('dailyActivities.dashboard.noneWaiting')} />}
                    </div>
                </section>

                <Alert tone="info">{t('dailyActivities.notAttendance')}</Alert>
            </div>
        </AuthenticatedLayout>
    );
}
