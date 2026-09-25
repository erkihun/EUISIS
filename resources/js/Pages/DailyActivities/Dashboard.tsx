import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ActivityFilters from '@/Components/dailyActivity/ActivityFilters';
import LogTable from '@/Components/dailyActivity/LogTable';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import { panelCls } from '@/Components/dailyActivity/helpers';
import type { FilterOptions, LogSummary, ManagementAbilities } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
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

/**
 * Activity Dashboard. Real, scoped counts only: no trend arrows, no
 * percentages, no ranking. Activity volume is not productivity.
 */
export default function DailyActivitiesDashboard({ today, todayFigures, days, pendingReview, returned, awaitingReviewList, reviewRequired, filters, options, can }: Props): JSX.Element {
    const { t } = useLocale();

    const figures: { label: string; value: number; tone?: 'danger' | 'warning'; href?: string }[] = [
        { label: t('dailyActivities.dashboard.expectedToday'), value: todayFigures.expected },
        { label: t('dailyActivities.dashboard.submittedToday'), value: todayFigures.submitted },
        { label: t('dailyActivities.dashboard.notYetToday'), value: todayFigures.missing, tone: todayFigures.missing > 0 ? 'warning' : undefined },
        { label: t('dailyActivities.dashboard.pendingReview'), value: pendingReview, href: can.review ? route('daily-activities.review-queue') : undefined },
        { label: t('dailyActivities.dashboard.returned'), value: returned },
        { label: t('dailyActivities.dashboard.lateToday'), value: todayFigures.late },
        { label: t('dailyActivities.dashboard.onLeaveToday'), value: todayFigures.leave },
    ];

    const th = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400';
    const td = 'px-3 py-2 text-sm tabular-nums text-gray-800 dark:text-slate-200';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.dashboard.title')} description={t('dailyActivities.dashboard.description')} />}>
            <Head title={t('dailyActivities.dashboard.title')} />
            <div className="space-y-4">
                <ManagementNav can={can} current="daily-activities.dashboard" />
                {options.organizations.length > 1 || options.units.length > 0 ? (
                    <ActivityFilters routeName="daily-activities.dashboard" filters={filters} options={options} fields={['organization', 'unit']} />
                ) : null}

                <section aria-label={t('dailyActivities.entry.today')}>
                    <p className="mb-1 text-xs text-gray-500 dark:text-slate-400">
                        {t('dailyActivities.entry.today')} · <LocalizedDateDisplay value={today} />
                    </p>
                    <dl className={`${panelCls} grid grid-cols-2 divide-gray-100 sm:grid-cols-4 lg:grid-cols-7 dark:divide-slate-800`}>
                        {figures.map((figure) => {
                            const value = (
                                <dd className={[
                                    'text-xl font-semibold tabular-nums',
                                    figure.tone === 'warning' ? 'text-amber-700 dark:text-amber-400' : 'text-gray-900 dark:text-slate-100',
                                ].join(' ')}>{figure.value}</dd>
                            );
                            return (
                                <div key={figure.label} className="border-b border-e border-gray-100 px-3 py-2.5 dark:border-slate-800">
                                    <dt className="text-xs text-gray-500 dark:text-slate-400">{figure.label}</dt>
                                    {figure.href ? <Link href={figure.href} className="hover:underline">{value}</Link> : value}
                                </div>
                            );
                        })}
                    </dl>
                </section>

                <section className="grid gap-4 lg:grid-cols-[minmax(0,26rem)_1fr]">
                    <div>
                        <h2 className="mb-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.dashboard.lastSevenDays')}</h2>
                        <div className={panelCls}>
                            <table className="w-full">
                                <thead className="border-b border-gray-200 dark:border-slate-800">
                                    <tr>
                                        <th className={th}>{t('dailyActivities.columns.date')}</th>
                                        <th className={`${th} text-right`}>{t('dailyActivities.dashboard.expected')}</th>
                                        <th className={`${th} text-right`}>{t('dailyActivities.dashboard.submitted')}</th>
                                        <th className={`${th} text-right`}>{t('dailyActivities.dashboard.missing')}</th>
                                        <th className={`${th} text-right`}>{t('dailyActivities.dashboard.late')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {days.map((day) => (
                                        <tr key={day.date}>
                                            <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={day.date} /></td>
                                            <td className={`${td} text-right`}>{day.expected}</td>
                                            <td className={`${td} text-right`}>{day.submitted}</td>
                                            <td className={`${td} text-right ${day.missing > 0 ? 'text-red-700 dark:text-red-400' : ''}`}>{day.missing}</td>
                                            <td className={`${td} text-right`}>{day.late}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h2 className="mb-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.dashboard.awaitingReview')}</h2>
                        {!reviewRequired
                            ? <p className={`${panelCls} p-3 text-sm text-gray-500 dark:text-slate-400`}>{t('dailyActivities.dashboard.reviewOff')}</p>
                            : <LogTable logs={awaitingReviewList} emptyText={t('dailyActivities.dashboard.noneWaiting')} />}
                    </div>
                </section>

                <p className="text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.notAttendance')}</p>
            </div>
        </AuthenticatedLayout>
    );
}
