import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ActivityFilters, { type FilterField } from '@/Components/dailyActivity/ActivityFilters';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import { DayStatusBadge, LogStatusBadge } from '@/Components/dailyActivity/StatusBadges';
import { compactInputCls, fill, panelCls, secondaryBtn } from '@/Components/dailyActivity/helpers';
import type { FilterOptions, ManagementAbilities } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, router } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

type Report = {
    type: string;
    columns: string[];
    rows: Record<string, string | number | boolean | null>[];
    truncated: boolean;
    total: number;
    from: string;
    to: string;
};

type Props = {
    type: string;
    types: string[];
    report: Report;
    filters: Record<string, string>;
    options: FilterOptions;
    can: ManagementAbilities;
};

const FIELDS: Record<string, FilterField[]> = {
    daily_submission: ['search', 'date', 'organization', 'unit', 'position'],
    employee_activity: ['search', 'dateRange', 'organization', 'unit', 'position', 'status', 'service'],
    unit_activity: ['dateRange', 'organization', 'unit'],
    missing: ['search', 'dateRange', 'organization', 'unit', 'position'],
    late: ['search', 'dateRange', 'organization', 'unit'],
    review_status: ['search', 'dateRange', 'organization', 'unit', 'status'],
    by_task: ['dateRange', 'organization', 'unit', 'service'],
    monthly_summary: ['search', 'month', 'organization', 'unit', 'position'],
};

const NUMERIC = new Set(['items', 'employees', 'required', 'submitted', 'approved', 'returned', 'missing', 'late', 'leave', 'holiday', 'completed', 'in_progress', 'blocked', 'carried_forward', 'waiting_days']);

/**
 * The eight Daily Activity reports on one page. The server builds every row
 * inside the viewer's organization scope; exports repeat the same query.
 */
export default function DailyActivitiesReports({ type, types, report, filters, options, can }: Props): JSX.Element {
    const { t, locale } = useLocale();

    function cell(column: string, value: string | number | boolean | null): ReactNode {
        if (value === null || value === '') return '—';
        if (column === 'date') return <LocalizedDateDisplay value={String(value)} />;
        if (column === 'submitted_at' || column === 'reviewed_at') return <LocalizedDateDisplay value={String(value)} withTime />;
        if (typeof value === 'boolean') return value ? t('dailyActivities.yes') : t('dailyActivities.no');
        if (column === 'status') return <LogStatusBadge status={String(value)} />;
        if (column === 'day_status') return <DayStatusBadge status={String(value)} />;
        if (column === 'progress_status') return t(`dailyActivities.progress.${value}`);
        if (column === 'source') return t(`dailyActivities.reports.sources.${value}`);
        return String(value);
    }

    const exportUrl = (format: string) => route('daily-activities.reports.export', { ...filters, type, format, locale });

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.reports.title')} description={t('dailyActivities.reports.description')} />}>
            <Head title={t('dailyActivities.reports.title')} />
            <div className="space-y-3">
                <ManagementNav can={can} current="daily-activities.reports" />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <label className="flex w-full items-center gap-2 text-sm sm:w-auto">
                        <span className="text-gray-600 dark:text-slate-400">{t('dailyActivities.reports.type')}</span>
                        <select
                            className={`${compactInputCls} flex-1 sm:flex-none`}
                            value={type}
                            onChange={(e) => router.get(route('daily-activities.reports'), { type: e.target.value })}
                        >
                            {types.map((value) => <option key={value} value={value}>{t(`dailyActivities.reports.types.${value}`)}</option>)}
                        </select>
                    </label>
                    {can.export && (
                        <div className="flex w-full gap-2 sm:w-auto">
                            {(['xlsx', 'csv', 'pdf'] as const).map((format) => (
                                <a key={format} href={exportUrl(format)} className={`${secondaryBtn} min-h-9 flex-1 py-1.5 uppercase sm:flex-none`}>
                                    {format === 'xlsx' ? 'Excel' : format}
                                </a>
                            ))}
                        </div>
                    )}
                </div>

                <ActivityFilters key={type} routeName="daily-activities.reports" filters={filters} options={options} fields={FIELDS[type] ?? []} keep={{ type }} />

                <p className="text-xs text-gray-500 dark:text-slate-400">
                    <LocalizedDateDisplay value={report.from} /> – <LocalizedDateDisplay value={report.to} />
                    {' · '}{fill(t('dailyActivities.reports.rows'), { count: report.total })}
                </p>

                {report.truncated && (
                    <p className="rounded-panel border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        {fill(t('dailyActivities.reports.truncated'), { count: report.rows.length, total: report.total })}
                    </p>
                )}

                {report.rows.length === 0 ? (
                    <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('dailyActivities.reports.empty')}</p>
                ) : (
                    // A report is inherently tabular; only the table scrolls sideways, never the page.
                    <div className={`${panelCls} overflow-x-auto`}>
                        <table className="w-full min-w-max">
                            <thead className="border-b border-gray-200 dark:border-slate-800">
                                <tr>
                                    {report.columns.map((column) => (
                                        <th key={column} className={`px-3 py-2 text-xs font-medium text-gray-500 dark:text-slate-400 ${NUMERIC.has(column) ? 'text-right' : 'text-left'}`}>
                                            {t(`dailyActivities.reports.columns.${column}`)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {report.rows.map((row, index) => (
                                    <tr key={index} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                        {report.columns.map((column) => (
                                            <td key={column} className={`max-w-xs px-3 py-1.5 align-top text-sm text-gray-800 dark:text-slate-200 ${NUMERIC.has(column) ? 'text-right tabular-nums' : 'break-words'}`}>
                                                {cell(column, row[column] ?? null)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.notAttendance')}</p>
            </div>
        </AuthenticatedLayout>
    );
}
