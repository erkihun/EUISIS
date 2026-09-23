import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight } from '@/Components/Icons';
import type { JSX } from 'react';
import type { ChangeRequestSummary, ChangeRequestListMeta } from './types';

type Props = {
    requests: ChangeRequestSummary[];
    meta: ChangeRequestListMeta;
    emptyMessage: string;
    routeName: string;
    filters: Record<string, string>;
    /** Implementation queue shows who the work is assigned to. */
    showAssignee?: boolean;
};

const cellCls = 'px-4 py-3 align-middle';

/**
 * Compact request table shared by all four queues.
 *
 * One row per request, no nested cards: the queues are working lists, so the
 * columns stay narrow and the detail lives on the request page.
 */
export default function ChangeRequestTable({
    requests,
    meta,
    emptyMessage,
    routeName,
    filters,
    showAssignee = false,
}: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';

    function goToPage(page: number) {
        router.get(route(routeName), { ...filters, page }, { preserveState: true, preserveScroll: true });
    }

    if (requests.length === 0) {
        return (
            <section className="rounded-panel border border-gray-200 bg-white p-8 dark:border-slate-800 dark:bg-slate-900">
                <EmptyState title={emptyMessage} />
            </section>
        );
    }

    return (
        <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                        <tr>
                            <th className={cellCls}>{t('organizationalChangeRequests.fields.requestNo')}</th>
                            <th className={cellCls}>{t('organizationalChangeRequests.fields.requestType')}</th>
                            <th className={cellCls}>{t('organizationalChangeRequests.fields.organization')}</th>
                            <th className={cellCls}>{t('organizationalChangeRequests.fields.status')}</th>
                            <th className={cellCls}>{t('organizationalChangeRequests.fields.effectiveDate')}</th>
                            {showAssignee && <th className={cellCls}>{t('organizationalChangeRequests.fields.assignedTo')}</th>}
                            <th className={`${cellCls} text-right`}>{t('common.view')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {requests.map(request => (
                            <tr key={request.id} className="transition hover:bg-gray-50/60 dark:hover:bg-slate-800/20">
                                <td className={cellCls}>
                                    <Link
                                        href={route('organizational-change-requests.show', request.id)}
                                        className="font-mono text-sm font-semibold text-[color:var(--color-primary)] hover:underline"
                                    >
                                        {request.request_no}
                                    </Link>
                                </td>
                                <td className={`${cellCls} text-gray-700 dark:text-slate-300`}>
                                    {t(`organizationalChangeRequests.types.${request.request_type}`)}
                                </td>
                                <td className={`${cellCls} text-gray-700 dark:text-slate-300`}>
                                    {request.organization
                                        ? (am ? request.organization.name_am || request.organization.name_en : request.organization.name_en)
                                        : '—'}
                                </td>
                                <td className={cellCls}>
                                    <StatusBadge
                                        status={request.status}
                                        label={t(`organizationalChangeRequests.statuses.${request.status}`)}
                                    />
                                </td>
                                <td className={`${cellCls} text-gray-600 dark:text-slate-400`}>
                                    {request.requested_effective_date
                                        ? <LocalizedDateDisplay value={request.requested_effective_date} />
                                        : '—'}
                                </td>
                                {showAssignee && (
                                    <td className={`${cellCls} text-gray-600 dark:text-slate-400`}>
                                        {request.implementation_assignee?.name ?? '—'}
                                    </td>
                                )}
                                <td className={`${cellCls} text-right`}>
                                    <Link
                                        href={route('organizational-change-requests.show', request.id)}
                                        aria-label={`${t('common.view')} ${request.request_no}`}
                                        className="inline-flex items-center gap-1 text-xs font-semibold text-[color:var(--color-primary)] hover:underline"
                                    >
                                        {t('common.view')}
                                        <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950/40">
                <p className="text-gray-500 dark:text-slate-400">
                    {meta.current_page} / {meta.last_page} · {meta.total}
                </p>
                <nav className="flex gap-2">
                    <button
                        type="button"
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        disabled={meta.current_page <= 1}
                        onClick={() => goToPage(meta.current_page - 1)}
                    >
                        {t('common.previous')}
                    </button>
                    <button
                        type="button"
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        disabled={meta.current_page >= meta.last_page}
                        onClick={() => goToPage(meta.current_page + 1)}
                    >
                        {t('common.next')}
                    </button>
                </nav>
            </div>
        </section>
    );
}
