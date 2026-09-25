import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import Pager from '@/Components/dailyActivity/Pager';
import { LateMark, LogStatusBadge } from '@/Components/dailyActivity/StatusBadges';
import { compactInputCls, panelCls, primaryBtn } from '@/Components/dailyActivity/helpers';
import type { LogSummary, Paginated } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Row = LogSummary & { review_comment: string | null; titles: string[] };

type Props = {
    has_employee: boolean;
    logs: Paginated<Row> | null;
    filters: { status: string | null; date_from: string | null; date_to: string | null };
    statuses: string[];
};

/** My Activity History: every day the employee recorded, newest first. */
export default function DailyActivityHistory({ has_employee, logs, filters, statuses }: Props): JSX.Element {
    const { t } = useLocale();
    const [values, setValues] = useState({
        status: filters.status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get(route('employee.daily-activity.history'), Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')), { preserveScroll: true });
    }

    return (
        <PortalPage title={t('dailyActivities.history.title')}>
            <div className="space-y-3">

                {!has_employee || !logs ? (
                    <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{t('dailyActivities.noEmployee')}</p>
                ) : (
                    <>
                        <form onSubmit={apply} className="flex flex-wrap items-end gap-2 rounded-panel border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                            <select className={`${compactInputCls} w-full sm:w-auto`} value={values.status} onChange={(e) => setValues({ ...values, status: e.target.value })}>
                                <option value="">{t('dailyActivities.filters.allStatuses')}</option>
                                {statuses.map((s) => <option key={s} value={s}>{t(`dailyActivities.statuses.${s}`)}</option>)}
                            </select>
                            <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                                {t('dailyActivities.filters.from')}
                                <LocalizedDatePicker value={values.date_from} onChange={(v) => setValues({ ...values, date_from: v })} />
                            </label>
                            <label className="flex w-[calc(50%-0.25rem)] flex-col text-xs text-gray-500 sm:w-40 dark:text-slate-400">
                                {t('dailyActivities.filters.to')}
                                <LocalizedDatePicker value={values.date_to} onChange={(v) => setValues({ ...values, date_to: v })} />
                            </label>
                            <button type="submit" className={`${primaryBtn} min-h-9 w-full py-1.5 sm:w-auto`}>{t('dailyActivities.actions.apply')}</button>
                        </form>

                        {logs.data.length === 0 ? (
                            <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('dailyActivities.history.empty')}</p>
                        ) : (
                            <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
                                {logs.data.map((log) => (
                                    <li key={log.id}>
                                        <Link href={route('employee.daily-activity.entry', { date: log.activity_date })} className="block px-3 py-2.5 hover:bg-gray-50 sm:grid sm:grid-cols-[9rem_1fr_auto] sm:items-start sm:gap-3 dark:hover:bg-slate-800/50">
                                            <div className="flex items-center justify-between gap-2 sm:block">
                                                <LocalizedDateDisplay value={log.activity_date} className="text-sm font-medium text-gray-900 dark:text-slate-100" />
                                                <span className="flex items-center gap-1.5 sm:hidden">
                                                    {log.is_late && <LateMark />}
                                                    <LogStatusBadge status={log.status} />
                                                </span>
                                            </div>
                                            <div className="mt-1 min-w-0 text-sm text-gray-700 sm:mt-0 dark:text-slate-300">
                                                <p className="truncate">{log.titles.join(' · ')}</p>
                                                <p className="text-xs text-gray-500 dark:text-slate-400">{log.items_count} {t('dailyActivities.summary.items')}</p>
                                                {log.status === 'returned_for_correction' && log.review_comment && (
                                                    <p className="mt-1 line-clamp-2 text-xs text-amber-800 dark:text-amber-300">{log.review_comment}</p>
                                                )}
                                            </div>
                                            <span className="hidden items-center gap-1.5 sm:flex">
                                                {log.is_late && <LateMark />}
                                                <LogStatusBadge status={log.status} />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Pager meta={logs.meta} links={logs.links} />
                    </>
                )}
            </div>
        </PortalPage>
    );
}
