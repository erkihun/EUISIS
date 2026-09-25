import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { named, panelCls } from './helpers';
import { LateMark, LogStatusBadge } from './StatusBadges';
import type { LogSummary } from './types';

/**
 * Daily logs as a dense table on wide screens and as stacked rows on phones,
 * so no page needs horizontal scrolling at 320px.
 */
export default function LogTable({ logs, emptyText }: { logs: LogSummary[]; emptyText: string }) {
    const { t, locale } = useLocale();

    if (logs.length === 0) {
        return <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{emptyText}</p>;
    }

    const th = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400';
    const td = 'px-3 py-2 align-top text-sm text-gray-800 dark:text-slate-200';

    return (
        <div className={panelCls}>
            <table className="hidden w-full md:table">
                <thead className="border-b border-gray-200 dark:border-slate-800">
                    <tr>
                        <th className={th}>{t('dailyActivities.columns.employee')}</th>
                        <th className={th}>{t('dailyActivities.columns.date')}</th>
                        <th className={th}>{t('dailyActivities.columns.unit')}</th>
                        <th className={th}>{t('dailyActivities.columns.position')}</th>
                        <th className={`${th} text-right`}>{t('dailyActivities.columns.activities')}</th>
                        <th className={th}>{t('dailyActivities.columns.submittedAt')}</th>
                        <th className={th}>{t('dailyActivities.columns.status')}</th>
                        <th className={th}><span className="sr-only">{t('dailyActivities.actions.view')}</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                    {logs.map((log) => (
                        <tr key={log.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                            <td className={td}>
                                <span className="font-medium">{log.employee?.full_name}</span>
                                <span className="block text-xs text-gray-500 dark:text-slate-400">{log.employee?.employee_number}</span>
                            </td>
                            <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={log.activity_date} /></td>
                            <td className={td}>{named(log.organization_unit, locale) || '—'}</td>
                            <td className={td}>{named(log.position, locale) || '—'}</td>
                            <td className={`${td} text-right tabular-nums`}>{log.items_count}</td>
                            <td className={`${td} whitespace-nowrap`}>
                                <LocalizedDateDisplay value={log.submitted_at} withTime />
                                {log.is_late && <span className="ms-1.5"><LateMark /></span>}
                            </td>
                            <td className={td}><LogStatusBadge status={log.status} /></td>
                            <td className={`${td} text-right`}>
                                <Link href={route('daily-activities.show', log.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">
                                    {t('dailyActivities.actions.view')}
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <ul className="divide-y divide-gray-100 md:hidden dark:divide-slate-800">
                {logs.map((log) => (
                    <li key={log.id}>
                        <Link href={route('daily-activities.show', log.id)} className="block px-3 py-2.5 active:bg-gray-50 dark:active:bg-slate-800/40">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium text-gray-900 dark:text-slate-100">{log.employee?.full_name}</p>
                                    <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                        {[named(log.organization_unit, locale), named(log.position, locale)].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                                <LogStatusBadge status={log.status} />
                            </div>
                            <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-600 dark:text-slate-400">
                                <LocalizedDateDisplay value={log.activity_date} />
                                <span>· {log.items_count} {t('dailyActivities.summary.items')}</span>
                                {log.is_late && <LateMark />}
                            </p>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
