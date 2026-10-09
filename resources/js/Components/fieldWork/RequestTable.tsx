import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FieldWorkStatusBadge, FlagBadge } from './Badges';
import { destinationName, employeeName, named, panelCls } from './helpers';
import type { FieldWorkSummary } from './types';

/**
 * Field work requests as a dense table on wide screens and stacked rows on
 * phones (no horizontal scroll at 320px). `href` decides where a row opens:
 * My Portal or the management detail page.
 */
export default function RequestTable({ rows, emptyText, href, showEmployee = true }: {
    rows: FieldWorkSummary[];
    emptyText: string;
    href: (row: FieldWorkSummary) => string;
    showEmployee?: boolean;
}) {
    const { t, locale } = useLocale();

    if (rows.length === 0) {
        return <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{emptyText}</p>;
    }

    const th = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-slate-400';
    const td = 'px-3 py-2 align-top text-sm text-gray-800 dark:text-slate-200';
    const flags = (row: FieldWorkSummary) => (
        <>
            {row.monitoring_flag && <FlagBadge flag={row.monitoring_flag} />}
            {row.status === 'pending_supervisor_approval' && row.supervisor_resolution === 'supervisor_not_resolved' && <FlagBadge flag="supervisor_not_resolved" />}
        </>
    );

    return (
        <div className={panelCls}>
            <table className="hidden w-full md:table">
                <thead className="border-b border-gray-200 dark:border-slate-800">
                    <tr>
                        <th className={th}>{t('fieldWork.columns.reference')}</th>
                        {showEmployee && <th className={th}>{t('fieldWork.columns.employee')}</th>}
                        <th className={th}>{t('fieldWork.columns.type')}</th>
                        <th className={th}>{t('fieldWork.columns.destination')}</th>
                        <th className={th}>{t('fieldWork.columns.start')}</th>
                        <th className={th}>{t('fieldWork.columns.expectedReturn')}</th>
                        <th className={th}>{t('fieldWork.columns.status')}</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                    {rows.map((row) => (
                        <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                            <td className={td}>
                                <Link href={href(row)} className="font-medium text-[color:var(--color-primary)] hover:underline">{row.reference_number}</Link>
                                {row.is_team && <span className="block text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.columns.participants')}: {row.participants_count ?? '—'}</span>}
                            </td>
                            {showEmployee && (
                                <td className={td}>
                                    <span className="font-medium">{employeeName(row.requester, locale)}</span>
                                    <span className="block text-xs text-gray-500 dark:text-slate-400">{[row.requester?.employee_number, named(row.organization_unit ?? undefined, locale)].filter(Boolean).join(' · ')}</span>
                                </td>
                            )}
                            <td className={td}>{named(row.type ?? undefined, locale) || '—'}</td>
                            <td className={td}>
                                {destinationName(row, locale)}
                                <span className="block text-xs text-gray-500 dark:text-slate-400">{t(`fieldWork.destinationTypes.${row.destination_type}`)}</span>
                            </td>
                            <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={row.starts_at} withTime /></td>
                            <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={row.expected_return_at} withTime /></td>
                            <td className={td}>
                                <span className="flex flex-wrap gap-1"><FieldWorkStatusBadge status={row.status} />{flags(row)}</span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <ul className="divide-y divide-gray-100 md:hidden dark:divide-slate-800">
                {rows.map((row) => (
                    <li key={row.id}>
                        <Link href={href(row)} className="block px-3 py-2.5 active:bg-gray-50 dark:active:bg-slate-800/40">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium text-gray-900 dark:text-slate-100">{row.reference_number}</p>
                                    <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                        {[showEmployee ? employeeName(row.requester, locale) : null, named(row.type ?? undefined, locale), destinationName(row, locale)].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                                <FieldWorkStatusBadge status={row.status} />
                            </div>
                            <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-slate-400">
                                <LocalizedDateDisplay value={row.starts_at} withTime />
                                <span>→</span>
                                <LocalizedDateDisplay value={row.expected_return_at} withTime />
                                {flags(row)}
                            </p>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
