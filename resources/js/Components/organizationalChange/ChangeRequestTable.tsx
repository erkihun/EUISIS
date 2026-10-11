import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight } from '@/Components/Icons';
import type { JSX } from 'react';
import type { ChangeRequestSummary, ChangeRequestListMeta } from './types';

import { ListPagination, actionLinkClassName, cx, tableBodyClassName, tableClassName, tableHeadClassName, tableRowClassName } from '@/Components/ui';
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
            <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-8">
                <EmptyState title={emptyMessage} />
            </section>
        );
    }

    return (
        <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            <div className="overflow-x-auto">
                <table className={tableClassName}>
                    <thead className={cx(tableHeadClassName, 'text-left uppercase')}>
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
                    <tbody className={tableBodyClassName}>
                        {requests.map(request => (
                            <tr key={request.id} className={tableRowClassName}>
                                <td className={cellCls}>
                                    <Link
                                        href={route('organizational-change-requests.show', request.id)}
                                        className={actionLinkClassName({ size: 'sm', className: 'font-mono' })}
                                    >
                                        {request.request_no}
                                    </Link>
                                </td>
                                <td className={`${cellCls} text-[color:var(--app-foreground)]`}>
                                    {t(`organizationalChangeRequests.types.${request.request_type}`)}
                                </td>
                                <td className={`${cellCls} text-[color:var(--app-foreground)]`}>
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
                                <td className={`${cellCls} text-[color:var(--app-muted-foreground)]`}>
                                    {request.requested_effective_date
                                        ? <LocalizedDateDisplay value={request.requested_effective_date} />
                                        : '—'}
                                </td>
                                {showAssignee && (
                                    <td className={`${cellCls} text-[color:var(--app-muted-foreground)]`}>
                                        {request.implementation_assignee?.name ?? '—'}
                                    </td>
                                )}
                                <td className={`${cellCls} text-right`}>
                                    <Link
                                        href={route('organizational-change-requests.show', request.id)}
                                        aria-label={`${t('common.view')} ${request.request_no}`}
                                        className={actionLinkClassName({ className: 'inline-flex items-center gap-1' })}
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

            <ListPagination meta={meta} onPageChange={goToPage} />
        </section>
    );
}
