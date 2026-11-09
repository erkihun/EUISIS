import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    FilterField, FilterPanel, ListPagination, Select, actionLinkClassName, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionRequestsIndexProps } from '@/court/types/inspections';
import { InspectionTabs, REQUEST_STATUS_TONES, caseLabel } from './inspectionUi';

/** Every inspection request the viewer may see, newest first, filtered by case and status. */
export default function InspectionRequestsIndex({ requests, cases, filters, statuses, nav }: InspectionRequestsIndexProps) {
    const { t, tx, text } = useInspectionPagesText();
    const [caseId, setCaseId] = useState(filters.case_id);
    const [status, setStatus] = useState(filters.status);
    const title = t('cm.case_inspections.requests.index_title');
    const noData = t('cm.case_inspections.common.no_data');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        if (caseId) params.case_id = caseId;
        if (status) params.status = status;
        router.get(route('court.case-inspection-requests.index'), params, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.case_inspections.requests.index_description')}
                actions={<Link href={route('court.case-inspection-requests.create')} className={buttonClassName({})}>{t('cm.case_inspections.requests.new')}</Link>} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <InspectionTabs active="requests" canRequests={nav.requests} canFindings={nav.findings} />

                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.case-inspection-requests.index'))}
                    hasActiveFilters={Boolean(filters.case_id || filters.status)} label={title}
                    submitLabel={t('cm.case_inspections.common.filter')} resetLabel={text('reset')}>
                    <FilterField label={t('cm.case_inspections.requests.table_case')} htmlFor="filter-case">
                        <Select id="filter-case" value={caseId} onChange={(event) => setCaseId(event.target.value)}>
                            <option value="">{t('cm.case_inspections.requests.all_cases')}</option>
                            {cases.map((item) => <option key={item.id} value={String(item.id)}>{caseLabel(item.case_number, item.title)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.case_inspections.requests.table_status')} htmlFor="filter-status">
                        <Select id="filter-status" value={status} onChange={(event) => setStatus(event.target.value)}>
                            <option value="">{t('cm.case_inspections.requests.all_statuses')}</option>
                            {statuses.map((item) => <option key={item} value={item}>{tx(`cm.case_inspections.status.${item}`, item)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.case_inspections.requests.table_case'), t('cm.case_inspections.requests.table_subject'), t('cm.case_inspections.requests.table_date'),
                                        t('cm.case_inspections.requests.table_status'), t('cm.case_inspections.requests.table_inspector'), t('cm.case_inspections.requests.table_created_by'),
                                    ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>)}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.case_inspections.common.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {requests.data.map((request) => (
                                    <tr key={request.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                            {request.case_id
                                                ? <Link href={route('court.cases.show', request.case_id)} className="font-mono font-semibold text-primary hover:underline">{request.case_number ?? noData}</Link>
                                                : (request.case_number ?? noData)}
                                        </td>
                                        <td className={tableCellClassName}>{request.subject}</td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap')}><LocalizedDateDisplay value={request.request_date} /></td>
                                        <td className={tableCellClassName}>
                                            <span className={badgeClassName(REQUEST_STATUS_TONES[request.status] ?? 'neutral')}>{tx(`cm.case_inspections.status.${request.status}`, request.status)}</span>
                                        </td>
                                        <td className={tableCellClassName}>{request.assigned_inspector_name ?? noData}</td>
                                        <td className={tableCellClassName}>{request.created_by_name ?? noData}</td>
                                        <td className={cx(tableCellClassName, 'space-x-2 text-right whitespace-nowrap')}>
                                            {request.status !== 'completed' && (
                                                <Link href={route('court.case-inspection-requests.edit', request.id)} className={actionLinkClassName({ tone: 'neutral' })}>{t('cm.case_inspections.common.edit')}</Link>
                                            )}
                                            <Link href={route('court.case-inspection-requests.show', request.id)} className={actionLinkClassName({})}>{t('cm.case_inspections.common.view')}</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {requests.data.length === 0 && <EmptyState title={t('cm.case_inspections.requests.empty')} />}
                    <ListPagination meta={requests} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
